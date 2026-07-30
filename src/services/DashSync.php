<?php

namespace lameco\dash\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\Assets;
use craft\helpers\Image;
use craft\models\Volume;
use lameco\dash\errors\DashApiException;
use lameco\dash\fs\DashFs;
use lameco\dash\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Keeps the `dash` volume in step with the Dash DAM.
 *
 * Craft's AssetIndexer is deliberately never run against this volume. It matches on
 * filename + folderId, so a move or rename in Dash reads as "one file missing, one file
 * new" and orphans every relation pointing at the asset. It also learns width and height
 * by reading the file, and Image::imageSizeByStream() parses JPEG/GIF/PNG headers only —
 * everything else falls through to a full download. Measured over 8 assets: 18.2 MB via
 * the indexer against 11.2 KB when elements are built from API data instead.
 *
 * So this service owns the whole lifecycle — create, move, retitle, alt, invalidate
 * transforms, detect deletions — reconciling on the Dash asset UUID rather than on path.
 */
class DashSync extends Component
{
    public const VOLUME_HANDLE = 'dash';

    /** @see migrations/m260729_215739_create_dash_tables.php */
    private const MAP_TABLE = '{{%dash_asset_map}}';
    private const STATE_TABLE = '{{%dash_sync_state}}';

    private const UNFILED = 'Unfiled';
    private const MUTEX_NAME = 'lameco:dashSync';

    /** How many assets are hydrated at once, so a 5000-asset library stays bounded. */
    private const CHUNK_SIZE = 100;

    /** Below this many missing assets the share is ignored, so small libraries still work. */
    private const ORPHAN_ABORT_FLOOR = 5;

    /**
     * Candidate names for the Dash field holding alt text, most specific first. Dash
     * ships no such field, so each account has to create one.
     */
    private const ALT_FIELD_NAMES = ['Alt text', 'Alt Text (Accessibility)', 'Alternative text', 'Alt', 'AltTextAccessibility'];

    /**
     * Dash `currentAssetFile.fileType` values this sync has actually been proven to
     * handle correctly end-to-end — element creation, dimensions, and serving real bytes
     * through DashFs::read(). IMAGE was the original spike. VIDEO was verified afterwards
     * by uploading a real file and comparing checksums at every layer (the API's reported
     * checksum, DashApi::fetch(), and DashFs::getFileStream()) — all matched, so `previewUrl`
     * turned out to serve the original, not the animated preview Dash's own docs describe.
     *
     * That was checked on a 19 KB clip; a HEAD request against two real multi-MB JPEGs
     * confirmed the same previewUrl-matches-declared-size pattern without downloading
     * either body, but no large video has been checked the same way. Widen this once one
     * has been.
     *
     * Dash also handles Audio, Document, Font and a generic Other bucket. None of those
     * have been tested here, so they are skipped rather than assumed to work the same way.
     */
    private const SUPPORTED_FILE_TYPES = ['IMAGE', 'VIDEO'];

    /**
     * How long the cheap probe is trusted before a full pass is run regardless.
     *
     * This is the only detector that can see a file replacement. Replacing a file sets
     * the asset's `dateLastModified` to the replacement file's `dateAdded` — measured
     * identical to the millisecond — so the stamp lands whenever that file was first
     * uploaded, which is in the past and never enters the `[watermark TO *]` window. The
     * count probe is no help either, since a replacement adds no asset. Spotting one
     * needs per-asset checksums, which is the full search anyway; that search transfers
     * no file bytes, so keeping this interval short is cheap.
     *
     * Ordinary edits (moves, title changes) do get a current stamp and are caught by the
     * timestamp probe within one cron tick.
     *
     * Override per environment with DASH_FULL_RECONCILE_MINUTES.
     */
    public int $fullReconcileMinutes = 30;

    /**
     * Whether an asset deleted in Dash may be moved to Craft's trash. Never applied to one
     * that is still related to something; set false to only ever report.
     */
    public bool $trashOrphans = true;

    /**
     * The share of mapped assets that may vanish from Dash in one run before the whole
     * reconcile is refused. Override with DASH_MAX_ORPHAN_SHARE, or 1.0 to disable.
     */
    public float $maxOrphanShare = 0.1;

    /** @var callable|null called with each progress line */
    public $logger = null;

    public function init(): void
    {
        parent::init();

        if (($minutes = (int)App::env('DASH_FULL_RECONCILE_MINUTES')) > 0) {
            $this->fullReconcileMinutes = $minutes;
        }

        if (($share = (float)App::env('DASH_MAX_ORPHAN_SHARE')) > 0) {
            $this->maxOrphanShare = $share;
        }
    }

    /**
     * @return array{now: string, watermark: string|null, modified: int|null, remoteTotal: int, mappedTotal: int, countMismatch: bool, stale: bool, watermarkAgeMinutes: float|null, changed: bool}
     */
    public function probe(): array
    {
        // Taken before the probe, so changes made while a sync runs are picked up by the
        // next run rather than skipped.
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $watermark = $this->state('lastSync');

        $modified = $watermark === null ? null : $this->api()->countAssets([
            'type' => 'FIELD_MATCHES',
            'value' => "[$watermark TO *]",
            'field' => ['type' => 'FIXED', 'fieldName' => 'DATE_LAST_MODIFIED'],
        ]);

        // DATE_LAST_MODIFIED structurally cannot surface a deletion — a removed asset
        // simply stops matching any search. Comparing totals is the only cheap way to
        // notice one.
        $remoteTotal = $this->api()->countAssets(['type' => 'MATCH_ALL']);
        $mappedTotal = (int)Craft::$app->getDb()
            ->createCommand('SELECT COUNT(*) FROM ' . self::MAP_TABLE)
            ->queryScalar();

        // Every reconcile rescans the whole library and compares checksums, so the
        // watermark doubles as "when everything was last verified".
        $ageMinutes = $watermark === null ? null : (time() - strtotime($watermark)) / 60;
        $stale = $ageMinutes !== null && $ageMinutes >= $this->fullReconcileMinutes;

        return [
            'now' => $now,
            'watermark' => $watermark,
            'modified' => $modified,
            'remoteTotal' => $remoteTotal,
            'mappedTotal' => $mappedTotal,
            'countMismatch' => $remoteTotal !== $mappedTotal,
            'stale' => $stale,
            'watermarkAgeMinutes' => $ageMinutes,
            'changed' => $watermark === null || $modified > 0 || $remoteTotal !== $mappedTotal || $stale,
        ];
    }

    /**
     * A reconcile over a full library takes minutes, and the probe is cheap enough to run
     * every few minutes, so overlapping cron runs are the normal case rather than the
     * edge one. Without the lock they race on element saves and transform deletion. The
     * watermark advance belongs inside it too, or a second run can start against a window
     * the first has already reconciled but not yet recorded.
     *
     * @return array<string, int>|null counts, or null if another run holds the lock
     */
    public function reconcileAndAdvance(string $timestamp): ?array
    {
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::MUTEX_NAME)) {
            return null;
        }

        try {
            $counts = $this->reconcile();
            // Only after a clean run, so a failure retries the same window.
            $this->setState('lastSync', $timestamp);

            return $counts;
        } finally {
            $mutex->release(self::MUTEX_NAME);
        }
    }

    /**
     * @return array<string, int> counts per kind of change
     */
    public function reconcile(): array
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle(self::VOLUME_HANDLE);

        if ($volume === null) {
            throw new DashApiException("No volume with handle '" . self::VOLUME_HANDLE . "'.");
        }

        $db = Craft::$app->getDb();
        ['assets' => $dash, 'altSyncable' => $altSyncable, 'skippedByType' => $skippedByType] = $this->dashState();
        $this->log(count($dash) . ' assets in Dash');

        foreach ($skippedByType as $fileType => $skipCount) {
            $this->log("  note: {$skipCount} {$fileType} asset(s) skipped — unverified file type, not synced");
        }

        $mapped = $db->createCommand('SELECT assetId, dashId, checksum FROM ' . self::MAP_TABLE)->queryAll();
        $byAssetId = array_column($mapped, 'dashId', 'assetId');
        $knownChecksum = array_column($mapped, 'checksum', 'assetId');

        $this->refuseMassDisappearance($byAssetId, $dash);

        $counts = ['adopted' => 0, 'unmatched' => 0, 'created' => 0, 'moved' => 0, 'retitled' => 0,
            'altSynced' => 0, 'resized' => 0, 'restamped' => 0, 'trashed' => 0, 'inUse' => 0, 'failed' => 0,
            'skippedUnsupported' => array_sum($skippedByType)];

        $this->adoptUnmapped($volume, $dash, $byAssetId, $counts);
        $this->syncMapped($volume, $dash, $byAssetId, $knownChecksum, $altSyncable, $counts);
        $this->createMissing($volume, $dash, $byAssetId, $altSyncable, $counts);

        return $counts;
    }

    /**
     * The Dash-side view of the library: dashId => path, title, alt, checksum, size, dimensions.
     *
     * `altSyncable` distinguishes "Dash has no alt field" from "the field exists but is
     * empty" — only the second may clear Craft's value. Without it, running a sync before
     * the field is configured would wipe every alt text on the site.
     *
     * @return array{assets: array<string, array>, altSyncable: bool, skippedByType: array<string, int>}
     */
    private function dashState(): array
    {
        $api = $this->api();
        $folderFieldId = $api->folderFieldId();

        // Dash is the system of record for both. "Rename" in Dash edits Title, never the
        // AssetFile filename. Alt text has no built-in field, so it is matched by name
        // against whatever the account configured, ideally mapped to the IPTC property
        // Iptc4xmpCore:AltTextAccessibility.
        $fields = $api->fields();

        $findField = static function(array $names) use ($fields): ?string {
            foreach ($names as $name) {
                if (isset($fields[strtolower($name)])) {
                    return $fields[strtolower($name)];
                }
            }

            return null;
        };

        $titleFieldId = $findField(['Title']);
        $altFieldId = $findField(self::ALT_FIELD_NAMES);

        if ($altFieldId === null) {
            $this->log(sprintf(
                '  note: no alt-text field found in Dash (looked for: %s) — alt sync skipped',
                implode(', ', self::ALT_FIELD_NAMES),
            ));
        }

        $folderPaths = $api->folderPaths($folderFieldId);
        $assets = [];
        $skippedByType = [];

        foreach ($api->allAssets() as $asset) {
            $file = $asset['currentAssetFile'] ?? null;

            if ($file === null || empty($file['filename'])) {
                continue;
            }

            $fileType = $file['fileType'] ?? 'UNKNOWN';

            if (!in_array($fileType, self::SUPPORTED_FILE_TYPES, true)) {
                $skippedByType[$fileType] = ($skippedByType[$fileType] ?? 0) + 1;
                continue;
            }

            $folders = array_values(array_filter(array_map(
                static fn(string $id) => $folderPaths[$id] ?? null,
                $asset['metadata']['values'][$folderFieldId] ?? [],
            )));

            $assets[$asset['id']] = [
                // Must match DashFs exactly, or the two disagree on every path.
                'path' => ($folders[0] ?? self::UNFILED) . '/'
                    . DashFs::craftFilename($file['filename'], $asset['id']),
                'title' => $titleFieldId !== null ? ($asset['metadata']['values'][$titleFieldId][0] ?? null) : null,
                'alt' => $altFieldId !== null ? ($asset['metadata']['values'][$altFieldId][0] ?? null) : null,
                'checksum' => $file['checksum'] ?? null,
                // Carried so elements can be created without Craft probing the file —
                // this is what keeps a cold sync from downloading the entire library.
                'size' => (int)($file['size'] ?? 0),
                'width' => $file['dimensions']['width'] ?? null,
                'height' => $file['dimensions']['height'] ?? null,
            ];
        }

        return ['assets' => $assets, 'altSyncable' => $altFieldId !== null, 'skippedByType' => $skippedByType];
    }

    /**
     * Adopt any Craft asset in the volume that has no mapping yet: exact path first, then
     * filename among the leftovers. An asset whose path drifted before the mapping
     * existed cannot be matched on path, and on a real install that is the common case.
     */
    private function adoptUnmapped(Volume $volume, array $dash, array &$byAssetId, array &$counts): void
    {
        // IDs only. Hydrating every element in the volume to find the unmapped few is
        // what breaks at library scale, and on a healthy install none are unmapped.
        $unmappedIds = array_values(array_diff(
            Asset::find()->volumeId($volume->id)->status(null)->ids(),
            array_keys($byAssetId),
        ));

        if ($unmappedIds === []) {
            return;
        }

        $db = Craft::$app->getDb();
        $claimed = array_flip($byAssetId);
        $available = array_diff_key($dash, $claimed);
        $byPath = array_flip(array_map(static fn(array $state) => $state['path'], $available));
        $byFilename = [];

        foreach ($available as $dashId => $state) {
            $byFilename[basename($state['path'])][] = $dashId;
        }

        foreach (array_chunk($unmappedIds, self::CHUNK_SIZE) as $ids) {
            foreach (Asset::find()->id($ids)->status(null)->all() as $asset) {
                $dashId = $byPath[$asset->getPath()] ?? null;
                $how = 'path';

                if ($dashId === null) {
                    $candidates = $byFilename[$asset->getFilename()] ?? [];

                    // Only trust a filename match when it is unambiguous.
                    if (count($candidates) === 1) {
                        $dashId = $candidates[0];
                        $how = 'filename (path had drifted)';
                    }
                }

                if ($dashId === null || isset($claimed[$dashId])) {
                    $this->log("  UNMATCHED #{$asset->id}  {$asset->getPath()}");
                    $counts['unmatched']++;
                    continue;
                }

                $db->createCommand()->insert(self::MAP_TABLE, ['assetId' => $asset->id, 'dashId' => $dashId])->execute();
                $byAssetId[$asset->id] = $dashId;
                $claimed[$dashId] = $asset->id;
                $this->log("  ADOPTED  #{$asset->id}  {$asset->getPath()}  (matched by $how)");
                $counts['adopted']++;
            }
        }

        $this->log("Seeded {$counts['adopted']} mapping(s)");
    }

    private function syncMapped(
        Volume $volume,
        array $dash,
        array $byAssetId,
        array $knownChecksum,
        bool $altSyncable,
        array &$counts,
    ): void {
        $db = Craft::$app->getDb();
        $orphanIds = [];

        foreach (array_chunk($byAssetId, self::CHUNK_SIZE, true) as $chunk) {
            // One query per chunk rather than one per asset. status(null) matches what
            // Elements::getElementById() does, so nothing is filtered out that used to
            // be found.
            $assets = Asset::find()->id(array_keys($chunk))->status(null)->indexBy('id')->all();

            foreach ($chunk as $assetId => $dashId) {
                $asset = $assets[$assetId] ?? null;

                if ($asset === null) {
                    $db->createCommand()->delete(self::MAP_TABLE, ['assetId' => $assetId])->execute();
                    continue;
                }

                if (!isset($dash[$dashId])) {
                    // Genuinely removed from Dash — this, and only this, is a real orphan.
                    // Collected rather than handled here so the in-use test is one query
                    // for all of them instead of one each.
                    $orphanIds[] = $assetId;
                    continue;
                }

                $state = $dash[$dashId];
                $current = $asset->getPath();
                $pathChanged = $state['path'] !== $current;
                $titleChanged = $state['title'] !== null && $state['title'] !== $asset->title;

                // Size and dimensions are re-synced on every run, not only at creation: a
                // same-name replacement changes the bytes without changing the path, and
                // since the AssetIndexer no longer runs, nothing else would notice. Width and
                // height are left alone when Dash reports none (SVG), so the value measured
                // at creation survives.
                $sizeChanged = $state['size'] > 0 && (int)$asset->size !== $state['size'];
                $dimsChanged = $state['width'] !== null
                    && ((int)$asset->width !== (int)$state['width'] || (int)$asset->height !== (int)$state['height']);
                // Dash leads: an empty value there clears Craft's rather than leaving a stale one.
                $altChanged = $altSyncable && ($state['alt'] ?? '') !== ($asset->alt ?? '');

                // craftcms/cms#19328: Craft re-downloads a cached remote original only when it
                // is missing or zero bytes, and never invalidates derivatives when the source
                // changes. A same-name version replacement leaves the path identical, so
                // nothing else here would notice. The Dash checksum is the only signal.
                $checksum = $state['checksum'];
                $contentChanged = $checksum !== null && ($knownChecksum[$assetId] ?? null) !== null
                    && $checksum !== $knownChecksum[$assetId];

                if ($contentChanged) {
                    Craft::$app->getImageTransforms()->deleteAllTransformData($asset);
                    $this->log("  RESTAMPED #{$assetId}  content changed — transforms invalidated");
                    $counts['restamped']++;
                }

                if ($checksum !== ($knownChecksum[$assetId] ?? null)) {
                    $db->createCommand()->update(self::MAP_TABLE, ['checksum' => $checksum], ['assetId' => $assetId])->execute();
                }

                if (!$pathChanged && !$titleChanged && !$altChanged && !$sizeChanged && !$dimsChanged) {
                    continue;
                }

                if ($pathChanged) {
                    $folder = Craft::$app->getAssets()
                        ->ensureFolderByFullPathAndVolume($this->folderPathOf($state['path']), $volume);

                    // Set folderId/filename directly rather than newFolderId/newFilename: the
                    // latter build a `newLocation`, which makes Asset::afterSave() call
                    // renameFile() on a filesystem that refuses writes.
                    $asset->folderId = $folder->id;
                    $asset->folderPath = $folder->path;
                    $asset->setFilename(basename($state['path']));
                }

                if ($titleChanged) {
                    $asset->title = $state['title'];
                }

                if ($altChanged) {
                    // Empty string, never null: `assets_sites.alt` only overrides the legacy
                    // `assets.alt` column when non-null, and afterSave() won't rewrite that
                    // column once set — so null leaves the old text visible forever.
                    $asset->alt = (string)($state['alt'] ?? '');
                }

                if ($sizeChanged) {
                    $asset->size = $state['size'];
                }

                if ($dimsChanged) {
                    $asset->setWidth((int)$state['width']);
                    $asset->setHeight((int)$state['height']);
                }

                $asset->setScenario(Asset::SCENARIO_INDEX);

                // Propagating, unlike the creation path: alt and title are per-site rows, and
                // saving only one leaves the others falling back to the stale legacy column.
                if (!Craft::$app->getElements()->saveElement($asset, false, true, false)) {
                    $this->log("  FAILED   #{$assetId}  " . json_encode($asset->getErrors()));
                    $counts['failed']++;
                    continue;
                }

                if ($pathChanged) {
                    $this->log("  MOVED    #{$assetId}  {$current}\n              -> {$state['path']}");
                    $counts['moved']++;
                }

                if ($titleChanged) {
                    $this->log("  RETITLED #{$assetId}  -> \"{$state['title']}\"");
                    $counts['retitled']++;
                }

                if ($altChanged) {
                    $this->log("  ALT      #{$assetId}  -> "
                        . ($state['alt'] === null || $state['alt'] === '' ? '(cleared)' : "\"{$state['alt']}\""));
                    $counts['altSynced']++;
                }

                if ($sizeChanged || $dimsChanged) {
                    $this->log(sprintf('  RESIZED  #%s  -> %sx%s, %d bytes',
                        $assetId, $asset->width ?: '-', $asset->height ?: '-', $asset->size));
                    $counts['resized']++;
                }
            }
        }

        if ($orphanIds !== []) {
            $this->handleOrphans($orphanIds, $counts);
        }
    }

    /**
     * An asset deleted in Dash is a broken asset in Craft: the file is gone, so any
     * transform generated from it fails. Trashing it is only safe when nothing relates to
     * it — for one still in use, deleting it breaks a live page and keeping it silently
     * serves a 404, so neither is done quietly and it is reported instead.
     *
     * The in-use test covers relations, which is how Assets fields store their references.
     * An asset referenced only from inside rich text as a `{asset:123:url}` ref tag will
     * not be seen.
     */
    /**
     * A folder unshared, a group permission narrowed, a partial API response — from here
     * all three are indistinguishable from a bulk deletion, because the assets simply stop
     * coming back from the search and every unreferenced one would be trashed. Losing a
     * large share at once is therefore treated as a broken read rather than as intent.
     */
    private function refuseMassDisappearance(array $byAssetId, array $dash): void
    {
        $mapped = count($byAssetId);

        if ($mapped === 0) {
            return;
        }

        $missing = count(array_diff(array_values($byAssetId), array_keys($dash)));
        $share = $missing / $mapped;

        if ($missing <= self::ORPHAN_ABORT_FLOOR || $share <= $this->maxOrphanShare) {
            return;
        }

        throw new DashApiException(sprintf(
            "%d of %d mapped assets (%d%%) are missing from Dash, over the %d%% limit — refusing to reconcile.\n"
            . "Nothing has been changed. A narrowed permission or a partial API response looks exactly like this,\n"
            . 'so check that before assuming the deletions are real. Re-run with --allow-mass-deletion to proceed.',
            $missing,
            $mapped,
            (int)round($share * 100),
            (int)round($this->maxOrphanShare * 100),
        ));
    }

    private function handleOrphans(array $orphanIds, array &$counts): void
    {
        $db = Craft::$app->getDb();
        $relationCounts = $db->createCommand(
            'SELECT targetId, COUNT(*) AS uses FROM {{%relations}} WHERE targetId IN ('
            . implode(',', array_map('intval', $orphanIds)) . ') GROUP BY targetId',
        )->queryAll();
        $uses = array_column($relationCounts, 'uses', 'targetId');

        foreach (array_chunk($orphanIds, self::CHUNK_SIZE) as $ids) {
            foreach (Asset::find()->id($ids)->status(null)->all() as $asset) {
                $used = (int)($uses[$asset->id] ?? 0);

                if ($used > 0 || !$this->trashOrphans) {
                    $reason = $used > 0 ? "still used by {$used} element(s)" : 'trashOrphans is off';
                    Craft::error(
                        "Dash asset #{$asset->id} ({$asset->getPath()}) no longer exists in Dash but was kept: {$reason}.",
                        __METHOD__,
                    );
                    $this->log("  IN USE   #{$asset->id}  {$asset->getPath()}  — gone from Dash, {$reason}");
                    $counts['inUse']++;
                    continue;
                }

                if (!Craft::$app->getElements()->deleteElement($asset)) {
                    $this->log("  FAILED   #{$asset->id}  could not trash orphan");
                    $counts['failed']++;
                    continue;
                }

                $db->createCommand()->delete(self::MAP_TABLE, ['assetId' => $asset->id])->execute();
                $this->log("  TRASHED  #{$asset->id}  {$asset->getPath()}  — gone from Dash, unused");
                $counts['trashed']++;
            }
        }
    }

    /**
     * Craft's AssetIndexer would also create these, but only after reading each file to
     * learn its dimensions. Dash reports `dimensions` and `size` in the search response,
     * so creating the element here touches the filesystem zero times.
     */
    private function createMissing(Volume $volume, array $dash, array $byAssetId, bool $altSyncable, array &$counts): void
    {
        $db = Craft::$app->getDb();

        foreach (array_diff_key($dash, array_flip($byAssetId)) as $dashId => $state) {
            $folder = Craft::$app->getAssets()
                ->ensureFolderByFullPathAndVolume($this->folderPathOf($state['path']), $volume);

            $asset = new Asset();
            $asset->setVolumeId((int)$volume->id);
            $asset->folderId = $folder->id;
            $asset->folderPath = $folder->path;
            $asset->setFilename(basename($state['path']));
            $asset->kind = Assets::getFileKindByExtension($state['path']);
            $asset->size = $state['size'];
            $asset->title = $state['title'] ?: null;

            if ($state['width'] !== null) {
                $asset->setWidth((int)$state['width']);
                $asset->setHeight((int)$state['height']);
            } elseif ($asset->kind === Asset::KIND_IMAGE) {
                $this->measureFromFile($volume, $asset, $state['path']);
            }

            if ($altSyncable && ($state['alt'] ?? '') !== '') {
                $asset->alt = $state['alt'];
            }

            $asset->setScenario(Asset::SCENARIO_INDEX);

            if (!Craft::$app->getElements()->saveElement($asset, false, false, false)) {
                $this->log("  FAILED   new asset {$state['path']}  " . json_encode($asset->getErrors()));
                $counts['failed']++;
                continue;
            }

            $db->createCommand()->insert(self::MAP_TABLE, [
                'assetId' => $asset->id,
                'dashId' => $dashId,
                'checksum' => $state['checksum'],
            ])->execute();

            $this->log("  CREATED  #{$asset->id}  {$state['path']}"
                . ($state['width'] !== null ? "  ({$state['width']}x{$state['height']}, from API)" : ''));
            $counts['created']++;
        }
    }

    /**
     * Dash reports dimensions for rasters but not for SVG. Reading the file keeps
     * width/height on the <img> tag — without them getSrcset() returns false and the tag
     * loses its intrinsic size, which is a layout-shift problem. Bounded: it only fires
     * for formats Dash doesn't measure, which are typically small vector files.
     */
    private function measureFromFile(Volume $volume, Asset $asset, string $path): void
    {
        try {
            $temp = Assets::tempFilePath(pathinfo($path, PATHINFO_EXTENSION));
            file_put_contents($temp, $volume->getFs()->read($path));
            [$width, $height] = Image::imageSize($temp);
            $asset->setWidth((int)$width);
            $asset->setHeight((int)$height);
            @unlink($temp);
        } catch (Throwable $e) {
            Craft::warning("Could not measure {$path}: {$e->getMessage()}", __METHOD__);
        }
    }

    private function folderPathOf(string $path): string
    {
        return dirname($path) === '.' ? '' : dirname($path) . '/';
    }

    private function state(string $key): ?string
    {
        return Craft::$app->getDb()
            ->createCommand('SELECT v FROM ' . self::STATE_TABLE . ' WHERE k = :k', [':k' => $key])
            ->queryScalar() ?: null;
    }

    private function setState(string $key, string $value): void
    {
        Craft::$app->getDb()->createCommand()
            ->upsert(self::STATE_TABLE, ['k' => $key, 'v' => $value], ['v' => $value])
            ->execute();
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }

    private function api(): DashApi
    {
        return Plugin::getInstance()->getDashApi();
    }
}

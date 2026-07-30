<?php

namespace lameco\dash\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Db;
use craft\helpers\Image;
use craft\models\Volume;
use DateTime;
use lameco\dash\DashVolumes;
use lameco\dash\errors\DashApiException;
use lameco\dash\fs\DashFs;
use lameco\dash\helpers\CanonicalFolder;
use lameco\dash\helpers\ProbeDecision;
use lameco\dash\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Keeps the Dash volume in step with the Dash DAM.
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
    private const MAP_TABLE = '{{%dash_asset_map}}';
    private const STATE_TABLE = '{{%dash_sync_state}}';

    /**
     * Cache key for the control panel badge. Owned here rather than by the utility that
     * renders it, because what it caches is missingCount() — so anything that changes that
     * number can invalidate it without reaching into another class's constants.
     */
    public const BADGE_CACHE_KEY = 'dash.missingCount';

    private const MUTEX_NAME = 'lameco:dashSync';

    /** How many assets are hydrated at once, so a 5000-asset library stays bounded. */
    private const CHUNK_SIZE = 100;

    /** Below this many missing assets the share is ignored, so small libraries still work. */
    private const ORPHAN_ABORT_FLOOR = 5;

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
     */
    public int $fullReconcileMinutes = 30;

    /**
     * Whether an asset deleted in Dash may be moved to Craft's trash. Never applied to one
     * that is still related to something; set false to only ever report.
     */
    public bool $trashOrphans = true;

    /**
     * The share of mapped assets that may vanish from Dash in one run before the whole
     * reconcile is refused. 1.0 disables the check.
     */
    public float $maxOrphanShare = 0.1;

    /** @var callable|null called with each progress line */
    public $logger = null;

    /** @var array<int, string|null>|null assetId => Dash preview URL, loaded once per request */
    private ?array $previewUrls = null;

    /**
     * The declared values above are fallbacks; the settings are the source. Overriding one
     * for a single run still works, because callers do that after the component is built —
     * which is what the console command's --allowMassDeletion does.
     */
    public function init(): void
    {
        parent::init();

        $settings = Plugin::getInstance()->getSettings();

        $this->fullReconcileMinutes = $settings->fullReconcileMinutes;
        $this->trashOrphans = $settings->trashOrphans;
        $this->maxOrphanShare = $settings->maxOrphanShare;
    }

    /**
     * @return array{now: string, watermark: string|null, modified: int|null, remoteTotal: int, knownTotal: int|null, mappedTotal: int, countChanged: bool, stale: bool, watermarkAgeMinutes: float|null, changed: bool}
     */
    public function probe(): array
    {
        // The probe itself never touches the volume, but a missing or ambiguous one must
        // surface here rather than at reconcile time: cron runs probe-then-maybe-reconcile,
        // and a misconfigured install should say what to fix instead of reporting "no
        // changes" until the next full pass happens to run.
        DashVolumes::single();

        // Taken before the probe, so changes made while a sync runs are picked up by the
        // next run rather than skipped.
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $watermark = $this->state('lastSync');

        $modified = $watermark === null ? null : $this->api()->countAssets([
            'type' => 'FIELD_MATCHES',
            'value' => "[$watermark TO *]",
            'field' => ['type' => 'FIXED', 'fieldName' => 'DATE_LAST_MODIFIED'],
        ]);

        // DATE_LAST_MODIFIED structurally cannot surface a deletion — a removed asset simply
        // stops matching any search. Comparing totals is the only cheap way to notice one.
        //
        // Against the total this environment last saw, not against how many assets are
        // mapped. Those two are only equal when the whole library is synced, which stopped
        // being true once folders could be scoped and unsupported file types skipped: with
        // 376 assets in Dash and 282 mapped, a mapped-count comparison reports "changed" on
        // every run forever, which both defeats the cheap probe and destroys the one signal
        // that can see a deletion.
        $remoteTotal = $this->api()->countAssets(['type' => 'MATCH_ALL']);
        $knownTotal = $this->state('remoteTotal');
        $mappedTotal = (int)Craft::$app->getDb()
            ->createCommand('SELECT COUNT(*) FROM ' . self::MAP_TABLE)
            ->queryScalar();

        // Every reconcile rescans the whole library and compares checksums, so the
        // watermark doubles as "when everything was last verified".
        $decision = ProbeDecision::evaluate(
            $watermark,
            $modified,
            $remoteTotal,
            $knownTotal === null ? null : (int)$knownTotal,
            time(),
            $this->fullReconcileMinutes,
        );

        return [
            'now' => $now,
            'watermark' => $watermark,
            'modified' => $modified,
            'remoteTotal' => $remoteTotal,
            'knownTotal' => $knownTotal === null ? null : (int)$knownTotal,
            'mappedTotal' => $mappedTotal,
            ...$decision,
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
            // What the next probe compares against. Taken from the reconcile's own full walk
            // rather than from the probe's count, so the two can never disagree about which
            // library state was actually processed.
            $this->setState('remoteTotal', (string)$counts['remoteTotal']);

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
        $volume = DashVolumes::single();
        $db = Craft::$app->getDb();
        [
            'assets' => $dash,
            'allIds' => $allIds,
            'altSyncable' => $altSyncable,
            'skippedByType' => $skippedByType,
            'outOfScope' => $outOfScope,
        ] = $this->dashState();
        $this->log(count($dash) . ' assets in Dash');

        foreach ($skippedByType as $fileType => $skipCount) {
            $this->log("  note: {$skipCount} {$fileType} asset(s) skipped — unverified file type, not synced");
        }

        if ($outOfScope > 0) {
            $this->log("  note: {$outOfScope} asset(s) outside the selected folders — not synced");
        }

        $mapped = $db->createCommand('SELECT assetId, dashId, checksum, missingSince FROM ' . self::MAP_TABLE)->queryAll();
        $byAssetId = array_column($mapped, 'dashId', 'assetId');
        $knownChecksum = array_column($mapped, 'checksum', 'assetId');
        $wasMissing = array_column($mapped, 'missingSince', 'assetId');

        // Against everything Dash returned, not just the in-scope subset: narrowing the
        // folder selection is not a deletion and must not trip the abort.
        $this->refuseMassDisappearance($byAssetId, $allIds);

        $counts = ['adopted' => 0, 'unmatched' => 0, 'created' => 0, 'moved' => 0, 'retitled' => 0,
            'altSynced' => 0, 'resized' => 0, 'restamped' => 0, 'trashed' => 0, 'inUse' => 0, 'returned' => 0,
            'outOfScope' => 0, 'failed' => 0, 'skippedUnsupported' => array_sum($skippedByType), ];

        $this->adoptUnmapped($volume, $dash, $byAssetId, $counts);
        $this->syncMapped($volume, $dash, $allIds, $byAssetId, $knownChecksum, $wasMissing, $altSyncable, $counts);
        $this->createMissing($volume, $dash, $byAssetId, $altSyncable, $counts);

        // Last, so it works on assets that have finished moving and had stale derivatives
        // cleared. Reads the mapping table fresh — createMissing() has added rows since.
        $mappedIds = array_map('intval', $db->createCommand('SELECT assetId FROM ' . self::MAP_TABLE)->queryColumn());
        $transforms = $this->transforms()->ensureTransforms($mappedIds);
        $counts['transformed'] = $transforms['generated'];
        $counts['transformsDeferred'] = $transforms['deferred'];
        $counts['failed'] += $transforms['failed'];
        $counts['foldersPruned'] = $this->pruneEmptyFolders($volume, $dash);
        $counts['remoteTotal'] = count($allIds);

        return $counts;
    }

    /**
     * The Dash-side view of the library: dashId => path, title, alt, checksum, size, dimensions.
     *
     * `altSyncable` distinguishes "Dash has no alt field" from "the field exists but is
     * empty" — only the second may clear Craft's value. Without it, running a sync before
     * the field is configured would wipe every alt text on the site.
     *
     * `allIds` is every asset Dash returned, before the folder-scope and file-type filters.
     * It is what makes "no longer in Dash" mean deletion: without it, narrowing the folder
     * selection would read as a bulk deletion and trash live assets.
     *
     * @return array{assets: array<string, array>, allIds: array<string, true>, altSyncable: bool, skippedByType: array<string, int>, outOfScope: int}
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

        $settings = Plugin::getInstance()->getSettings();
        $altFieldNames = $settings->altFieldNameList();
        $titleFieldNames = $settings->titleFieldNameList();
        $titleFieldId = $findField($titleFieldNames);
        $altFieldId = $findField($altFieldNames);

        if ($titleFieldId === null) {
            $this->log(sprintf(
                '  note: no title field found in Dash (looked for: %s) — titles left as they are.'
                . ' Set the right name under Settings → Dash DAM.',
                implode(', ', $titleFieldNames),
            ));
        }

        if ($altFieldId === null) {
            $this->log(sprintf(
                '  note: no alt-text field found in Dash (looked for: %s) — alt sync skipped.'
                . ' Set the right name under Settings → Dash DAM.',
                implode(', ', $altFieldNames),
            ));
        }

        $folderPaths = $api->folderPaths($folderFieldId);
        $config = $this->config();
        $assets = [];
        $allIds = [];
        $skippedByType = [];
        $outOfScope = 0;

        foreach ($api->allAssets() as $asset) {
            // Recorded before every filter below: this set answers "does it still exist in
            // Dash", which is a different question from "should we be managing it".
            $allIds[$asset['id']] = true;

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

            // The pick prefers in-scope folders, so an asset is only out of scope when
            // none of its folders are selected — not when Dash happens to list an
            // unselected one first.
            $dirname = CanonicalFolder::pick($folders, $config->includesFolder(...));

            if (!$config->includesFolder($dirname)) {
                $outOfScope++;
                continue;
            }

            $assets[$asset['id']] = [
                // Must match DashFs exactly, or the two disagree on every path.
                'path' => $dirname . '/' . DashFs::craftFilename($file['filename'], $asset['id']),
                'title' => $titleFieldId !== null ? ($asset['metadata']['values'][$titleFieldId][0] ?? null) : null,
                'alt' => $altFieldId !== null ? ($asset['metadata']['values'][$altFieldId][0] ?? null) : null,
                'checksum' => $file['checksum'] ?? null,
                // Kept so the control panel can render a thumbnail without Craft generating
                // one from the original. CloudFront-signed and expiring, so it must never
                // reach rendered site HTML — Blitz caches statically and the URL would rot
                // mid-cache-lifetime. Control panel pages are per-request, which is the one
                // place it is safe.
                'previewUrl' => $file['previewUrl'] ?? null,
                // Carried so elements can be created without Craft probing the file —
                // this is what keeps a cold sync from downloading the entire library.
                'size' => (int)($file['size'] ?? 0),
                'width' => $file['dimensions']['width'] ?? null,
                'height' => $file['dimensions']['height'] ?? null,
            ];
        }

        return [
            'assets' => $assets,
            'allIds' => $allIds,
            'altSyncable' => $altFieldId !== null,
            'skippedByType' => $skippedByType,
            'outOfScope' => $outOfScope,
        ];
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
        array $allIds,
        array $byAssetId,
        array $knownChecksum,
        array $wasMissing,
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
                    // Still in Dash, just no longer inside the selected folders. Left exactly
                    // as it is — not moved, not retitled, and above all not trashed. Deleting
                    // an asset because someone narrowed the folder selection would take live
                    // images off the site.
                    if (isset($allIds[$dashId])) {
                        $counts['outOfScope']++;
                        continue;
                    }

                    // Genuinely removed from Dash — this, and only this, is a real orphan.
                    // Collected rather than handled here so the in-use test is one query
                    // for all of them instead of one each.
                    $orphanIds[] = $assetId;
                    continue;
                }

                // Restored in Dash, or pulled back out of its bin. Clearing the stamp is what
                // takes the asset off the control panel's broken list.
                if (($wasMissing[$assetId] ?? null) !== null) {
                    $db->createCommand()->update(self::MAP_TABLE, ['missingSince' => null], ['assetId' => $assetId])->execute();
                    $this->log("  RETURNED #{$assetId}  back in Dash");
                    $counts['returned']++;
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
                // Craft leads on alt text, and the sync only ever fills a gap.
                //
                // Dash ships no alt-text field, so Craft is where alt text is actually authored
                // — the volume's AltField is editable for exactly that reason. Overwriting it
                // from Dash is the open data-loss bug in the one mature plugin in this space
                // (imageshoporg/Craft#11: "I can't sync metadata at all without losing
                // locally-set data"), and it would be worse here: an empty Dash value would
                // wipe an editor's work on a healthcare site's accessibility text.
                //
                // So a value arriving from Dash seeds an empty Craft field and nothing more. If
                // an alt field is configured in Dash later, it fills the gaps without touching
                // anything a person wrote.
                $altChanged = $altSyncable
                    && ($state['alt'] ?? '') !== ''
                    && ($asset->alt ?? '') === '';

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

                // The preview URL is re-signed by Dash on every search, so it is refreshed
                // here rather than compared — the stored one is what the control panel reads,
                // and letting it expire would leave every thumbnail broken.
                $db->createCommand()->update(
                    self::MAP_TABLE,
                    ['checksum' => $checksum, 'previewUrl' => $state['previewUrl']],
                    ['assetId' => $assetId],
                )->execute();

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
                    $asset->alt = (string)$state['alt'];
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
                    $this->log("  ALT      #{$assetId}  filled from Dash -> \"{$state['alt']}\"");
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
    private function refuseMassDisappearance(array $byAssetId, array $allIds): void
    {
        $mapped = count($byAssetId);

        if ($mapped === 0) {
            return;
        }

        $missing = count(array_diff(array_values($byAssetId), array_keys($allIds)));
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
        $uses = $this->usageCounts($orphanIds);

        foreach (array_chunk($orphanIds, self::CHUNK_SIZE) as $ids) {
            foreach (Asset::find()->id($ids)->status(null)->all() as $asset) {
                $used = (int)($uses[$asset->id] ?? 0);

                if ($used > 0 || !$this->trashOrphans) {
                    $reason = $used > 0 ? "still used by {$used} element(s)" : 'trashOrphans is off';
                    Craft::error(
                        "Dash asset #{$asset->id} ({$asset->getPath()}) no longer exists in Dash but was kept: {$reason}.",
                        __METHOD__,
                    );

                    // Stamped once and then left alone, so the control panel can report how
                    // long this has been broken rather than how recently a sync noticed.
                    $db->createCommand()->update(
                        self::MAP_TABLE,
                        ['missingSince' => Db::prepareDateForDb(new DateTime())],
                        ['assetId' => $asset->id, 'missingSince' => null],
                    )->execute();

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
                'previewUrl' => $state['previewUrl'],
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

    /**
     * Drop volume folders that no in-scope Dash asset lives in any more.
     *
     * `ensureFolderByFullPathAndVolume()` only ever creates rows, and nothing in Craft
     * removes them, so a folder renamed or emptied in Dash — or belonging to a tenant this
     * environment used to point at — would sit in the control panel tree forever.
     *
     * Two guards. Ancestors of a kept folder are kept, because `volumefolders.parentId`
     * cascades and deleting a parent would take its children with it. And a folder is only
     * dropped when nothing at all references it, including trashed assets, because
     * `assets.folderId` cascades too: pruning a folder that still held one would delete the
     * asset row outright, behind Craft's element lifecycle rather than through it.
     *
     * @param array<string, array> $dash in-scope Dash state, keyed by Dash id
     */
    private function pruneEmptyFolders(Volume $volume, array $dash): int
    {
        $keep = [];

        foreach ($dash as $state) {
            $path = $this->folderPathOf($state['path']);

            // Every prefix, so a parent is never pruned out from under its children.
            foreach (explode('/', rtrim($path, '/')) as $segment) {
                $prefix = ($prefix ?? '') === '' ? $segment : $prefix . '/' . $segment;
                $keep[$prefix . '/'] = true;
            }

            unset($prefix);
        }

        // The root is excluded by both checks: volume roots created in the control panel
        // carry a NULL path, while Volumes::saveVolume() writes an empty string. Missing
        // the second case would prune the root, and the parentId/folderId cascades would
        // take every folder row and asset row in the volume with it.
        $db = Craft::$app->getDb();
        $stale = $db->createCommand(
            "SELECT f.id, f.path FROM {{%volumefolders}} f
             WHERE f.volumeId = :v AND f.path IS NOT NULL AND f.path <> ''
               AND NOT EXISTS (SELECT 1 FROM {{%assets}} a WHERE a.folderId = f.id)",
            [':v' => $volume->id],
        )->queryAll();

        $ids = [];

        foreach ($stale as $folder) {
            if (!isset($keep[$folder['path']])) {
                $ids[] = (int)$folder['id'];
                $this->log("  FOLDER   removed  {$folder['path']}");
            }
        }

        if ($ids === []) {
            return 0;
        }

        $db->createCommand()->delete('{{%volumefolders}}', ['id' => $ids])->execute();

        // What was asked for, not what the statement reported: parentId cascades, so a child
        // is already gone by the time its own id comes up and the affected-row count reads
        // lower than the number of folders that actually disappeared.
        return count($ids);
    }

    /**
     * Forget everything synced from Dash: trash the volume's assets, drop the Dash id
     * mappings, and clear the watermark so the next run starts cold.
     *
     * Both halves are required. Dropping only the mappings would leave the assets behind as
     * unmapped strays that adoptUnmapped() then tries to match against a library they never
     * came from; trashing only the assets would leave mapping rows pointing at nothing.
     *
     * Assets are trashed rather than erased, so running this against the wrong environment is
     * recoverable. Transform records go with them — the derivative *files* are left on disk,
     * which is a pre-existing gap rather than one this introduces.
     *
     * The `dash_config` table is untouched: the folder selection is configuration a person
     * chose, not state the sync derived, which is why it lives apart from both other tables.
     *
     * @return array{trashed: int, unmapped: int, folders: int, failed: int}
     */
    public function reset(): array
    {
        $volume = DashVolumes::single();
        $db = Craft::$app->getDb();
        $elements = Craft::$app->getElements();
        $transforms = Craft::$app->getImageTransforms();
        $counts = ['trashed' => 0, 'unmapped' => 0, 'folders' => 0, 'failed' => 0];

        foreach (array_chunk(Asset::find()->volumeId($volume->id)->status(null)->ids(), self::CHUNK_SIZE) as $ids) {
            foreach (Asset::find()->id($ids)->status(null)->all() as $asset) {
                $transforms->deleteAllTransformData($asset);

                if (!$elements->deleteElement($asset)) {
                    $this->log("  FAILED   #{$asset->id}  could not trash");
                    $counts['failed']++;
                    continue;
                }

                $this->log("  TRASHED  #{$asset->id}  {$asset->getPath()}");
                $counts['trashed']++;
            }
        }

        $counts['folders'] = $this->clearFolderTree($volume);
        $counts['unmapped'] = (int)$db->createCommand()->delete(self::MAP_TABLE)->execute();
        $db->createCommand()->delete(self::STATE_TABLE, ['k' => 'lastSync'])->execute();
        $this->clearCaches();

        return $counts;
    }

    /**
     * Remove the volume's folder rows, so the control panel tree does not keep showing a
     * different tenant's structure. The root folder stays — Craft requires one per volume.
     *
     * Trashed assets still carry the folderId they had, and `assets.folderId` cascades on
     * delete: dropping the folders while anything still points at them would delete those
     * asset rows outright, behind Craft's element lifecycle rather than through it. So they
     * are re-pointed at the root first, which keeps them recoverable — restored from the
     * trash they land in the volume root rather than in a folder that no longer exists.
     *
     * `volumefolders.parentId` cascades too, so children go with their parents; deleting the
     * whole non-root set in one statement is safe either way.
     */
    private function clearFolderTree(Volume $volume): int
    {
        $db = Craft::$app->getDb();
        $root = Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volume->id);

        if ($root === null) {
            return 0;
        }

        $db->createCommand()
            ->update('{{%assets}}', ['folderId' => $root->id], ['volumeId' => $volume->id])
            ->execute();

        return (int)$db->createCommand()->delete('{{%volumefolders}}', [
            'and',
            ['volumeId' => $volume->id],
            ['not', ['id' => $root->id]],
        ])->execute();
    }

    /**
     * Drop everything cached about the current tenant — the filesystem listing, the folder
     * list behind the selection form, and the control panel's badge count. Left alone they
     * would keep describing a library this environment no longer talks to.
     */
    public function clearCaches(): void
    {
        DashFs::clearCache();
        $this->config()->clearFolderCache();
        Craft::$app->getCache()->delete(self::BADGE_CACHE_KEY);
    }

    /**
     * Assets that stopped coming back from Dash and were kept rather than trashed — the
     * ones that will serve a broken image. Reads the mapping table only: the control panel
     * must never call Dash to render a page.
     *
     * @return array<int, string> assetId => when it went missing
     */
    public function missingAssets(): array
    {
        $rows = Craft::$app->getDb()
            ->createCommand('SELECT assetId, missingSince FROM ' . self::MAP_TABLE
                . ' WHERE missingSince IS NOT NULL ORDER BY missingSince ASC')
            ->queryAll();

        return array_column($rows, 'missingSince', 'assetId');
    }

    /**
     * How many elements relate to each of the given assets. Relations are how Assets fields
     * store their references, so this is the in-use test — with one blind spot: an asset
     * referenced only from inside rich text as a `{asset:123:url}` ref tag is not seen.
     *
     * @param int[] $assetIds
     * @return array<int, int> assetId => number of relations
     */
    public function usageCounts(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $rows = Craft::$app->getDb()->createCommand(
            'SELECT targetId, COUNT(*) AS uses FROM {{%relations}} WHERE targetId IN ('
            . implode(',', array_map('intval', $assetIds)) . ') GROUP BY targetId',
        )->queryAll();

        return array_column($rows, 'uses', 'targetId');
    }

    /**
     * The Dash preview URL for an asset, or null if it is not a Dash asset.
     *
     * Loaded for the whole volume in one query and held for the request: the control panel
     * asks per asset, and a folder of 225 would otherwise be 225 queries.
     */
    public function previewUrl(int $assetId): ?string
    {
        if ($this->previewUrls === null) {
            $rows = Craft::$app->getDb()
                ->createCommand('SELECT assetId, previewUrl FROM ' . self::MAP_TABLE)
                ->queryAll();

            $this->previewUrls = array_map(
                static fn($url) => $url === '' ? null : $url,
                array_column($rows, 'previewUrl', 'assetId'),
            );
        }

        return $this->previewUrls[$assetId] ?? null;
    }

    public function missingCount(): int
    {
        return (int)Craft::$app->getDb()
            ->createCommand('SELECT COUNT(*) FROM ' . self::MAP_TABLE . ' WHERE missingSince IS NOT NULL')
            ->queryScalar();
    }

    public function lastSync(): ?string
    {
        return $this->state('lastSync');
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

    private function config(): DashConfig
    {
        return Plugin::getInstance()->getDashConfig();
    }

    private function transforms(): DashTransforms
    {
        $transforms = Plugin::getInstance()->getDashTransforms();
        $transforms->logger ??= $this->logger;

        return $transforms;
    }
}

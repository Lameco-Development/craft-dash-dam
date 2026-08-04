<?php

namespace lameco\dash\services;

use Craft;
use craft\helpers\Db;
use DateTime;
use lameco\dash\models\Mapping;
use yii\base\Component;

/**
 * The mapping between asset elements and the Dash assets they came from.
 *
 * This is the identity Craft's path-based asset model cannot provide. Reconciling on path
 * reads a folder move in Dash as one file missing plus one file new, orphaning every
 * relation pointing at the asset — so the sync reconciles on the Dash UUID held here.
 *
 * A module rather than SQL inside the sync because four other places ask it questions: the
 * utility renders the broken-asset list, the plugin resolves control panel thumbnails, the
 * console command counts mappings before a reset, and the reconcile itself reads the mapped
 * set back after creating elements. Spreading five column names across all of them is what
 * this replaces.
 *
 * Deliberately owns this one table and no other. The sync watermark lives with the sync that
 * writes it, and the in-use question belongs to AssetUsage — see ADR 0002.
 */
class DashAssetMap extends Component
{
    private const MAP_TABLE = '{{%dash_asset_map}}';

    /** Cache key for the control panel badge, owned here with the count it caches. */
    private const BADGE_CACHE_KEY = 'dash.missingCount';

    /** Long enough that a control panel page load never pays for the count twice. */
    private const BADGE_CACHE_DURATION = 60;

    /** @var array<int, string|null>|null assetId => preview URL, loaded once per request */
    private ?array $previewUrls = null;

    /**
     * Every mapping, keyed by asset id — what the reconcile compares the Dash side against.
     *
     * @return array<int, Mapping>
     */
    public function all(): array
    {
        $rows = Craft::$app->getDb()
            ->createCommand('SELECT assetId, dashId, checksum, missingSince, previewUrl FROM ' . self::MAP_TABLE)
            ->queryAll();

        $mappings = [];

        foreach ($rows as $row) {
            $assetId = (int)$row['assetId'];
            $mappings[$assetId] = new Mapping(
                $assetId,
                (string)$row['dashId'],
                $row['checksum'],
                $row['missingSince'],
                $row['previewUrl'] === '' ? null : $row['previewUrl'],
            );
        }

        return $mappings;
    }

    /**
     * @return int[] every mapped asset id
     */
    public function assetIds(): array
    {
        return array_map('intval', Craft::$app->getDb()
            ->createCommand('SELECT assetId FROM ' . self::MAP_TABLE)
            ->queryColumn());
    }

    public function count(): int
    {
        return (int)Craft::$app->getDb()
            ->createCommand('SELECT COUNT(*) FROM ' . self::MAP_TABLE)
            ->queryScalar();
    }

    public function add(int $assetId, string $dashId, ?string $checksum = null, ?string $previewUrl = null): void
    {
        Craft::$app->getDb()->createCommand()->insert(self::MAP_TABLE, [
            'assetId' => $assetId,
            'dashId' => $dashId,
            'checksum' => $checksum,
            'previewUrl' => $previewUrl,
        ])->execute();
    }

    /**
     * Dash signs preview URLs for 30 days and hands back the same one until it re-signs, so
     * this is stored unconditionally rather than compared — the stored URL is what the
     * control panel renders, and letting it age out would break every thumbnail.
     */
    public function recordFile(int $assetId, ?string $checksum, ?string $previewUrl): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(self::MAP_TABLE, ['checksum' => $checksum, 'previewUrl' => $previewUrl], ['assetId' => $assetId])
            ->execute();
    }

    /**
     * Stamped once and then left alone, so the control panel reports how long an asset has
     * been broken rather than how recently a sync noticed. The `missingSince => null` half of
     * the condition is what makes it once — a later run matches no row.
     */
    public function markMissing(int $assetId): void
    {
        Craft::$app->getDb()->createCommand()->update(
            self::MAP_TABLE,
            ['missingSince' => Db::prepareDateForDb(new DateTime())],
            ['assetId' => $assetId, 'missingSince' => null],
        )->execute();
    }

    /** Back in Dash, or pulled out of its bin — this is what takes it off the broken list. */
    public function markReturned(int $assetId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(self::MAP_TABLE, ['missingSince' => null], ['assetId' => $assetId])
            ->execute();
    }

    public function forget(int $assetId): void
    {
        Craft::$app->getDb()->createCommand()->delete(self::MAP_TABLE, ['assetId' => $assetId])->execute();
    }

    /**
     * @return int how many mappings were dropped
     */
    public function forgetAll(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(self::MAP_TABLE)->execute();
    }

    /**
     * Assets that stopped coming back from Dash and were kept rather than trashed — the ones
     * that will serve a broken image. Reads this table only: the control panel must never
     * call Dash to render a page.
     *
     * @return array<int, string> assetId => when it went missing
     */
    public function missing(): array
    {
        $rows = Craft::$app->getDb()
            ->createCommand('SELECT assetId, missingSince FROM ' . self::MAP_TABLE
                . ' WHERE missingSince IS NOT NULL ORDER BY missingSince ASC')
            ->queryAll();

        return array_column($rows, 'missingSince', 'assetId');
    }

    public function missingCount(): int
    {
        return (int)Craft::$app->getDb()
            ->createCommand('SELECT COUNT(*) FROM ' . self::MAP_TABLE . ' WHERE missingSince IS NOT NULL')
            ->queryScalar();
    }

    /**
     * The same count behind a short cache. Craft recalculates every utility's badge on every
     * control panel request and sums them into the main menu, so the uncached query would run
     * on page loads that have nothing to do with Dash.
     *
     * `missingSince` carries no index, so this is a scan of the mapping table rather than an
     * index seek. Cheap at the library sizes this plugin sees, but worth knowing before
     * anyone leans on it harder.
     */
    public function cachedMissingCount(): int
    {
        return Craft::$app->getCache()->getOrSet(
            self::BADGE_CACHE_KEY,
            fn() => $this->missingCount(),
            self::BADGE_CACHE_DURATION,
        );
    }

    /**
     * Drop the cached badge count. A reconcile is the only thing that moves that number — it
     * stamps missingSince and clears it — so it has to say so here, or the badge keeps
     * reporting the pre-sync figure until the cache expires.
     */
    public function clearBadgeCache(): void
    {
        Craft::$app->getCache()->delete(self::BADGE_CACHE_KEY);
    }

    /**
     * The Dash preview URL for an asset, or null if it is not a Dash asset.
     *
     * Loaded for the whole table in one query and held for the request: the control panel
     * asks per asset, and a folder of 225 would otherwise be 225 queries. That memo is why
     * this is the wrong reader for anything that writes and re-reads within one process —
     * use all() there, which always queries.
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
}

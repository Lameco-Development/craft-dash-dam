<?php

namespace lameco\dash\services;

use Craft;
use craft\base\ElementInterface;
use yii\base\Component;

/**
 * Whether anything still points at an asset, and what.
 *
 * Relations are how Assets fields store their references, so this is the in-use test — with
 * one blind spot: an asset referenced only from inside rich text as a `{asset:123:url}` ref
 * tag is not seen.
 *
 * Nothing here is Dash-specific; it answers a plain Craft question. It is a module of its own
 * because four callers need it for four different reasons — the sync refuses to trash an
 * orphan that is still in use, the transforms pass only generates for referenced assets, the
 * reset command warns before it trashes, and the utility tells an editor what to go and fix.
 */
class AssetUsage extends Component
{
    /**
     * How many elements relate to each of the given assets.
     *
     * @param int[] $assetIds
     * @return array<int, int> assetId => number of relations, absent when nothing relates
     */
    public function counts(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $rows = $this->query('SELECT targetId, COUNT(*) AS uses', $assetIds, ' GROUP BY targetId');

        return array_column($rows, 'uses', 'targetId');
    }

    /**
     * What an editor has to open to replace each asset: the top-level owner of every relation,
     * deduplicated.
     *
     * Relations point at whatever holds the Assets field, which for a page builder is a nested
     * entry rather than the page. Linking an editor to a Matrix block is no more use than the
     * bare count was, so each one is walked up to its root owner. That also makes the number
     * honest: the same image used in three blocks of one page is one thing to fix, and a page
     * related on two sites is still one page.
     *
     * @param int[] $assetIds
     * @return array<int, ElementInterface[]> assetId => owning elements
     */
    public function owners(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $rows = $this->query('SELECT DISTINCT targetId, sourceId', $assetIds);

        $owners = [];
        $byAsset = [];

        foreach ($rows as $row) {
            $sourceId = (int)$row['sourceId'];

            // Memoised across assets, because one block can hold several of them.
            if (!array_key_exists($sourceId, $owners)) {
                $source = Craft::$app->getElements()->getElementById($sourceId, null, null, ['status' => null]);
                // A relation can outlive what it points at; there is nothing to link to then.
                $owners[$sourceId] = $source?->getRootOwner();
            }

            if ($owners[$sourceId] !== null) {
                $byAsset[(int)$row['targetId']][$owners[$sourceId]->id] = $owners[$sourceId];
            }
        }

        return array_map('array_values', $byAsset);
    }

    /**
     * Both reads target the same rows and differ only in what they select. The ids are cast
     * and inlined rather than bound: the list is as long as the asset set, and binding a
     * variable number of parameters buys nothing once every value is an int.
     *
     * @param int[] $assetIds must not be empty — an empty IN () is a syntax error
     * @return array<int, array<string, mixed>>
     */
    private function query(string $select, array $assetIds, string $suffix = ''): array
    {
        return Craft::$app->getDb()->createCommand(
            $select . ' FROM {{%relations}} WHERE targetId IN ('
            . implode(',', array_map('intval', $assetIds)) . ')' . $suffix,
        )->queryAll();
    }
}

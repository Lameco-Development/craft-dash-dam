<?php

namespace lameco\dash\tests\integration;

use Craft;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\models\Volume;
use DateTime;
use lameco\dash\DashVolumes;
use lameco\dash\Plugin;
use lameco\dash\services\DashAssetMap;
use lameco\dash\services\DashConfig;
use lameco\dash\services\DashSync;
use PHPUnit\Framework\TestCase;
use yii\db\Transaction;

/**
 * A test against the booted Craft app: fresh application and fake Dash tenant per test,
 * every database change rolled back afterwards.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected FakeDashApi $dash;
    private ?Transaction $transaction = null;

    protected function setUp(): void
    {
        CraftHarness::ensureInstalled();
        CraftHarness::freshApp();

        $this->dash = FakeDashApi::withDefaultTenant();
        CraftHarness::injectDashApi($this->dash);

        $this->transaction = Craft::$app->getDb()->beginTransaction();

        // The default tenant's every folder, Unfiled included — the baseline the suite was
        // written against. Inside the transaction, so tests that narrow or empty the
        // selection start from the same clean slate. An empty selection refuses to sync.
        $this->dashConfig()->setSyncFolders(['Archief', 'Beeldbank', 'Corporate', 'Unfiled']);
    }

    protected function tearDown(): void
    {
        $this->transaction?->rollBack();
        $this->transaction = null;
        CraftHarness::teardownApp();
    }

    /**
     * @param array<string, mixed> $syncSettings per-run overrides, the way the console
     * command applies --allowMassDeletion after the component is built
     * @return array<string, int>
     */
    protected function reconcile(array $syncSettings = []): array
    {
        $sync = $this->sync();

        foreach ($syncSettings as $name => $value) {
            $sync->$name = $value;
        }

        return $sync->reconcile();
    }

    protected function sync(): DashSync
    {
        return $this->plugin()->getDashSync();
    }

    protected function plugin(): Plugin
    {
        $plugin = Plugin::getInstance();
        self::assertNotNull($plugin);

        return $plugin;
    }

    protected function volume(): Volume
    {
        return DashVolumes::single();
    }

    /**
     * @return Asset[] every asset in the Dash volume, trashed ones excluded, keyed by path
     */
    protected function assetsByPath(): array
    {
        $assets = [];

        foreach (Asset::find()->volumeId($this->volume()->id)->status(null)->all() as $asset) {
            $assets[$asset->getPath()] = $asset;
        }

        ksort($assets, SORT_STRING);

        return $assets;
    }

    protected function assetByPath(string $path): Asset
    {
        $asset = $this->assetsByPath()[$path] ?? null;
        self::assertNotNull($asset, "No asset at '{$path}' — volume holds: " . implode(', ', array_keys($this->assetsByPath())));

        return $asset;
    }

    /**
     * Mapping rows keyed by Dash id — the key the fixtures are written against, which is why
     * this re-keys rather than handing back DashAssetMap's asset-id map directly.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function mapRows(): array
    {
        $rows = [];

        foreach ($this->assetMap()->all() as $mapping) {
            $rows[$mapping->dashId] = [
                'assetId' => $mapping->assetId,
                'dashId' => $mapping->dashId,
                'checksum' => $mapping->checksum,
                'missingSince' => $mapping->missingSince,
                'previewUrl' => $mapping->previewUrl,
            ];
        }

        return $rows;
    }

    /**
     * @return string[] the volume's folder paths, root excluded
     */
    protected function folderPaths(): array
    {
        return Craft::$app->getDb()
            ->createCommand(
                "SELECT path FROM {{%volumefolders}} WHERE volumeId = :v AND path IS NOT NULL AND path <> '' ORDER BY path",
                [':v' => $this->volume()->id],
            )
            ->queryColumn();
    }

    protected function dashConfig(): DashConfig
    {
        return $this->plugin()->getDashConfig();
    }

    protected function assetMap(): DashAssetMap
    {
        return $this->plugin()->getDashAssetMap();
    }

    /**
     * Author alt text in Craft the way an editor does, which is the one write the
     * read-only guard deliberately allows.
     */
    protected function saveAlt(Asset $asset, string $alt): void
    {
        $asset->alt = $alt;
        self::assertTrue(Craft::$app->getElements()->saveElement($asset, false, true, false));
    }

    /**
     * What an editor moving the focal point in the image editor leaves behind, without the
     * controller round-trip: a value on the element and a row rewritten by Asset::afterSave().
     */
    protected function saveFocalPoint(Asset $asset, float $x, float $y): void
    {
        $asset->setFocalPoint(['x' => $x, 'y' => $y]);
        self::assertTrue(Craft::$app->getElements()->saveElement($asset, false, true, false));
    }

    /**
     * Mark a mapping missing straight in the database, without a reconcile — so a test can
     * change the number the badge counts without going through the code that invalidates it.
     */
    protected function stampMissing(string $dashId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%dash_asset_map}}', ['missingSince' => Db::prepareDateForDb(new DateTime())], ['dashId' => $dashId])
            ->execute();
    }

    /**
     * Simulate a mapping lost before the plugin existed — the state adoption exists for.
     */
    protected function deleteMapRow(string $dashId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%dash_asset_map}}', ['dashId' => $dashId])
            ->execute();
    }

    /**
     * Drift an asset's path behind the sync's back, straight in the database — the way
     * legacy data actually drifted, and without tripping the read-only guard.
     */
    protected function driftToFolder(Asset $asset, string $folderPath): void
    {
        $folderId = Craft::$app->getDb()->createCommand(
            'SELECT id FROM {{%volumefolders}} WHERE volumeId = :v AND path = :p',
            [':v' => $this->volume()->id, ':p' => $folderPath],
        )->queryScalar();
        self::assertNotFalse($folderId, "No volume folder at '{$folderPath}'");

        Craft::$app->getDb()->createCommand()
            ->update('{{%assets}}', ['folderId' => $folderId], ['id' => $asset->id])
            ->execute();
    }

    protected function insertTransformIndexRow(Asset $asset): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%imagetransformindex}}', [
            'assetId' => $asset->id,
            'transformString' => '_100x100_crop_center-center_none',
            'fileExists' => true,
            'inProgress' => false,
            'error' => false,
        ])->execute();
    }

    protected function transformIndexRowCount(Asset $asset): int
    {
        return (int)Craft::$app->getDb()->createCommand(
            'SELECT COUNT(*) FROM {{%imagetransformindex}} WHERE assetId = :id',
            [':id' => $asset->id],
        )->queryScalar();
    }

    /**
     * Make an asset "in use" the way Assets fields do: a row in the relations table.
     * The source is another element — any element row satisfies the foreign key, and
     * AssetUsage::counts() only looks at the target side.
     */
    protected function relate(Asset $source, Asset $target): void
    {
        $fieldId = Craft::$app->getFields()->getFieldByHandle(CraftHarness::RELATION_FIELD_HANDLE)?->id;
        self::assertNotNull($fieldId);

        Craft::$app->getDb()->createCommand()->insert('{{%relations}}', [
            'fieldId' => $fieldId,
            'sourceId' => $source->id,
            'targetId' => $target->id,
            'sortOrder' => 1,
        ])->execute();
    }
}

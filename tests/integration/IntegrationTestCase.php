<?php

namespace lameco\dash\tests\integration;

use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use lameco\dash\DashVolumes;
use lameco\dash\Plugin;
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
     * @return array<string, array<string, mixed>> mapping rows keyed by Dash id
     */
    protected function mapRows(): array
    {
        $rows = Craft::$app->getDb()
            ->createCommand('SELECT assetId, dashId, checksum, missingSince, previewUrl FROM {{%dash_asset_map}}')
            ->queryAll();

        return array_column($rows, null, 'dashId');
    }

    /**
     * @return string[] the volume's folder paths, root excluded
     */
    protected function folderPaths(): array
    {
        return Craft::$app->getDb()
            ->createCommand(
                'SELECT path FROM {{%volumefolders}} WHERE volumeId = :v AND path IS NOT NULL ORDER BY path',
                [':v' => $this->volume()->id],
            )
            ->queryColumn();
    }

    /**
     * Make an asset "in use" the way Assets fields do: a row in the relations table.
     * The source is another element — any element row satisfies the foreign key, and
     * usageCounts() only looks at the target side.
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

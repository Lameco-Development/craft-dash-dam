<?php

namespace lameco\dash\services;

use Craft;
use lameco\dash\fs\DashFs;
use lameco\dash\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Configuration the client maintains in the control panel.
 *
 * Kept in the plugin's own table rather than in plugin settings, because Craft writes those
 * to project config and every deploy applies the committed YAML over it. See
 * migrations/m260730_110340_create_dash_config.php.
 */
class DashConfig extends Component
{
    /** @see migrations/m260730_110340_create_dash_config.php */
    private const CONFIG_TABLE = '{{%dash_config}}';

    private const SYNC_FOLDERS_KEY = 'syncFolders';

    /** Folder discovery costs several Dash calls, and the form is not opened often. */
    private const FOLDER_CACHE_KEY = 'dash.availableFolders';
    private const FOLDER_CACHE_DURATION = 300;

    /**
     * Memoised per request: includesFolder() runs once per folder candidate during a
     * reconcile or filesystem index, and each miss would be a query.
     *
     * @var string[]|null
     */
    private ?array $syncFolders = null;

    /**
     * Which Dash folders are synced, as full paths. An empty array means every folder —
     * the default, so an install that never opens the form behaves as it always did.
     *
     * @return string[]
     */
    public function syncFolders(): array
    {
        if ($this->syncFolders !== null) {
            return $this->syncFolders;
        }

        $raw = $this->get(self::SYNC_FOLDERS_KEY);

        if ($raw === null || $raw === '') {
            return $this->syncFolders = [];
        }

        $decoded = json_decode($raw, true);

        return $this->syncFolders = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * @param string[] $paths
     */
    public function setSyncFolders(array $paths): void
    {
        $paths = array_values(array_unique(array_filter(array_map('strval', $paths), static fn(string $p) => $p !== '')));

        $this->set(self::SYNC_FOLDERS_KEY, json_encode($paths));
        $this->syncFolders = null;
        $this->clearFolderCache();

        // The cached filesystem listing bakes the selection into every path — in-scope
        // folders win the canonicalisation — so it must not outlive a selection change,
        // or DashFs and DashSync disagree on paths until the TTL runs out.
        DashFs::clearCache();
    }

    public function clearFolderCache(): void
    {
        Craft::$app->getCache()->delete(self::FOLDER_CACHE_KEY);
    }

    /**
     * Whether a Dash folder path is synced. A selected folder carries its descendants, so
     * picking "Beeldbank" also takes "Beeldbank/Zorg" — otherwise selecting a parent would
     * silently ignore everything filed one level deeper.
     */
    public function includesFolder(string $path): bool
    {
        $selected = $this->syncFolders();

        if ($selected === []) {
            return true;
        }

        foreach ($selected as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every folder path in Dash, for the selection form. Returns null when Dash cannot be
     * reached, so the form can say so rather than render an empty list that looks like
     * "this account has no folders".
     *
     * @return string[]|null
     */
    public function availableFolders(): ?array
    {
        $cached = Craft::$app->getCache()->get(self::FOLDER_CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $paths = array_values(Plugin::getInstance()->getDashApi()->folderPaths());
        } catch (Throwable $e) {
            Craft::error("Could not list Dash folders: {$e->getMessage()}", __METHOD__);

            return null;
        }

        sort($paths);
        Craft::$app->getCache()->set(self::FOLDER_CACHE_KEY, $paths, self::FOLDER_CACHE_DURATION);

        return $paths;
    }

    private function get(string $key): ?string
    {
        return Craft::$app->getDb()
            ->createCommand('SELECT v FROM ' . self::CONFIG_TABLE . ' WHERE k = :k', [':k' => $key])
            ->queryScalar() ?: null;
    }

    private function set(string $key, string $value): void
    {
        Craft::$app->getDb()->createCommand()
            ->upsert(self::CONFIG_TABLE, ['k' => $key, 'v' => $value], ['v' => $value])
            ->execute();
    }
}

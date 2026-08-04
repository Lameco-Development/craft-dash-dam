<?php

namespace lameco\dash\utilities;

use Craft;
use craft\base\Utility;
use craft\elements\Asset;
use lameco\dash\Plugin;

/**
 * Reports assets that were deleted in Dash but are still referenced in Craft.
 *
 * These are the ones that break a live page: the file is gone, so every transform generated
 * from it fails. The sync refuses to trash an asset that something still relates to — which
 * is the safe choice, and also a silent one, so the count is surfaced here as a badge.
 */
class DashUtility extends Utility
{
    public static function id(): string
    {
        return 'dash';
    }

    public static function displayName(): string
    {
        return Craft::t('dash-dam', 'Dash DAM');
    }

    public static function icon(): ?string
    {
        return 'images';
    }

    /**
     * Craft recalculates this on every control panel request and sums it into the main menu,
     * so the count is cached and never touches the Dash API. The cache and its invalidation
     * both live with the mapping, which is the only thing that can change the number.
     */
    public static function badgeCount(): int
    {
        return Plugin::getInstance()->getDashAssetMap()->cachedMissingCount();
    }

    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();
        $map = $plugin->getDashAssetMap();
        $config = $plugin->getDashConfig();
        $missing = $map->missing();
        $assets = [];
        $usedBy = [];

        if ($missing !== []) {
            $assets = Asset::find()->id(array_keys($missing))->status(null)->indexBy('id')->all();
            $usedBy = $plugin->getAssetUsage()->owners(array_keys($missing));
        }

        return Craft::$app->getView()->renderTemplate('dash-dam/_utility', [
            'missing' => $missing,
            'assets' => $assets,
            'usedBy' => $usedBy,
            // Decides what an empty "Used by" means: with trashing on, the sync is about to
            // clear the row by itself; with it off, the row is the whole point and stays.
            'trashOrphans' => $plugin->getSettings()->trashOrphans,
            'lastSync' => $plugin->getDashSync()->lastSync(),
            'availableFolders' => $config->availableFolders(),
            'syncFolders' => $config->syncFolders(),
        ]);
    }
}

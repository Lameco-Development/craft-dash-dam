<?php

namespace lameco\dash\utilities;

use Craft;
use craft\base\Utility;
use craft\elements\Asset;
use lameco\dash\Plugin;
use lameco\dash\services\DashSync;

/**
 * Reports assets that were deleted in Dash but are still referenced in Craft.
 *
 * These are the ones that break a live page: the file is gone, so every transform generated
 * from it fails. The sync refuses to trash an asset that something still relates to — which
 * is the safe choice, and also a silent one, so the count is surfaced here as a badge.
 */
class DashUtility extends Utility
{
    /** Long enough that a control-panel page load never pays for this twice. */
    private const BADGE_CACHE_DURATION = 60;

    public static function id(): string
    {
        return 'dash';
    }

    public static function displayName(): string
    {
        return Craft::t('_craft-dash', 'Dash');
    }

    public static function icon(): ?string
    {
        return 'images';
    }

    /**
     * Craft recalculates this on every control panel request and sums it into the main menu,
     * so it stays a single indexed COUNT behind a cache and never touches the Dash API.
     */
    public static function badgeCount(): int
    {
        return Craft::$app->getCache()->getOrSet(
            DashSync::BADGE_CACHE_KEY,
            static fn() => Plugin::getInstance()->getDashSync()->missingCount(),
            self::BADGE_CACHE_DURATION,
        );
    }

    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();
        $sync = $plugin->getDashSync();
        $config = $plugin->getDashConfig();
        $missing = $sync->missingAssets();
        $assets = [];
        $uses = [];

        if ($missing !== []) {
            $assets = Asset::find()->id(array_keys($missing))->status(null)->indexBy('id')->all();
            $uses = $sync->usageCounts(array_keys($missing));
        }

        return Craft::$app->getView()->renderTemplate('_craft-dash/_utility', [
            'missing' => $missing,
            'assets' => $assets,
            'uses' => $uses,
            'lastSync' => $sync->lastSync(),
            'availableFolders' => $config->availableFolders(),
            'syncFolders' => $config->syncFolders(),
        ]);
    }
}

<?php

namespace lameco\dash\helpers;

/**
 * Craft allows an asset exactly one folder; Dash allows many. This rule picks the single
 * Craft path deterministically: prefer folders inside the client's sync selection, then
 * take the alphabetically first full path. Fixed rule, not a setting.
 *
 * Both DashFs and DashSync derive paths from it — they must never disagree, and the same
 * asset state must produce the same path regardless of the order Dash returns folder
 * assignments in.
 */
final class CanonicalFolder
{
    /**
     * The synthetic bucket for assets that live in no Dash folder. Craft's AssetIndexer
     * throws AssetNotIndexableException for any path segment beginning with an
     * underscore, so it cannot be named "_unfiled".
     */
    public const UNFILED = 'Unfiled';

    /**
     * @param string[] $candidates full paths of the folders the asset is assigned to
     * @param callable(string): bool $inScope whether a path is inside the sync selection,
     * typically DashConfig::includesFolder()
     */
    public static function pick(array $candidates, callable $inScope): string
    {
        if ($candidates === []) {
            return self::UNFILED;
        }

        $preferred = array_filter($candidates, $inScope);
        $pool = array_values($preferred === [] ? $candidates : $preferred);

        // SORT_STRING pins the tiebreak to byte order; PHP's default comparison would
        // rank numeric-looking folder names ("9", "10") as numbers.
        sort($pool, SORT_STRING);

        return $pool[0];
    }
}

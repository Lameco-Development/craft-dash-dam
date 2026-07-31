<?php

namespace lameco\dash\helpers;

/**
 * Whether a Dash folder path falls inside a sync selection. A selected folder carries its
 * descendants — picking "Beeldbank" also takes "Beeldbank/Zorg" — otherwise selecting a
 * parent would silently ignore everything filed one level deeper. The match stops at the
 * segment boundary, so "Beeldbank" never claims "Beeldbank2".
 *
 * An empty selection includes nothing: a fresh install (or a database pulled from an
 * environment that never configured the plugin) must not import the whole library before
 * anyone has chosen what belongs in Craft.
 */
final class FolderScope
{
    /**
     * @param string[] $selected selected folder paths; empty means nothing is synced
     */
    public static function includes(array $selected, string $path): bool
    {
        if ($selected === []) {
            return false;
        }

        foreach ($selected as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}

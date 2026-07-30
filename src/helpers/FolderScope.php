<?php

namespace lameco\dash\helpers;

/**
 * Whether a Dash folder path falls inside a sync selection. A selected folder carries its
 * descendants — picking "Beeldbank" also takes "Beeldbank/Zorg" — otherwise selecting a
 * parent would silently ignore everything filed one level deeper. The match stops at the
 * segment boundary, so "Beeldbank" never claims "Beeldbank2".
 */
final class FolderScope
{
    /**
     * @param string[] $selected selected folder paths; empty means every folder is synced
     */
    public static function includes(array $selected, string $path): bool
    {
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
}

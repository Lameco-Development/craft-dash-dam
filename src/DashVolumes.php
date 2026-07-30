<?php

namespace lameco\dash;

use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use lameco\dash\errors\DashApiException;
use lameco\dash\fs\DashFs;
use Throwable;

/**
 * Finds the Dash volume by what it is rather than by what it is called: the volume whose
 * filesystem is a DashFs, whatever handle the user gave it. Matching on a handle would
 * make renaming the volume in the control panel silently kill sync, guard and thumbnails.
 */
final class DashVolumes
{
    /**
     * Every volume backed by a Dash filesystem. Zero and more-than-one are for the caller
     * to judge: the sync refuses both via single(), while the read-only guard simply
     * guards whatever is there.
     *
     * @return Volume[]
     */
    public static function all(): array
    {
        $volumes = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            try {
                $fs = $volume->getFs();
            } catch (Throwable) {
                // A volume whose filesystem no longer resolves is broken, but it is not
                // the Dash volume, and it is not this plugin's error to raise.
                continue;
            }

            if ($fs instanceof DashFs) {
                $volumes[] = $volume;
            }
        }

        return $volumes;
    }

    /**
     * The volume the sync works on. Exactly one must exist: none means the install is not
     * set up yet, and more than one is ambiguous — the plugin is single-tenant, and
     * guessing which volume to reconcile could trash assets in the wrong one.
     *
     * @throws DashApiException when there is no Dash volume, or more than one
     */
    public static function single(): Volume
    {
        $volumes = self::all();

        if ($volumes === []) {
            throw new DashApiException(
                'No Dash volume found. Create a filesystem of type Dash and a volume using it, then try again.',
            );
        }

        if (count($volumes) > 1) {
            $handles = implode(', ', array_map(static fn(Volume $volume) => $volume->handle, $volumes));

            throw new DashApiException(
                "Multiple volumes use a Dash filesystem ({$handles}). Dash DAM is single-tenant"
                . ' and syncs exactly one volume — keep one and remove the rest.',
            );
        }

        return $volumes[0];
    }

    /**
     * Whether the element is an asset living in a Dash volume.
     */
    public static function isDashAsset(?object $element): bool
    {
        if (!$element instanceof Asset) {
            return false;
        }

        try {
            return $element->getVolume()->getFs() instanceof DashFs;
        } catch (Throwable) {
            // A temporary upload has no real volume yet, and a missing one throws. Neither
            // is a Dash asset, and neither is worth failing a save or an authorization
            // check over.
            return false;
        }
    }
}

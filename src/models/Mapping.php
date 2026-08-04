<?php

namespace lameco\dash\models;

/**
 * One row of the mapping: which asset element is which Dash asset, plus what the last
 * reconcile saw of that file.
 *
 * Read-only by design. The reconcile compares against these values and writes changes back
 * through DashAssetMap; nothing re-reads a Mapping mid-run, so a stale object cannot be
 * mistaken for a fresh one.
 */
final class Mapping
{
    public function __construct(
        public readonly int $assetId,
        public readonly string $dashId,
        public readonly ?string $checksum,
        public readonly ?string $missingSince,
    ) {
    }

    /**
     * Whether this asset is currently on the broken list — stopped coming back from Dash and
     * was kept rather than trashed.
     */
    public function isMissing(): bool
    {
        return $this->missingSince !== null;
    }
}

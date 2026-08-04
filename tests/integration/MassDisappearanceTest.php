<?php

namespace lameco\dash\tests\integration;

use lameco\dash\errors\DashApiException;

/**
 * A large share of mapped assets vanishing at once reads as a broken feed — a revoked
 * permission or a partial response — not as intent, and the reconcile refuses to act on it.
 */
final class MassDisappearanceTest extends IntegrationTestCase
{
    private const REMOVED = [
        'aaaa1111-0000-4000-8000-000000000001',
        'bbbb2222-0000-4000-8000-000000000002',
        '1111aaaa-0000-4000-8000-000000000009',
        'eeee5555-0000-4000-8000-000000000005',
        'ffff6666-0000-4000-8000-000000000006',
        'abab7777-0000-4000-8000-000000000007',
    ];

    public function testMassDisappearanceRefusesToReconcileAndChangesNothing(): void
    {
        $this->reconcile();

        foreach (self::REMOVED as $dashId) {
            $this->dash->remove($dashId);
        }

        try {
            $this->reconcile();
            self::fail('Expected the reconcile to refuse.');
        } catch (DashApiException $e) {
            self::assertStringContainsString('refusing to reconcile', $e->getMessage());
        }

        self::assertCount(7, $this->assetsByPath());
        self::assertCount(7, $this->mapRows());
    }

    public function testAllowMassDeletionOverrideProceeds(): void
    {
        $this->reconcile();

        foreach (self::REMOVED as $dashId) {
            $this->dash->remove($dashId);
        }

        // What the console command's --allowMassDeletion flag does.
        $counts = $this->reconcile(['maxOrphanShare' => 1.0]);

        self::assertSame(6, $counts['trashed']);
        self::assertSame(['Archief/Oud/oud~cdcd8888.jpg'], array_keys($this->assetsByPath()));
        self::assertCount(1, $this->mapRows());
    }

    public function testSmallShareOfALargeLibraryReconcilesEvenAboveTheFloor(): void
    {
        // Sixty extra assets make the six removals a 9% share: over the absolute floor,
        // under the 10% threshold — the ordinary-deletions case for a large library.
        for ($i = 1; $i <= 60; $i++) {
            $suffix = str_pad((string)$i, 12, '0', STR_PAD_LEFT);
            $this->dash->addAsset([
                'id' => sprintf('bulk%04d', $i) . "-0000-4000-8000-{$suffix}",
                'dateLastModified' => '2026-07-01T11:00:00Z',
                'currentAssetFile' => [
                    'filename' => "bulk-{$i}.jpg",
                    'fileType' => 'IMAGE',
                    'checksum' => "checksum-bulk-{$i}",
                    'size' => 1000,
                    'previewUrl' => "https://fake.dash/preview/bulk-{$i}",
                    'dimensions' => ['width' => 100, 'height' => 100],
                ],
                'metadata' => ['values' => ['f-folders' => ['opt-archief']]],
            ]);
        }

        $this->reconcile();
        self::assertCount(67, $this->mapRows());

        foreach (self::REMOVED as $dashId) {
            $this->dash->remove($dashId);
        }

        $counts = $this->reconcile();

        self::assertSame(6, $counts['trashed']);
        self::assertCount(61, $this->mapRows());
    }

    public function testLossesAtTheFloorStillReconcileSoSmallLibrariesKeepWorking(): void
    {
        $this->reconcile();

        // One less than the refusal threshold: five missing is a huge share of seven, but
        // under the absolute floor it is treated as ordinary deletions.
        foreach (array_slice(self::REMOVED, 0, 5) as $dashId) {
            $this->dash->remove($dashId);
        }

        $counts = $this->reconcile();

        self::assertSame(5, $counts['trashed']);
        self::assertCount(2, $this->mapRows());
    }
}

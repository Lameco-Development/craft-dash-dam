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
        'cccc3333-0000-4000-8000-000000000003',
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

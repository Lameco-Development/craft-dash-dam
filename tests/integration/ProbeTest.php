<?php

namespace lameco\dash\tests\integration;

/**
 * The cheap change probe against the fake tenant, plus the paging the real search API
 * forces on every caller.
 */
final class ProbeTest extends IntegrationTestCase
{
    public function testProbeReconcileProbeSettlesToNoChanges(): void
    {
        $probe = $this->sync()->probe();
        self::assertTrue($probe['changed']);
        self::assertNull($probe['watermark']);

        self::assertNotNull($this->sync()->reconcileAndAdvance($probe['now']));

        $probe = $this->sync()->probe();
        self::assertFalse($probe['changed']);
        self::assertSame(8, $probe['remoteTotal']);
        self::assertSame(8, $probe['knownTotal']);
        self::assertSame(7, $probe['mappedTotal']);
    }

    public function testDeletionOnlyShowsUpInTheCountProbe(): void
    {
        $probe = $this->sync()->probe();
        $this->sync()->reconcileAndAdvance($probe['now']);

        $this->dash->remove('eeee5555-0000-4000-8000-000000000005');
        $probe = $this->sync()->probe();

        self::assertSame(0, $probe['modified']);
        self::assertTrue($probe['countChanged']);
        self::assertTrue($probe['changed']);
    }

    public function testMetadataEditShowsUpInTheDateWindow(): void
    {
        $probe = $this->sync()->probe();
        $this->sync()->reconcileAndAdvance($probe['now']);

        sleep(1);
        $this->dash->retitle('aaaa1111-0000-4000-8000-000000000001', 'Bijgewerkt');
        $probe = $this->sync()->probe();

        self::assertSame(1, $probe['modified']);
        self::assertTrue($probe['changed']);
    }

    public function testAllAssetsPagesThroughTheWholeLibrary(): void
    {
        $ids = [];

        foreach ($this->plugin()->getDashApi()->allAssets(3) as $asset) {
            $ids[] = $asset['id'];
        }

        self::assertCount(8, $ids);
        self::assertCount(8, array_unique($ids));
    }
}

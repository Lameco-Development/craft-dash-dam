<?php

namespace lameco\dash\tests\integration;

/**
 * Reset forgets everything synced — assets, mappings, folder tree, watermark — while the
 * folder selection, being configuration a person chose, survives.
 */
final class ResetTest extends IntegrationTestCase
{
    public function testResetTrashesUnmapsAndClearsTheWatermarkButKeepsTheSelection(): void
    {
        $this->dashConfig()->setSyncFolders(['Beeldbank', 'Corporate']);
        $counts = $this->sync()->reconcileAndAdvance(gmdate('Y-m-d\TH:i:s\Z'));
        self::assertNotNull($counts);
        self::assertNotNull($this->sync()->lastSync());

        $result = $this->sync()->reset();

        self::assertSame(5, $result['trashed']);
        self::assertSame(5, $result['unmapped']);
        self::assertSame(0, $result['failed']);

        self::assertSame([], $this->assetsByPath());
        self::assertSame([], $this->mapRows());
        self::assertSame([], $this->folderPaths());
        self::assertNull($this->sync()->lastSync());
        self::assertSame(['Beeldbank', 'Corporate'], $this->dashConfig()->syncFolders());
    }

    public function testColdSyncAfterResetRebuildsTheVolume(): void
    {
        $this->reconcile();
        $this->sync()->reset();

        $counts = $this->reconcile();

        self::assertSame(7, $counts['created']);
        self::assertCount(7, $this->assetsByPath());
    }
}

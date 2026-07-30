<?php

namespace lameco\dash\tests\integration;

/**
 * Folders no in-scope Dash asset lives in any more are dropped from the volume tree —
 * with the cascade guards that keep populated folders and ancestors safe.
 */
final class FolderPruneTest extends IntegrationTestCase
{
    public function testEmptiedFolderAndItsOrphanedAncestorArePruned(): void
    {
        $this->reconcile();
        self::assertContains('Archief/Oud/', $this->folderPaths());

        // Move both Archief/Oud residents elsewhere in Dash: duo's other folder wins,
        // oud goes to Corporate.
        $this->dash->moveToFolders('ffff6666-0000-4000-8000-000000000006', ['opt-zorg']);
        $this->dash->moveToFolders('cdcd8888-0000-4000-8000-000000000008', ['opt-corporate']);
        $counts = $this->reconcile();

        self::assertSame(2, $counts['moved']);
        self::assertSame(2, $counts['foldersPruned']);

        $paths = $this->folderPaths();
        self::assertNotContains('Archief/Oud/', $paths);
        self::assertNotContains('Archief/', $paths);
        // Ancestors of folders that still hold assets stay.
        self::assertContains('Beeldbank/', $paths);
        self::assertContains('Beeldbank/Zorg/', $paths);
        self::assertContains('Corporate/', $paths);

        // Every element survived the pruning — this is exactly the cascade the prune
        // must never trigger.
        self::assertCount(7, $this->assetsByPath());
    }

    public function testAncestorsOfFoldersHoldingOutOfScopeAssetsSurviveNarrowing(): void
    {
        $this->reconcile();

        $this->dashConfig()->setSyncFolders(['Beeldbank']);
        $counts = $this->reconcile();

        // After narrowing, Archief/ holds no asset directly and nothing in-scope beneath
        // it — only the out-of-scope oud.jpg one level down. Pruning it would cascade
        // through parentId into Archief/Oud and take the asset row with it.
        self::assertSame(0, $counts['foldersPruned']);
        self::assertContains('Archief/', $this->folderPaths());
        self::assertContains('Archief/Oud/', $this->folderPaths());
        self::assertArrayHasKey('Archief/Oud/oud~cdcd8888.jpg', $this->assetsByPath());
    }

    public function testNothingIsPrunedWhileFoldersStillHoldAssets(): void
    {
        $this->reconcile();
        $before = $this->folderPaths();

        $counts = $this->reconcile();

        self::assertSame(0, $counts['foldersPruned']);
        self::assertSame($before, $this->folderPaths());
    }
}

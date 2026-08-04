<?php

namespace lameco\dash\tests\integration;

use lameco\dash\errors\DashApiException;

/**
 * The folder selection scopes what is synced — and narrowing it must never read as
 * deletion.
 */
final class ScopeTest extends IntegrationTestCase
{
    public function testEmptySelectionRefusesToSync(): void
    {
        $this->dashConfig()->setSyncFolders([]);

        $this->expectException(DashApiException::class);
        $this->expectExceptionMessageMatches('/No folders are selected/');
        $this->reconcile();
    }

    public function testColdSyncWithSelectionOnlyCreatesInScopeAssets(): void
    {
        $this->dashConfig()->setSyncFolders(['Beeldbank']);

        $counts = $this->reconcile();

        self::assertSame(4, $counts['created']);
        self::assertSame([
            // In Beeldbank/Zorg *and* an unselected folder that sorts first: the in-scope
            // folder must win, whatever order Dash lists the assignments in. This is the
            // scope-skip regression the canonicalisation rule fixed.
            'Beeldbank/Zorg/banner~1111aaaa.jpg',
            'Beeldbank/Zorg/duo~ffff6666.jpg',
            'Beeldbank/Zorg/foto-zorg~aaaa1111.jpg',
            'Beeldbank/Zorg/foto-zorg~abab7777.jpg',
        ], array_keys($this->assetsByPath()));
    }

    public function testNarrowingTheSelectionIsNotDeletion(): void
    {
        $this->reconcile();
        self::assertCount(7, $this->assetsByPath());

        $this->dashConfig()->setSyncFolders(['Beeldbank']);
        $counts = $this->reconcile();

        // Three synced assets fall outside the selection now (logo, zwerver, oud); the
        // multi-folder duo re-canonicalises into the selected folder instead.
        self::assertSame(3, $counts['outOfScope']);
        self::assertSame(0, $counts['trashed']);
        self::assertSame(0, $counts['inUse']);
        self::assertSame(1, $counts['moved']);

        $paths = $this->assetsByPath();
        self::assertCount(7, $paths);
        self::assertArrayHasKey('Beeldbank/Zorg/duo~ffff6666.jpg', $paths);
        self::assertArrayHasKey('Corporate/logo~bbbb2222.png', $paths);
        self::assertCount(7, $this->mapRows());
    }

    public function testOutOfScopeAssetsAreLeftCompletelyAlone(): void
    {
        $this->reconcile();
        $this->dashConfig()->setSyncFolders(['Beeldbank']);
        $this->reconcile();

        // Dash-side edits to an out-of-scope asset must not reach the element.
        $this->dash->retitle('bbbb2222-0000-4000-8000-000000000002', 'Nieuwe titel');
        $counts = $this->reconcile();

        self::assertSame(0, $counts['retitled']);
        self::assertSame('Logo', $this->assetByPath('Corporate/logo~bbbb2222.png')->title);
    }
}

<?php

namespace lameco\dash\tests\unit;

use lameco\dash\helpers\FolderScope;
use PHPUnit\Framework\TestCase;

final class FolderScopeTest extends TestCase
{
    public function testEmptySelectionIncludesNothing(): void
    {
        self::assertFalse(FolderScope::includes([], 'Beeldbank'));
        self::assertFalse(FolderScope::includes([], 'Unfiled'));
    }

    public function testExactMatchIsIncluded(): void
    {
        self::assertTrue(FolderScope::includes(['Beeldbank'], 'Beeldbank'));
    }

    public function testSelectedFolderCarriesItsDescendants(): void
    {
        self::assertTrue(FolderScope::includes(['Beeldbank'], 'Beeldbank/Zorg'));
        self::assertTrue(FolderScope::includes(['Beeldbank'], 'Beeldbank/Zorg/Foto'));
    }

    public function testPrefixMatchStopsAtTheSegmentBoundary(): void
    {
        self::assertFalse(FolderScope::includes(['Beeldbank'], 'Beeldbank2'));
        self::assertFalse(FolderScope::includes(['Beeldbank'], 'Beeldbank2/Zorg'));
    }

    public function testParentOfASelectedFolderIsNotIncluded(): void
    {
        self::assertFalse(FolderScope::includes(['Beeldbank/Zorg'], 'Beeldbank'));
    }

    public function testAnySelectedPrefixWins(): void
    {
        self::assertTrue(FolderScope::includes(['Archief', 'Corporate'], 'Corporate/Logo'));
        self::assertFalse(FolderScope::includes(['Archief', 'Corporate'], 'Beeldbank'));
    }

    public function testUnfiledIsOutOfScopeUnlessSelected(): void
    {
        self::assertFalse(FolderScope::includes(['Beeldbank'], 'Unfiled'));
        self::assertTrue(FolderScope::includes(['Unfiled'], 'Unfiled'));
    }
}

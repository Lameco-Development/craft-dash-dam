<?php

namespace lameco\dash\tests\unit;

use lameco\dash\services\DashApi;
use PHPUnit\Framework\TestCase;

final class SanitiseSegmentTest extends TestCase
{
    public function testPlainNamePassesThrough(): void
    {
        self::assertSame('Beeldbank', DashApi::sanitiseSegment('Beeldbank'));
    }

    public function testSlashesBecomeDashesSoOneDashFolderStaysOnePathSegment(): void
    {
        self::assertSame('Zorg-West', DashApi::sanitiseSegment('Zorg/West'));
        self::assertSame('Zorg-West', DashApi::sanitiseSegment('Zorg\\West'));
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        self::assertSame('Zorg', DashApi::sanitiseSegment('  Zorg '));
    }

    public function testLeadingUnderscoresAreStrippedBecauseCraftRefusesToIndexThem(): void
    {
        self::assertSame('privat', DashApi::sanitiseSegment('_privat'));
        self::assertSame('privat', DashApi::sanitiseSegment('__privat'));
    }

    public function testInternalUnderscoresAreKept(): void
    {
        self::assertSame('zorg_west', DashApi::sanitiseSegment('zorg_west'));
    }

    public function testNamesThatSanitiseToNothingBecomeUntitled(): void
    {
        self::assertSame('Untitled', DashApi::sanitiseSegment(''));
        self::assertSame('Untitled', DashApi::sanitiseSegment('   '));
        self::assertSame('Untitled', DashApi::sanitiseSegment('___'));
    }
}

<?php

namespace lameco\dash\tests\unit;

use lameco\dash\fs\DashFs;
use PHPUnit\Framework\TestCase;

/**
 * The `~suffix` scheme is the path identity every Craft reference hangs on: a regression
 * here changes every asset's path on the next sync and re-orphans every relation. The
 * expected strings are pinned exactly on purpose.
 */
final class CraftFilenameTest extends TestCase
{
    private const DASH_ID = '0b1e2f3a-4c5d-6e7f-8899-aabbccddeeff';

    public function testSuffixIsFirstEightHexCharsOfTheDashedId(): void
    {
        self::assertSame('photo~0b1e2f3a.jpg', DashFs::craftFilename('photo.jpg', self::DASH_ID));
    }

    public function testFilenameWithoutExtensionGetsBareSuffix(): void
    {
        self::assertSame('README~0b1e2f3a', DashFs::craftFilename('README', self::DASH_ID));
    }

    public function testOnlyTheLastDotCountsAsExtension(): void
    {
        self::assertSame('archive.tar~0b1e2f3a.gz', DashFs::craftFilename('archive.tar.gz', self::DASH_ID));
    }

    public function testCasingOfFilenameAndIdIsPreserved(): void
    {
        self::assertSame('Foto~AB12CD34.JPG', DashFs::craftFilename('Foto.JPG', 'AB12-CD34-EF56'));
    }

    public function testIdWithoutDashesProducesTheSameSuffix(): void
    {
        self::assertSame(
            DashFs::craftFilename('photo.jpg', self::DASH_ID),
            DashFs::craftFilename('photo.jpg', str_replace('-', '', self::DASH_ID)),
        );
    }

    public function testDuplicateFilenamesInOneFolderStayDistinctPerAsset(): void
    {
        self::assertNotSame(
            DashFs::craftFilename('photo.jpg', '11111111-aaaa'),
            DashFs::craftFilename('photo.jpg', '22222222-bbbb'),
        );
    }
}

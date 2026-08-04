<?php

namespace lameco\dash\tests\integration;

/**
 * This sync handles images. Everything else Dash holds is reported and left where it is.
 */
final class FileTypeTest extends IntegrationTestCase
{
    private const BANNER = '1111aaaa-0000-4000-8000-000000000009';
    private const BANNER_PATH = 'Beeldbank/Zorg/banner~1111aaaa.jpg';

    public function testUnsupportedTypesAreCountedRatherThanSilentlyDropped(): void
    {
        $counts = $this->reconcile();

        // The tenant holds one video and one pdf.
        self::assertSame(2, $counts['skippedUnsupported']);
        self::assertSame(7, $counts['created']);
        self::assertArrayNotHasKey('Beeldbank/Zorg/clip~cccc3333.mp4', $this->assetsByPath());
    }

    /**
     * The upgrade path off video support. An asset that stops being a syncable type is not
     * gone from Dash, so it must not read as a deletion — otherwise narrowing the supported
     * types would trash live images on every install that had synced them.
     */
    public function testAnAssetThatBecomesUnsupportedIsLeftAloneRatherThanTrashed(): void
    {
        $this->reconcile();
        self::assertArrayHasKey(self::BANNER_PATH, $this->assetsByPath());

        $this->dash->changeFileType(self::BANNER, 'VIDEO');
        $counts = $this->reconcile();

        self::assertSame(1, $counts['outOfScope']);
        self::assertSame(0, $counts['trashed']);
        self::assertSame(0, $counts['inUse']);

        // Still there, still mapped — it simply stops being kept up to date.
        self::assertArrayHasKey(self::BANNER_PATH, $this->assetsByPath());
        self::assertArrayHasKey(self::BANNER, $this->mapRows());
        self::assertSame([], $this->assetMap()->missing());
    }
}

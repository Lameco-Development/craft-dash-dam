<?php

namespace lameco\dash\tests\integration;

use craft\elements\Asset;

/**
 * The creation path: a first reconcile against a populated Dash library builds every
 * element from API data alone.
 */
final class ColdSyncTest extends IntegrationTestCase
{
    public function testColdSyncCreatesEverySupportedInScopeAsset(): void
    {
        $counts = $this->reconcile();

        self::assertSame(7, $counts['created']);
        self::assertSame(2, $counts['skippedUnsupported']);
        self::assertSame(0, $counts['failed']);

        self::assertSame([
            'Archief/Oud/duo~ffff6666.jpg',
            'Archief/Oud/oud~cdcd8888.jpg',
            'Beeldbank/Zorg/banner~1111aaaa.jpg',
            'Beeldbank/Zorg/foto-zorg~aaaa1111.jpg',
            'Beeldbank/Zorg/foto-zorg~abab7777.jpg',
            'Corporate/logo~bbbb2222.png',
            'Unfiled/zwerver~eeee5555.jpg',
        ], array_keys($this->assetsByPath()));
    }

    public function testElementsAreBuiltFromApiDataWithoutReadingASingleFile(): void
    {
        $this->reconcile();

        $foto = $this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg');
        self::assertSame(1200, (int)$foto->width);
        self::assertSame(800, (int)$foto->height);
        self::assertSame(123456, (int)$foto->size);
        self::assertSame('Zorgfoto', $foto->title);
        self::assertSame(Asset::KIND_IMAGE, $foto->kind);

        // The tenant's video and pdf are both skipped: this sync handles images only.
        self::assertArrayNotHasKey('Beeldbank/Zorg/clip~cccc3333.mp4', $this->assetsByPath());

        self::assertSame(0, $this->dash->fetchCalls);
    }

    public function testMappingsCarryChecksumAndPreviewUrl(): void
    {
        $this->reconcile();

        $rows = $this->mapRows();
        self::assertCount(7, $rows);
        self::assertSame('checksum-foto-zorg-v1', $rows['aaaa1111-0000-4000-8000-000000000001']['checksum']);
        self::assertSame('https://fake.dash/preview/aaaa1111', $rows['aaaa1111-0000-4000-8000-000000000001']['previewUrl']);
        self::assertArrayNotHasKey('dddd4444-0000-4000-8000-000000000004', $rows);
    }

    public function testDuplicateFilenamesInOneDashFolderStayTwoAssets(): void
    {
        $this->reconcile();

        $first = $this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg');
        $copy = $this->assetByPath('Beeldbank/Zorg/foto-zorg~abab7777.jpg');
        self::assertNotSame($first->id, $copy->id);
        self::assertSame('Zorgfoto kopie', $copy->title);
    }

    public function testMultiFolderAssetLandsInTheAlphabeticallyFirstFolderWhenNothingIsSelected(): void
    {
        $this->reconcile();

        $this->assetByPath('Archief/Oud/duo~ffff6666.jpg');
    }

    public function testAltTextArrivingFromDashSeedsTheNewElement(): void
    {
        $this->reconcile();

        self::assertSame('Het logo', $this->assetByPath('Corporate/logo~bbbb2222.png')->alt);
        self::assertNull($this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg')->alt);
    }

    public function testSecondReconcileIsANoOp(): void
    {
        $this->reconcile();
        $counts = $this->reconcile();

        self::assertSame(0, $counts['created']);
        self::assertSame(0, $counts['moved']);
        self::assertSame(0, $counts['retitled']);
        self::assertSame(0, $counts['altSynced']);
        self::assertSame(0, $counts['resized']);
        self::assertSame(0, $counts['trashed']);
        self::assertSame(0, $counts['failed']);
    }
}

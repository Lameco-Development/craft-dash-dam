<?php

namespace lameco\dash\tests\integration;

/**
 * Craft assets in the volume without a mapping row — the state every install starts in —
 * adopted by exact path, then by unambiguous filename, and left alone when ambiguous.
 */
final class AdoptionTest extends IntegrationTestCase
{
    private const FOTO = 'aaaa1111-0000-4000-8000-000000000001';

    public function testUnmappedAssetAtItsExactPathIsAdopted(): void
    {
        $this->reconcile();
        $element = $this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg');
        $this->deleteMapRow(self::FOTO);

        $counts = $this->reconcile();

        self::assertSame(1, $counts['adopted']);
        self::assertSame(0, $counts['created']);
        self::assertSame($element->id, (int)$this->mapRows()[self::FOTO]['assetId']);
    }

    public function testUnmappedAssetWithDriftedPathIsAdoptedByUniqueFilename(): void
    {
        $this->reconcile();
        $element = $this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg');
        $this->deleteMapRow(self::FOTO);
        $this->driftToFolder($element, 'Unfiled/');

        $counts = $this->reconcile();

        self::assertSame(1, $counts['adopted']);
        self::assertSame(0, $counts['created']);
        self::assertSame($element->id, (int)$this->mapRows()[self::FOTO]['assetId']);
        // And the same run moves it back where Dash says it lives.
        self::assertSame(1, $counts['moved']);
        self::assertArrayHasKey('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg', $this->assetsByPath());
    }

    public function testAmbiguousFilenameMatchIsRefused(): void
    {
        // Two Dash assets whose ids share their first eight characters and whose
        // filenames match: their Craft filenames collide exactly, so a filename match
        // cannot tell them apart.
        foreach (['abcdefab-1111-4000-8000-000000000011' => 'opt-corporate', 'abcdefab-2222-4000-8000-000000000012' => 'opt-zorg'] as $dashId => $folder) {
            $this->dash->addAsset([
                'id' => $dashId,
                'dateLastModified' => '2026-07-01T10:00:00Z',
                'currentAssetFile' => [
                    'filename' => 'twin.jpg',
                    'fileType' => 'IMAGE',
                    'checksum' => "checksum-{$dashId}",
                    'size' => 1000,
                    'previewUrl' => "https://fake.dash/preview/{$dashId}",
                    'dimensions' => ['width' => 100, 'height' => 100],
                ],
                'metadata' => ['values' => ['f-folders' => [$folder]]],
            ]);
        }

        $this->reconcile();
        $corporateTwin = $this->assetByPath('Corporate/twin~abcdefab.jpg');
        $zorgTwin = $this->assetByPath('Beeldbank/Zorg/twin~abcdefab.jpg');

        foreach (['abcdefab-1111-4000-8000-000000000011', 'abcdefab-2222-4000-8000-000000000012'] as $dashId) {
            $this->deleteMapRow($dashId);
        }
        $this->driftToFolder($corporateTwin, 'Unfiled/');
        $this->driftToFolder($zorgTwin, 'Unfiled/');

        $counts = $this->reconcile();

        self::assertSame(2, $counts['unmatched']);
        self::assertSame(0, $counts['adopted']);
        // The Dash assets get fresh elements instead; the strays stay unmapped.
        self::assertSame(2, $counts['created']);
    }
}

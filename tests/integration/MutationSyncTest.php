<?php

namespace lameco\dash\tests\integration;

/**
 * Edits made in Dash — moves, retitles, alt text, file replacements — reflected onto
 * already-synced elements without ever changing their element id.
 */
final class MutationSyncTest extends IntegrationTestCase
{
    private const FOTO = 'aaaa1111-0000-4000-8000-000000000001';
    private const LOGO = 'bbbb2222-0000-4000-8000-000000000002';

    public function testMoveInDashMovesTheElementAndKeepsItsIdentity(): void
    {
        $this->reconcile();
        $before = $this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg');

        $this->dash->moveToFolders(self::FOTO, ['opt-corporate']);
        $counts = $this->reconcile();

        self::assertSame(1, $counts['moved']);
        $after = $this->assetByPath('Corporate/foto-zorg~aaaa1111.jpg');
        self::assertSame($before->id, $after->id);
        self::assertArrayNotHasKey('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg', $this->assetsByPath());
    }

    public function testRetitleInDashRetitlesTheElement(): void
    {
        $this->reconcile();

        $this->dash->retitle(self::FOTO, 'Zorgfoto, bijgewerkt');
        $counts = $this->reconcile();

        self::assertSame(1, $counts['retitled']);
        self::assertSame(0, $counts['moved']);
        self::assertSame('Zorgfoto, bijgewerkt', $this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg')->title);
    }

    public function testAltFromDashFillsAnEmptyCraftField(): void
    {
        $this->reconcile();
        self::assertNull($this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg')->alt);

        $this->dash->setAlt(self::FOTO, 'Verpleegkundige aan het werk');
        $counts = $this->reconcile();

        self::assertSame(1, $counts['altSynced']);
        self::assertSame('Verpleegkundige aan het werk', $this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg')->alt);
    }

    public function testAltFromDashNeverOverwritesAnEditorsValue(): void
    {
        $this->reconcile();
        $this->saveAlt($this->assetByPath('Corporate/logo~bbbb2222.png'), 'Door een redacteur geschreven');

        $this->dash->setAlt(self::LOGO, 'Iets heel anders uit Dash');
        $counts = $this->reconcile();

        self::assertSame(0, $counts['altSynced']);
        self::assertSame('Door een redacteur geschreven', $this->assetByPath('Corporate/logo~bbbb2222.png')->alt);
    }

    public function testEmptyDashAltLeavesAnEditorsValueAlone(): void
    {
        $this->reconcile();
        $this->saveAlt($this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg'), 'Blijft staan');

        $counts = $this->reconcile();

        self::assertSame(0, $counts['altSynced']);
        self::assertSame('Blijft staan', $this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg')->alt);
    }

    public function testNoAltFieldConfiguredMeansNoAltIsSyncedAtAll(): void
    {
        $this->dash->dropField('ALT-tekst');

        $counts = $this->reconcile();

        self::assertSame(7, $counts['created']);
        self::assertSame(0, $counts['altSynced']);
        // Even the asset that carries alt text in Dash: without the field there is no way
        // to tell "empty" from "not configured", so nothing may be written.
        self::assertNull($this->assetByPath('Corporate/logo~bbbb2222.png')->alt);
    }

    /**
     * A focal point exists only in Craft — Dash has no such field, so a sync that dropped it
     * would destroy it with nothing to restore it from. It survives here only because the sync
     * hydrates whole elements: Asset::afterSave() writes `focalPoint = null` for any element
     * whose value was not loaded, so narrowing that query with a select() would silently wipe
     * the column across the volume.
     */
    public function testFocalPointSurvivesAMoveRetitleAndResize(): void
    {
        $this->reconcile();
        $this->saveFocalPoint($this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg'), 0.25, 0.75);

        $this->dash->moveToFolders(self::FOTO, ['opt-corporate']);
        $this->dash->retitle(self::FOTO, 'Zorgfoto, bijgewerkt');
        $this->dash->replaceFile(self::FOTO, 'checksum-foto-zorg-v2', 999999);
        $counts = $this->reconcile();

        self::assertSame(1, $counts['moved']);
        self::assertSame(1, $counts['retitled']);
        self::assertSame(1, $counts['resized']);

        $after = $this->assetByPath('Corporate/foto-zorg~aaaa1111.jpg');
        self::assertTrue($after->getHasFocalPoint());
        self::assertSame(['x' => 0.25, 'y' => 0.75], $after->getFocalPoint());
    }

    public function testReplacedFileFlagsAFocalPointThatMayNoLongerMatch(): void
    {
        $this->reconcile();
        $this->saveFocalPoint($this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg'), 0.25, 0.75);

        $this->dash->replaceFile(self::FOTO, 'checksum-foto-zorg-v2');
        $counts = $this->reconcile();

        self::assertSame(1, $counts['restamped']);
        self::assertSame(1, $counts['reframed']);
    }

    public function testReshapedImageFlagsAFocalPointEvenWhenTheBytesLookUnchanged(): void
    {
        $this->reconcile();
        $this->saveFocalPoint($this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg'), 0.25, 0.75);

        $this->dash->resize(self::FOTO, 800, 800);
        $counts = $this->reconcile();

        self::assertSame(0, $counts['restamped']);
        self::assertSame(1, $counts['resized']);
        self::assertSame(1, $counts['reframed']);
    }

    public function testAnImageWithoutAFocalPointIsNeverFlagged(): void
    {
        $this->reconcile();

        $this->dash->replaceFile(self::FOTO, 'checksum-foto-zorg-v2', 999999);
        $counts = $this->reconcile();

        self::assertSame(1, $counts['restamped']);
        self::assertSame(0, $counts['reframed']);
    }

    public function testChecksumChangeInvalidatesTransformDataAndRestampsTheMapping(): void
    {
        $this->reconcile();
        $asset = $this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg');
        $this->insertTransformIndexRow($asset);

        $this->dash->replaceFile(self::FOTO, 'checksum-foto-zorg-v2', 999999);
        $counts = $this->reconcile();

        self::assertSame(1, $counts['restamped']);
        self::assertSame(1, $counts['resized']);
        self::assertSame(0, $this->transformIndexRowCount($asset));
        self::assertSame('checksum-foto-zorg-v2', $this->mapRows()[self::FOTO]['checksum']);
        self::assertSame(999999, (int)$this->assetByPath('Beeldbank/Zorg/foto-zorg~aaaa1111.jpg')->size);
    }
}

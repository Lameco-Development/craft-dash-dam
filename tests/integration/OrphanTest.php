<?php

namespace lameco\dash\tests\integration;

/**
 * Assets that stop coming back from Dash: trashed when nothing uses them, kept and
 * reported when something does, welcomed back when they return.
 */
final class OrphanTest extends IntegrationTestCase
{
    private const ZWERVER = 'eeee5555-0000-4000-8000-000000000005';
    private const OUD = 'cdcd8888-0000-4000-8000-000000000008';

    public function testUnusedOrphanIsTrashedAndUnmapped(): void
    {
        $this->reconcile();

        $this->dash->remove(self::ZWERVER);
        $counts = $this->reconcile();

        self::assertSame(1, $counts['trashed']);
        self::assertSame(0, $counts['inUse']);
        self::assertArrayNotHasKey('Unfiled/zwerver~eeee5555.jpg', $this->assetsByPath());
        self::assertArrayNotHasKey(self::ZWERVER, $this->mapRows());
    }

    public function testOrphanStillInUseIsKeptAndStampedOnce(): void
    {
        $this->reconcile();
        $target = $this->assetByPath('Archief/Oud/oud~cdcd8888.jpg');
        $this->relate($this->assetByPath('Corporate/logo~bbbb2222.png'), $target);

        $this->dash->remove(self::OUD);
        $counts = $this->reconcile();

        self::assertSame(1, $counts['inUse']);
        self::assertSame(0, $counts['trashed']);
        $stampedAt = $this->mapRows()[self::OUD]['missingSince'];
        self::assertNotNull($stampedAt);
        self::assertArrayHasKey('Archief/Oud/oud~cdcd8888.jpg', $this->assetsByPath());

        // The stamp records when the asset went missing, not when a sync last noticed.
        sleep(1);
        $counts = $this->reconcile();

        self::assertSame(1, $counts['inUse']);
        self::assertSame($stampedAt, $this->mapRows()[self::OUD]['missingSince']);
    }

    public function testReturnedAssetComesOffTheMissingList(): void
    {
        $this->reconcile();
        $this->relate($this->assetByPath('Corporate/logo~bbbb2222.png'), $this->assetByPath('Archief/Oud/oud~cdcd8888.jpg'));
        $this->dash->remove(self::OUD);
        $this->reconcile();

        $this->dash->restore(self::OUD);
        $counts = $this->reconcile();

        self::assertSame(1, $counts['returned']);
        self::assertNull($this->mapRows()[self::OUD]['missingSince']);
        self::assertSame([], $this->sync()->missingAssets());
    }

    /**
     * A relation held only by a draft or revision of a page is history a visitor cannot
     * reach, not a reason to keep serving a broken image. See DashSync::usedBy().
     */
    public function testOrphanRelatedOnlyFromADraftOrRevisionIsTrashedAnyway(): void
    {
        $this->reconcile();
        $target = $this->assetByPath('Archief/Oud/oud~cdcd8888.jpg');
        $source = $this->assetByPath('Corporate/logo~bbbb2222.png');
        $this->relate($source, $target);
        // Any other real element id stands in for "this is a draft/revision of something" —
        // the target asset's id is a convenient one that already exists.
        $this->markAsDerivative($source, $target->id);

        $this->dash->remove(self::OUD);
        $counts = $this->reconcile();

        self::assertSame(1, $counts['trashed']);
        self::assertSame(0, $counts['inUse']);
        self::assertArrayNotHasKey('Archief/Oud/oud~cdcd8888.jpg', $this->assetsByPath());
    }

    public function testTrashOrphansOffOnlyReports(): void
    {
        $this->reconcile();

        $this->dash->remove(self::ZWERVER);
        $counts = $this->reconcile(['trashOrphans' => false]);

        self::assertSame(0, $counts['trashed']);
        self::assertSame(1, $counts['inUse']);
        self::assertArrayHasKey('Unfiled/zwerver~eeee5555.jpg', $this->assetsByPath());
        self::assertNotNull($this->mapRows()[self::ZWERVER]['missingSince']);
    }
}

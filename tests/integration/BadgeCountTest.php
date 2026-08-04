<?php

namespace lameco\dash\tests\integration;

use lameco\dash\utilities\DashUtility;

/**
 * The control panel badge counting assets that broke while nobody was looking.
 */
final class BadgeCountTest extends IntegrationTestCase
{
    private const OUD = 'cdcd8888-0000-4000-8000-000000000008';

    public function testBadgeReportsAnAssetThatBrokeDuringTheReconcile(): void
    {
        $this->reconcile();
        $this->relate(
            $this->assetByPath('Corporate/logo~bbbb2222.png'),
            $this->assetByPath('Archief/Oud/oud~cdcd8888.jpg'),
        );

        // Primed while nothing is broken. Without invalidation this zero is what the badge
        // keeps reporting after the sync below, for as long as the cache lives.
        self::assertSame(0, DashUtility::badgeCount());

        $this->dash->remove(self::OUD);
        $counts = $this->sync()->reconcileAndAdvance(gmdate('Y-m-d\TH:i:s\Z'));

        self::assertNotNull($counts);
        self::assertSame(1, $counts['inUse']);
        self::assertSame(1, DashUtility::badgeCount());
    }

    /**
     * The count stays cached between reconciles. Pinned because the cheapest way to "fix"
     * staleness is to drop the cache, and that trades a bounded staleness window for a scan
     * of the mapping table on every control panel request.
     */
    public function testBadgeStaysCachedBetweenReconciles(): void
    {
        $this->reconcile();
        self::assertSame(0, DashUtility::badgeCount());

        // Straight into the table, behind the module's back: nothing invalidates the cache,
        // so the badge must still answer with what it cached.
        $this->stampMissing(self::OUD);

        self::assertSame(1, $this->assetMap()->missingCount());
        self::assertSame(0, DashUtility::badgeCount());
    }
}

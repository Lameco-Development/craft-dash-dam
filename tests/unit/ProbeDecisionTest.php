<?php

namespace lameco\dash\tests\unit;

use lameco\dash\helpers\ProbeDecision;
use PHPUnit\Framework\TestCase;

final class ProbeDecisionTest extends TestCase
{
    private const WATERMARK = '2026-07-30T10:00:00Z';
    private const THRESHOLD_MINUTES = 30;

    public function testFirstRunAlwaysCountsAsChanged(): void
    {
        $decision = $this->evaluate(watermark: null, modified: null, remoteTotal: 10, knownTotal: null, minutesSince: 0);

        self::assertTrue($decision['changed']);
        self::assertTrue($decision['countChanged']);
        self::assertFalse($decision['stale']);
        self::assertNull($decision['watermarkAgeMinutes']);
    }

    public function testNothingChangedMeansNoSync(): void
    {
        $decision = $this->evaluate(watermark: self::WATERMARK, modified: 0, remoteTotal: 10, knownTotal: 10, minutesSince: 5);

        self::assertFalse($decision['changed']);
        self::assertFalse($decision['countChanged']);
        self::assertFalse($decision['stale']);
    }

    public function testModifiedAssetsInTheWindowTriggerASync(): void
    {
        $decision = $this->evaluate(watermark: self::WATERMARK, modified: 3, remoteTotal: 10, knownTotal: 10, minutesSince: 5);

        self::assertTrue($decision['changed']);
    }

    public function testCountDropTriggersASyncBecauseDeletionsNeverMatchTheDateWindow(): void
    {
        $decision = $this->evaluate(watermark: self::WATERMARK, modified: 0, remoteTotal: 9, knownTotal: 10, minutesSince: 5);

        self::assertTrue($decision['changed']);
        self::assertTrue($decision['countChanged']);
    }

    public function testCountRiseTriggersASync(): void
    {
        $decision = $this->evaluate(watermark: self::WATERMARK, modified: 0, remoteTotal: 11, knownTotal: 10, minutesSince: 5);

        self::assertTrue($decision['countChanged']);
        self::assertTrue($decision['changed']);
    }

    public function testUnknownPreviousCountCountsAsChanged(): void
    {
        $decision = $this->evaluate(watermark: self::WATERMARK, modified: 0, remoteTotal: 10, knownTotal: null, minutesSince: 5);

        self::assertTrue($decision['countChanged']);
        self::assertTrue($decision['changed']);
    }

    public function testJustUnderTheStaleThresholdIsNotStale(): void
    {
        $decision = $this->evaluate(watermark: self::WATERMARK, modified: 0, remoteTotal: 10, knownTotal: 10, minutesSince: self::THRESHOLD_MINUTES - 1);

        self::assertFalse($decision['stale']);
        self::assertFalse($decision['changed']);
    }

    public function testExactlyAtTheStaleThresholdForcesAFullPass(): void
    {
        $decision = $this->evaluate(watermark: self::WATERMARK, modified: 0, remoteTotal: 10, knownTotal: 10, minutesSince: self::THRESHOLD_MINUTES);

        self::assertTrue($decision['stale']);
        self::assertTrue($decision['changed']);
        self::assertSame(30.0, $decision['watermarkAgeMinutes']);
    }

    /**
     * @return array{countChanged: bool, stale: bool, watermarkAgeMinutes: float|null, changed: bool}
     */
    private function evaluate(?string $watermark, ?int $modified, int $remoteTotal, ?int $knownTotal, int $minutesSince): array
    {
        $now = strtotime(self::WATERMARK) + $minutesSince * 60;

        return ProbeDecision::evaluate($watermark, $modified, $remoteTotal, $knownTotal, $now, self::THRESHOLD_MINUTES);
    }
}

<?php

namespace lameco\dash\helpers;

/**
 * The probe's verdict on whether a reconcile is worth running, separated from the API and
 * database reads that feed it. Three signals, each covering a blind spot of the others:
 * the modified-date window (edits), the library total (deletions, which stop matching any
 * search), and staleness (file replacements, whose stamp lands in the past).
 */
final class ProbeDecision
{
    /**
     * @param string|null $watermark when the last clean reconcile started, or null on a first run
     * @param int|null $modified how many assets Dash modified since the watermark
     * @param int $remoteTotal how many assets Dash holds now
     * @param int|null $knownTotal how many it held at the last clean reconcile
     * @param int $now current Unix timestamp
     * @param int $fullReconcileMinutes how long the cheap probe is trusted before a full pass
     * @return array{countChanged: bool, stale: bool, watermarkAgeMinutes: float|null, changed: bool}
     */
    public static function evaluate(
        ?string $watermark,
        ?int $modified,
        int $remoteTotal,
        ?int $knownTotal,
        int $now,
        int $fullReconcileMinutes,
    ): array {
        $countChanged = $knownTotal === null || $knownTotal !== $remoteTotal;
        $ageMinutes = $watermark === null ? null : (float)($now - strtotime($watermark)) / 60;
        $stale = $ageMinutes !== null && $ageMinutes >= $fullReconcileMinutes;

        return [
            'countChanged' => $countChanged,
            'stale' => $stale,
            'watermarkAgeMinutes' => $ageMinutes,
            'changed' => $watermark === null || $modified > 0 || $countChanged || $stale,
        ];
    }
}

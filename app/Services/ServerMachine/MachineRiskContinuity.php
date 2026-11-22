<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

/** Compares probe state already scoped to the same machine authority and probe revision. */
final class MachineRiskContinuity
{
    public static function newest(?array ...$states): array
    {
        $newest = [];
        foreach ($states as $state) {
            if (($state['last_checked_at'] ?? 0) > ($newest['last_checked_at'] ?? 0)) $newest = $state;
        }
        return $newest;
    }

    public static function originalEpisodeStatus(bool $recovered, ?int $legacyAt, ?int $currentAt, ?int $liveAt, bool $sameOriginal): string
    {
        if ($recovered) return 'recovered';
        if (!$sameOriginal || ($legacyAt === null && $currentAt === null)) return 'mismatch';
        $last = max($legacyAt ?? 0, $currentAt ?? 0);
        if (($liveAt ?? 0) > $last) return 'sample_not_applied';
        if ($currentAt !== null && $currentAt > ($legacyAt ?? 0)) return 'adopted';
        return 'awaiting_report';
    }
}

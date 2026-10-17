<?php

declare(strict_types=1);

namespace App\Services\NodeRealtime;

final class NodeRealtimeReceipt
{
    public static function shouldReplace(?array $current, array $next): bool
    {
        if ($current === null) return true;
        $currentEvent = (string) ($current['event_id'] ?? '');
        $nextEvent = (string) ($next['event_id'] ?? '');
        $terminal = ['applied', 'failed', 'error', 'restart_required', 'ignored'];
        if ($currentEvent !== '' && $currentEvent === $nextEvent) {
            // A replayed "received" or "queued" must not undo a final acknowledgement.
            return !in_array($current['status'] ?? '', $terminal, true);
        }
        $currentTime = max(0, (int) ($current['event_ts'] ?? 0));
        $nextTime = max(0, (int) ($next['event_ts'] ?? 0));
        if ($currentTime > 0 && $nextTime > 0) {
            if ($nextTime < $currentTime) return false;
            // Same-second events have no reliable order. Never confirm a different event.
            if ($nextTime === $currentTime && in_array($next['status'] ?? '', $terminal, true)) return false;
        }
        return true;
    }
}

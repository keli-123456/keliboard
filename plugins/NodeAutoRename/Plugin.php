<?php

namespace Plugin\NodeAutoRename;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Plugin\NodeAutoRename\Services\NodeAutoRenameService;

class Plugin extends AbstractPlugin
{
    public function schedule(Schedule $schedule): void
    {
        if (!$this->isAutoRenameEnabled()) {
            return;
        }

        $event = $schedule->call(function (): void {
            $result = (new NodeAutoRenameService($this->getConfig()))->sync();
            if (($result['renamed'] ?? 0) > 0 || ($result['failed'] ?? 0) > 0) {
                Log::info('[NodeAutoRename] sync finished', $result);
            }
        })->name('plugin:node_auto_rename:sync')
            ->onOneServer()
            ->withoutOverlapping();

        $this->applyInterval($event, $this->normalizeIntervalMinutes($this->getConfig('interval_minutes', 10)));
    }

    private function isAutoRenameEnabled(): bool
    {
        $value = $this->getConfig('enable_auto_rename', true);
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function normalizeIntervalMinutes(mixed $value): int
    {
        $interval = is_numeric($value) ? (int) $value : 10;
        return in_array($interval, [1, 5, 10, 15, 30, 60], true) ? $interval : 10;
    }

    private function applyInterval(object $event, int $interval): void
    {
        match ($interval) {
            1 => $event->everyMinute(),
            5 => $event->everyFiveMinutes(),
            10 => $event->everyTenMinutes(),
            15 => $event->everyFifteenMinutes(),
            30 => $event->everyThirtyMinutes(),
            60 => $event->hourly(),
            default => $event->everyTenMinutes(),
        };
    }
}

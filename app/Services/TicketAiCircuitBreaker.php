<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class TicketAiCircuitBreaker
{
    /** @return array{open:bool,open_until:?int,failures:int} */
    public function state(string $baseUrl, string $model): array
    {
        $key = $this->key($baseUrl, $model);
        $openUntil = (int) Cache::get("{$key}:open_until", 0);
        if ($openUntil > 0 && $openUntil <= time()) {
            Cache::forget("{$key}:open_until");
            Cache::forget("{$key}:failures");
            $openUntil = 0;
        }

        return [
            'open' => $openUntil > time(),
            'open_until' => $openUntil > time() ? $openUntil : null,
            'failures' => max(0, (int) Cache::get("{$key}:failures", 0)),
        ];
    }

    public function success(string $baseUrl, string $model): void
    {
        $key = $this->key($baseUrl, $model);
        Cache::forget("{$key}:failures");
        Cache::forget("{$key}:open_until");
    }

    public function failure(string $baseUrl, string $model, int $threshold, int $cooldownMinutes): void
    {
        $key = $this->key($baseUrl, $model);
        $threshold = max(1, min(20, $threshold));
        $cooldownSeconds = max(60, min(7200, $cooldownMinutes * 60));
        $failures = max(0, (int) Cache::get("{$key}:failures", 0)) + 1;
        Cache::put("{$key}:failures", $failures, $cooldownSeconds);
        if ($failures >= $threshold) {
            Cache::put("{$key}:open_until", time() + $cooldownSeconds, $cooldownSeconds);
        }
    }

    private function key(string $baseUrl, string $model): string
    {
        return 'ticket_ai:circuit:' . hash('sha256', rtrim(trim($baseUrl), '/') . '|' . trim($model));
    }
}

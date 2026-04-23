<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\TrafficResetService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecalculateNextResetAtJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;
    private ?int $planId;
    private bool $followSystemOnly;

    public function __construct(?int $planId = null, bool $followSystemOnly = false)
    {
        $this->planId = $planId;
        $this->followSystemOnly = $followSystemOnly;
    }

    public function handle(TrafficResetService $trafficResetService): void
    {
        $processed = 0;
        $updated = 0;
        $errors = 0;

        $query = User::query()
            ->whereNotNull('plan_id')
            ->where('banned', 0)
            ->where(function ($q) {
                $q->where('expired_at', '>', time())
                    ->orWhereNull('expired_at');
            })
            ->with('plan:id,reset_traffic_method');

        if ($this->planId !== null) {
            $query->where('plan_id', $this->planId);
        }

        if ($this->followSystemOnly) {
            $query->whereHas('plan', function ($q) {
                $q->whereNull('reset_traffic_method');
            });
        }

        $query->orderBy('id')->chunkById(1000, function ($users) use (&$processed, &$updated, &$errors, $trafficResetService): void {
            foreach ($users as $user) {
                try {
                    $nextReset = $trafficResetService->calculateNextResetTime($user);
                    $nextResetTimestamp = $nextReset?->timestamp;

                    $currentResetAt = $user->next_reset_at;
                    $currentResetTimestamp = $currentResetAt instanceof DateTimeInterface
                        ? $currentResetAt->getTimestamp()
                        : ($currentResetAt !== null ? (int) $currentResetAt : null);

                    if ($currentResetTimestamp !== $nextResetTimestamp) {
                        DB::table('v2_user')
                            ->where('id', $user->id)
                            ->update(['next_reset_at' => $nextResetTimestamp]);
                        $updated++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::error('RecalculateNextResetAtJob user failed', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    $processed++;
                }
            }
        });

        Log::info('RecalculateNextResetAtJob completed', [
            'plan_id' => $this->planId,
            'follow_system_only' => $this->followSystemOnly,
            'processed' => $processed,
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }
}

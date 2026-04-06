<?php

namespace App\Jobs;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplyPlanToUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    private int $planId;

    public function __construct(int $planId)
    {
        $this->planId = $planId;
    }

    public function handle(): void
    {
        $plan = Plan::query()->find($this->planId);
        if (!$plan) {
            return;
        }

        User::query()
            ->where('plan_id', $plan->id)
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $userIds = $users->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->filter(fn(int $id) => $id > 0)
                    ->values()
                    ->all();

                if (empty($userIds)) {
                    return;
                }

                ApplyPlanToUsersChunkJob::dispatch($this->planId, $userIds);
            });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ApplyPlanToUsersJob failed', [
            'plan_id' => $this->planId,
            'error' => $e->getMessage(),
        ]);
    }
}

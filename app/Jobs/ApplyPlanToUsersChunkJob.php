<?php

namespace App\Jobs;

use App\Models\Plan;
use App\Models\User;
use App\Services\UserSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplyPlanToUsersChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    private int $planId;
    private array $userIds;

    /**
     * @param int[] $userIds
     */
    public function __construct(int $planId, array $userIds)
    {
        $this->planId = $planId;
        $this->userIds = $userIds;
    }

    public function handle(UserSyncService $userSyncService): void
    {
        $plan = Plan::query()->find($this->planId);
        if (!$plan) {
            return;
        }

        $userIds = collect($this->userIds)
            ->map(fn($id) => (int) $id)
            ->filter(fn(int $id) => $id > 0)
            ->values()
            ->all();

        if (empty($userIds)) {
            return;
        }

        $attributes = [
            'group_id' => $plan->group_id,
            'transfer_enable' => (int) $plan->transfer_enable * 1073741824,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => $plan->device_limit,
        ];

        User::query()
            ->where('plan_id', $plan->id)
            ->whereIn('id', $userIds)
            ->update($attributes);

        User::query()
            ->where('plan_id', $plan->id)
            ->whereIn('id', $userIds)
            ->get()
            ->each(fn(User $user) => $userSyncService->syncUser($user, 'plan_apply'));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ApplyPlanToUsersChunkJob failed', [
            'plan_id' => $this->planId,
            'user_count' => count($this->userIds),
            'error' => $e->getMessage(),
        ]);
    }
}

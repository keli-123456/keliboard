<?php

namespace App\Observers;

use App\Models\User;
use App\Services\TrafficResetService;
use App\Services\UserSyncService;

class UserObserver
{
  public function __construct(
    private readonly TrafficResetService $trafficResetService,
    private readonly UserSyncService $userSyncService,
  ) {
  }

  public function created(User $user): void
  {
    try {
      $this->userSyncService->syncUser($user, 'created');
    } catch (\Throwable) {
    }
  }

  public function updated(User $user): void
  {
    if ($user->isDirty(['plan_id', 'expired_at'])) {
      $user->refresh();
      User::withoutEvents(function () use ($user) {
        $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
        $user->next_reset_at = $nextResetTime?->timestamp;
        $user->save();
      });
    }

    try {
      $this->userSyncService->syncUser($user, 'updated');
    } catch (\Throwable) {
    }
  }

  public function deleted(User $user): void
  {
    try {
      $this->userSyncService->markUserDeleted((int) $user->id);
    } catch (\Throwable) {
    }
  }
}

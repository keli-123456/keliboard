<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\Plugin\HookManager;

/**
 * Service for handling traffic reset.
 */
class TrafficResetService
{
  /**
   * Check if a user's traffic should be reset and perform the reset.
   */
  public function checkAndReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_AUTO): bool
  {
    if (!$user->shouldResetTraffic()) {
      return false;
    }

    return $this->performReset($user, $triggerSource);
  }

  /**
   * Perform the traffic reset for a user.
   */
  public function performReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_MANUAL, array $metadata = []): bool
  {
    try {
      return DB::transaction(function () use ($user, $triggerSource, $metadata) {
        $lockedUser = User::query()
          ->with('plan:id,reset_traffic_method')
          ->lockForUpdate()
          ->find($user->id);

        if (!$lockedUser) {
          return false;
        }

        // For schedule-driven resets, enforce due-check under row lock to avoid duplicate resets.
        if ($this->shouldRequireDueResetCheck($triggerSource) && !$lockedUser->shouldResetTraffic()) {
          return false;
        }

        return $this->resetLockedUser($lockedUser, $triggerSource, $metadata);
      });
    } catch (\Exception $e) {
      Log::error(__('traffic_reset.reset_failed'), [
        'user_id' => $user->id,
        'email' => $user->email,
        'error' => $e->getMessage(),
        'trigger_source' => $triggerSource,
      ]);

      return false;
    }
  }

  /**
   * Calculate the next traffic reset time for a user.
   */
  public function calculateNextResetTime(User $user): ?Carbon
  {
    if (!$user->plan) {
      return null;
    }

    $resetMethod = $this->resolveResetMethod($user->plan);
    if ($resetMethod === Plan::RESET_TRAFFIC_NEVER) {
      return null;
    }

    // Calendar resets do not need an expiration anchor. Only anniversary-based
    // monthly/yearly resets depend on expired_at.
    if (
      $user->expired_at === null
      && in_array($resetMethod, [Plan::RESET_TRAFFIC_MONTHLY, Plan::RESET_TRAFFIC_YEARLY], true)
    ) {
      return null;
    }

    $now = Carbon::now(config('app.timezone'));

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => $this->getNextMonthFirstDay($now),
      Plan::RESET_TRAFFIC_MONTHLY => $this->getNextMonthlyReset($user, $now),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $this->getNextYearFirstDay($now),
      Plan::RESET_TRAFFIC_YEARLY => $this->getNextYearlyReset($user, $now),
      default => null,
    };
  }

  /**
   * Determine whether a calendar-based reset was missed in the current period.
   */
  public function isCalendarResetMissed(User $user, ?Carbon $now = null): bool
  {
    if (!$user->isActive() || !$user->plan) {
      return false;
    }

    $periodStart = $this->getCalendarPeriodStart($user->plan, $now);
    if (!$periodStart) {
      return false;
    }

    $createdAt = $this->toTimestamp($user->created_at);
    $lastResetAt = $this->toTimestamp($user->last_reset_at);

    return $createdAt !== null
      && $createdAt < $periodStart->timestamp
      && ($lastResetAt === null || $lastResetAt < $periodStart->timestamp);
  }

  /**
   * Reset one user whose first-day monthly/yearly reset was skipped.
   */
  public function reconcileMissedCalendarReset(User $user, ?Carbon $now = null): bool
  {
    return DB::transaction(function () use ($user, $now) {
      $lockedUser = User::query()
        ->with('plan:id,reset_traffic_method')
        ->lockForUpdate()
        ->find($user->id);

      if (!$lockedUser || !$this->isCalendarResetMissed($lockedUser, $now)) {
        return false;
      }

      $periodStart = $this->getCalendarPeriodStart($lockedUser->plan, $now);

      return $this->resetLockedUser($lockedUser, TrafficResetLog::SOURCE_CRON, [
        'reason' => 'missed_calendar_reset',
        'period_start' => $periodStart?->toIso8601String(),
      ]);
    });
  }

  /**
   * Get the first day of the next month.
   */
  private function getNextMonthFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->addMonth()->startOfMonth();
  }

  /**
   * Get the next monthly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. Use expiration date day/time as monthly reset anchor.
   * 2. Prioritize current month target if it is still in the future.
   * 3. If anchor day does not exist in a month (e.g. 31st in February), clamp to month-end.
   */
  private function getNextMonthlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

    $currentMonthTarget = $this->createClampedTargetDate(
      $from->year,
      $from->month,
      $resetDay,
      $resetTime
    );

    if ($currentMonthTarget->greaterThan($from)) {
      return $currentMonthTarget;
    }

    $nextMonth = $from->copy()->startOfMonth()->addMonth();

    return $this->createClampedTargetDate(
      $nextMonth->year,
      $nextMonth->month,
      $resetDay,
      $resetTime
    );
  }

  /**
   * Get the first day of the next year.
   */
  private function getNextYearFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->addYear()->startOfYear();
  }

  /**
   * Get the next yearly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. Use expiration date month/day/time as yearly reset anchor.
   * 2. Prioritize current year target if it is still in the future.
   * 3. If anchor day does not exist in a target year/month (e.g. Feb 29 in non-leap year),
   *    clamp to month-end.
   */
  private function getNextYearlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetMonth = $expiredAt->month;
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

    $currentYearTarget = $this->createClampedTargetDate(
      $from->year,
      $resetMonth,
      $resetDay,
      $resetTime
    );
    if ($currentYearTarget->greaterThan($from)) {
      return $currentYearTarget;
    }

    return $this->createClampedTargetDate(
      $from->year + 1,
      $resetMonth,
      $resetDay,
      $resetTime
    );
  }

  /**
   * Create a reset target date while clamping day to month-end.
   */
  private function createClampedTargetDate(int $year, int $month, int $day, array $time): Carbon
  {
    [$hour, $minute, $second] = $time;
    $target = Carbon::create($year, $month, 1, $hour, $minute, $second, config('app.timezone'));
    $targetDay = min($day, $target->daysInMonth);
    $target->day($targetDay);

    return $target;
  }

  private function resolveResetMethod(Plan $plan): int
  {
    if ($plan->reset_traffic_method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      return (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    return (int) $plan->reset_traffic_method;
  }

  private function getCalendarPeriodStart(Plan $plan, ?Carbon $now = null): ?Carbon
  {
    $current = ($now ?? Carbon::now(config('app.timezone')))
      ->copy()
      ->setTimezone(config('app.timezone'));

    return match ($this->resolveResetMethod($plan)) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => $current->startOfMonth(),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $current->startOfYear(),
      default => null,
    };
  }

  private function toTimestamp(mixed $value): ?int
  {
    if ($value instanceof \DateTimeInterface) {
      return $value->getTimestamp();
    }

    return is_numeric($value) ? (int) $value : null;
  }

  /**
   * Apply a reset while the caller holds the user row lock.
   */
  private function resetLockedUser(User $user, string $triggerSource, array $metadata): bool
  {
    $oldUpload = $user->u ?? 0;
    $oldDownload = $user->d ?? 0;
    $oldTotal = $oldUpload + $oldDownload;
    $nextResetTime = $this->calculateNextResetTime($user);
    $resetTimestamp = Carbon::now(config('app.timezone'))->timestamp;

    $user->update([
      'u' => 0,
      'd' => 0,
      'last_reset_at' => $resetTimestamp,
      'reset_count' => ((int) $user->reset_count) + 1,
      'next_reset_at' => $nextResetTime?->timestamp,
    ]);

    $this->recordResetLog($user, [
      'reset_type' => $this->getResetTypeFromPlan($user->plan),
      'trigger_source' => $triggerSource,
      'old_upload' => $oldUpload,
      'old_download' => $oldDownload,
      'old_total' => $oldTotal,
      'new_upload' => 0,
      'new_download' => 0,
      'new_total' => 0,
      'metadata' => $metadata ?: null,
    ]);

    $this->clearUserCache($user);
    HookManager::call('traffic.reset.after', $user);

    return true;
  }

  /**
   * Whether this reset source must satisfy due-check under transaction lock.
   */
  private function shouldRequireDueResetCheck(string $triggerSource): bool
  {
    return in_array($triggerSource, [
      TrafficResetLog::SOURCE_AUTO,
      TrafficResetLog::SOURCE_API,
      TrafficResetLog::SOURCE_CRON,
      TrafficResetLog::SOURCE_USER_ACCESS,
    ], true);
  }


  /**
   * Record the traffic reset log.
   */
  private function recordResetLog(User $user, array $data): void
  {
    TrafficResetLog::create([
      'user_id' => $user->id,
      'reset_type' => $data['reset_type'],
      'reset_time' => now(),
      'old_upload' => $data['old_upload'],
      'old_download' => $data['old_download'],
      'old_total' => $data['old_total'],
      'new_upload' => $data['new_upload'],
      'new_download' => $data['new_download'],
      'new_total' => $data['new_total'],
      'trigger_source' => $data['trigger_source'],
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * Get the reset type from the user's plan.
   */
  private function getResetTypeFromPlan(?Plan $plan): string
  {
    if (!$plan) {
      return TrafficResetLog::TYPE_MANUAL;
    }

    $resetMethod = $plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => TrafficResetLog::TYPE_FIRST_DAY_MONTH,
      Plan::RESET_TRAFFIC_MONTHLY => TrafficResetLog::TYPE_MONTHLY,
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => TrafficResetLog::TYPE_FIRST_DAY_YEAR,
      Plan::RESET_TRAFFIC_YEARLY => TrafficResetLog::TYPE_YEARLY,
      Plan::RESET_TRAFFIC_NEVER => TrafficResetLog::TYPE_MANUAL,
      default => TrafficResetLog::TYPE_MANUAL,
    };
  }

  /**
   * Clear user-related cache.
   */
  private function clearUserCache(User $user): void
  {
    $cacheKeys = [
      "user_traffic_{$user->id}",
      "user_reset_status_{$user->id}",
      "user_subscription_{$user->token}",
    ];

    foreach ($cacheKeys as $key) {
      Cache::forget($key);
    }
  }

  /**
   * Batch check and reset users. Processes all eligible users in batches.
   */
  public function batchCheckReset(int $batchSize = 100, ?callable $progressCallback = null): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessedCount = 0;
    $batchNumber = 1;
    $errors = [];
    $lastProcessedId = 0;

    try {
      do {
        $users = User::where('next_reset_at', '<=', time())
          ->whereNotNull('next_reset_at')
          ->where('id', '>', $lastProcessedId)
          ->where(function ($query) {
            $query->where('expired_at', '>', time())
              ->orWhereNull('expired_at');
          })
          ->where('banned', 0)
          ->whereNotNull('plan_id')
          ->orderBy('id')
          ->limit($batchSize)
          ->get();

        if ($users->isEmpty()) {
          break;
        }

        $batchResetCount = 0;

        if ($progressCallback) {
          $progressCallback([
            'batch_number' => $batchNumber,
            'batch_size' => $users->count(),
            'total_processed' => $totalProcessedCount,
          ]);
        }

        foreach ($users as $user) {
          try {
            if ($this->checkAndReset($user, TrafficResetLog::SOURCE_CRON)) {
              $batchResetCount++;
              $totalResetCount++;
            }
            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          } catch (\Exception $e) {
            $error = [
              'user_id' => $user->id,
              'email' => $user->email,
              'error' => $e->getMessage(),
              'batch' => $batchNumber,
              'timestamp' => now()->toDateTimeString(),
            ];
            $errors[] = $error;

            Log::error('User traffic reset failed', $error);

            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          }
        }

        $batchNumber++;

        if ($batchNumber % 10 === 0) {
          gc_collect_cycles();
        }

        if ($batchNumber % 5 === 0) {
          usleep(100000);
        }

      } while (true);

    } catch (\Exception $e) {
      Log::error('Batch traffic reset task failed with an exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'total_processed' => $totalProcessedCount,
        'total_reset' => $totalResetCount,
        'last_processed_id' => $lastProcessedId,
      ]);

      $errors[] = [
        'type' => 'system_error',
        'error' => $e->getMessage(),
        'batch' => $batchNumber,
        'last_processed_id' => $lastProcessedId,
        'timestamp' => now()->toDateTimeString(),
      ];
    }

    $totalDuration = round(microtime(true) - $startTime, 2);

    $result = [
      'total_processed' => $totalProcessedCount,
      'total_reset' => $totalResetCount,
      'total_batches' => $batchNumber - 1,
      'error_count' => count($errors),
      'errors' => $errors,
      'duration' => $totalDuration,
      'batch_size' => $batchSize,
      'last_processed_id' => $lastProcessedId,
      'completed_at' => now()->toDateTimeString(),
    ];

    return $result;
  }

  /**
   * Set the initial reset time for a new user.
   */
  public function setInitialResetTime(User $user): void
  {
    if ($user->next_reset_at !== null) {
      return;
    }

    $nextResetTime = $this->calculateNextResetTime($user);

    if ($nextResetTime) {
      $user->update(['next_reset_at' => $nextResetTime->timestamp]);
    }
  }

  /**
   * Get the user's traffic reset history.
   */
  public function getUserResetHistory(User $user, int $limit = 10): \Illuminate\Database\Eloquent\Collection
  {
    return $user->trafficResetLogs()
      ->orderBy('reset_time', 'desc')
      ->limit($limit)
      ->get();
  }

  /**
   * Check if the user is eligible for traffic reset.
   */
  public function canReset(User $user): bool
  {
    return $user->isActive() && $user->plan !== null;
  }

  /**
   * Manually reset a user's traffic (Admin function).
   */
  public function manualReset(User $user, array $metadata = []): bool
  {
    if (!$this->canReset($user)) {
      return false;
    }

    return $this->performReset($user, TrafficResetLog::SOURCE_MANUAL, $metadata);
  }
}

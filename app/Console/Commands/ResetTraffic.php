<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Models\TrafficResetLog;
use App\Services\TrafficResetService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResetTraffic extends Command
{
  private const CHUNK_SIZE = 1000;
  private const GC_EVERY_CHUNKS = 5;
  private const CHUNK_PAUSE_US = 100000;

  protected $signature = 'reset:traffic {--fix-null : 修正模式，重新计算next_reset_at为null的用户} {--force : 强制模式，重新计算所有用户的重置时间} {--reconcile-calendar : 补偿本周期遗漏的每月1日/每年1月1日重置} {--dry-run : 仅预览补偿范围，不修改用户流量}';

  protected $description = '流量重置 - 处理所有需要重置的用户';

  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
    parent::__construct();
  }

  public function handle(): int
  {
    $fixNull = $this->option('fix-null');
    $force = $this->option('force');
    $reconcileCalendar = $this->option('reconcile-calendar');
    $dryRun = $this->option('dry-run');

    if (((int) $fixNull + (int) $force + (int) $reconcileCalendar) > 1) {
      $this->error('修正、强制重算和日历补偿模式不能同时使用。');
      return self::INVALID;
    }

    if ($dryRun && !$reconcileCalendar) {
      $this->error('--dry-run 只能与 --reconcile-calendar 一起使用。');
      return self::INVALID;
    }

    $this->info('🚀 开始执行流量重置任务...');

    if ($fixNull) {
      $this->warn('🔧 修正模式 - 将重新计算next_reset_at为null的用户');
    } elseif ($force) {
      $this->warn('⚡ 强制模式 - 将重新计算所有用户的重置时间');
    } elseif ($reconcileCalendar) {
      $this->warn($dryRun
        ? '🔍 预览模式 - 仅统计本周期遗漏的日历重置用户'
        : '🛠️  补偿模式 - 将重置本周期被遗漏的日历重置用户');
    }

    try {
      if ($reconcileCalendar) {
        $this->displayCalendarReconciliationResults($this->performCalendarReconciliation($dryRun), $dryRun);
        return self::SUCCESS;
      }

      $result = $fixNull ? $this->performFix() : ($force ? $this->performForce() : $this->performReset());
      $this->displayResults($result, $fixNull || $force);
      return self::SUCCESS;

    } catch (\Exception $e) {
      $this->error("❌ 任务执行失败: {$e->getMessage()}");

      Log::error('流量重置命令执行失败', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      return self::FAILURE;
    }
  }

  private function displayResults(array $result, bool $isSpecialMode): void
  {
    $this->info("✅ 任务完成！\n");

    if ($isSpecialMode) {
      $this->displayFixResults($result);
    } else {
      $this->displayExecutionResults($result);
    }
  }

  private function displayFixResults(array $result): void
  {
    $this->info("📊 修正结果统计:");
    $this->info("🔍 发现用户总数: {$result['total_found']}");
    $this->info("✅ 成功修正数量: {$result['total_fixed']}");
    $this->info("⏱️  总执行时间: {$result['duration']} 秒");

    if (isset($result['total_due_reset'])) {
      $this->info("🔄 重算前已处理到期用户: {$result['total_due_reset']}");
    }

    if ($result['error_count'] > 0) {
      $this->warn("⚠️  错误数量: {$result['error_count']}");
      $this->warn("详细错误信息请查看日志");
    } else {
      $this->info("✨ 无错误发生");
    }

    if ($result['total_found'] > 0) {
      $avgTime = round($result['duration'] / $result['total_found'], 4);
      $this->info("⚡ 平均处理速度: {$avgTime} 秒/用户");
    }
  }



  private function displayExecutionResults(array $result): void
  {
    $this->info("📊 执行结果统计:");
    $this->info("👥 处理用户总数: {$result['total_processed']}");
    $this->info("🔄 重置用户数量: {$result['total_reset']}");
    $this->info("⏱️  总执行时间: {$result['duration']} 秒");

    if ($result['error_count'] > 0) {
      $this->warn("⚠️  错误数量: {$result['error_count']}");
      $this->warn("详细错误信息请查看日志");
    } else {
      $this->info("✨ 无错误发生");
    }

    if ($result['total_processed'] > 0) {
      $avgTime = round($result['duration'] / $result['total_processed'], 4);
      $this->info("⚡ 平均处理速度: {$avgTime} 秒/用户");
    }
  }

  private function displayCalendarReconciliationResults(array $result, bool $dryRun): void
  {
    $this->info($dryRun ? '📊 日历重置补偿预览:' : '📊 日历重置补偿结果:');
    $this->info("🔍 扫描有效用户: {$result['total_scanned']}");
    $this->info("⚠️  发现遗漏用户: {$result['total_candidates']}");

    if (!$dryRun) {
      $this->info("✅ 成功补偿数量: {$result['total_reset']}");
    }

    $this->info("⏱️  总执行时间: {$result['duration']} 秒");

    if ($result['error_count'] > 0) {
      $this->warn("⚠️  错误数量: {$result['error_count']}");
      $this->warn('详细错误信息请查看日志');
    } else {
      $this->info('✨ 无错误发生');
    }

    if ($dryRun && $result['total_candidates'] > 0) {
      $this->warn('确认范围无误后，去掉 --dry-run 即可执行补偿。');
    }
  }

  private function performReset(): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessedCount = 0;
    $errors = [];
    $chunkNumber = 0;

    $query = $this->getResetQuery();
    $totalFound = (clone $query)->count();

    if ($totalFound === 0) {
      $this->info("😴 当前没有需要重置的用户");
      return [
        'total_processed' => 0,
        'total_reset' => 0,
        'error_count' => 0,
        'duration' => round(microtime(true) - $startTime, 2),
      ];
    }

    $this->info("找到 {$totalFound} 个需要重置的用户");

    $query->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($users) use (&$totalResetCount, &$totalProcessedCount, &$errors, &$chunkNumber) {
      $chunkNumber++;

      foreach ($users as $user) {
        try {
          $totalResetCount += (int) $this->trafficResetService->checkAndReset($user, TrafficResetLog::SOURCE_CRON);
        } catch (\Exception $e) {
          $errors[] = [
            'user_id' => $user->id,
            'email' => $user->email,
            'error' => $e->getMessage(),
          ];
          Log::error('用户流量重置失败', [
            'user_id' => $user->id,
            'error' => $e->getMessage(),
          ]);
        } finally {
          $totalProcessedCount++;
        }
      }

      $this->afterChunk($chunkNumber);
    });

    return [
      'total_processed' => $totalProcessedCount,
      'total_reset' => $totalResetCount,
      'error_count' => count($errors),
      'duration' => round(microtime(true) - $startTime, 2),
    ];
  }

  private function performFix(): array
  {
    $startTime = microtime(true);
    $query = $this->getNullResetTimeUsersQuery();
    $totalFound = (clone $query)->count();

    if ($totalFound === 0) {
      $this->info("✅ 没有发现next_reset_at为null的用户");
      return [
        'total_found' => 0,
        'total_fixed' => 0,
        'error_count' => 0,
        'duration' => round(microtime(true) - $startTime, 2),
      ];
    }

    $this->info("🔧 发现 {$totalFound} 个next_reset_at为null的用户，开始修正...");

    $fixedCount = 0;
    $errors = [];
    $chunkNumber = 0;

    $query->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($users) use (&$fixedCount, &$errors, &$chunkNumber) {
      $chunkNumber++;

      foreach ($users as $user) {
        try {
          $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
          $nextResetTimestamp = $nextResetTime?->timestamp;
          $currentResetAt = $user->next_reset_at;
          $currentResetTimestamp = $currentResetAt instanceof \DateTimeInterface
            ? $currentResetAt->getTimestamp()
            : ($currentResetAt !== null ? (int) $currentResetAt : null);

          if ($currentResetTimestamp !== $nextResetTimestamp) {
            User::query()->whereKey($user->id)->update([
              'next_reset_at' => $nextResetTimestamp,
            ]);
            $fixedCount++;
          }
        } catch (\Exception $e) {
          $errors[] = [
            'user_id' => $user->id,
            'email' => $user->email,
            'error' => $e->getMessage(),
          ];
          Log::error('修正用户next_reset_at失败', [
            'user_id' => $user->id,
            'error' => $e->getMessage(),
          ]);
        }
      }

      $this->afterChunk($chunkNumber);
    });

    return [
      'total_found' => $totalFound,
      'total_fixed' => $fixedCount,
      'error_count' => count($errors),
      'duration' => round(microtime(true) - $startTime, 2),
    ];
  }

  private function performForce(): array
  {
    $this->info('强制重算前先处理已到期用户，避免把本次重置跳到下一周期...');
    $dueResult = $this->performReset();
    $dueResetCount = (int) ($dueResult['total_reset'] ?? 0);

    $startTime = microtime(true);
    $query = $this->getAllUsersQuery();
    $totalFound = (clone $query)->count();

    if ($totalFound === 0) {
      $this->info("✅ 没有发现需要处理的用户");
      return [
        'total_found' => 0,
        'total_fixed' => 0,
        'error_count' => 0,
        'duration' => round(microtime(true) - $startTime, 2),
        'total_due_reset' => $dueResetCount,
      ];
    }

    $this->info("⚡ 发现 {$totalFound} 个用户，开始重新计算重置时间...");

    $fixedCount = 0;
    $errors = [];
    $chunkNumber = 0;

    $query->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($users) use (&$fixedCount, &$errors, &$chunkNumber) {
      $chunkNumber++;

      foreach ($users as $user) {
        try {
          $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
          $nextResetTimestamp = $nextResetTime?->timestamp;
          $currentResetAt = $user->next_reset_at;
          $currentResetTimestamp = $currentResetAt instanceof \DateTimeInterface
            ? $currentResetAt->getTimestamp()
            : ($currentResetAt !== null ? (int) $currentResetAt : null);

          if ($currentResetTimestamp !== $nextResetTimestamp) {
            User::query()->whereKey($user->id)->update([
              'next_reset_at' => $nextResetTimestamp,
            ]);
            $fixedCount++;
          }
        } catch (\Exception $e) {
          $errors[] = [
            'user_id' => $user->id,
            'email' => $user->email,
            'error' => $e->getMessage(),
          ];
          Log::error('强制重新计算用户next_reset_at失败', [
            'user_id' => $user->id,
            'error' => $e->getMessage(),
          ]);
        }
      }

      $this->afterChunk($chunkNumber);
    });

    return [
      'total_found' => $totalFound,
      'total_fixed' => $fixedCount,
      'error_count' => count($errors),
      'duration' => round(microtime(true) - $startTime, 2),
      'total_due_reset' => $dueResetCount,
    ];
  }



  private function performCalendarReconciliation(bool $dryRun): array
  {
    $startTime = microtime(true);
    $now = Carbon::now(config('app.timezone'));
    $query = $this->getCalendarResetUsersQuery();
    $totalScanned = (clone $query)->count();
    $candidateCount = 0;
    $resetCount = 0;
    $errors = [];
    $chunkNumber = 0;

    $query->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($users) use ($dryRun, $now, &$candidateCount, &$resetCount, &$errors, &$chunkNumber) {
      $chunkNumber++;

      foreach ($users as $user) {
        try {
          if (!$this->trafficResetService->isCalendarResetMissed($user, $now)) {
            continue;
          }

          $candidateCount++;
          if (!$dryRun && $this->trafficResetService->reconcileMissedCalendarReset($user, $now)) {
            $resetCount++;
          }
        } catch (\Throwable $e) {
          $errors[] = [
            'user_id' => $user->id,
            'email' => $user->email,
            'error' => $e->getMessage(),
          ];
          Log::error('补偿遗漏流量重置失败', [
            'user_id' => $user->id,
            'error' => $e->getMessage(),
          ]);
        }
      }

      $this->afterChunk($chunkNumber);
    });

    return [
      'total_scanned' => $totalScanned,
      'total_candidates' => $candidateCount,
      'total_reset' => $resetCount,
      'error_count' => count($errors),
      'duration' => round(microtime(true) - $startTime, 2),
    ];
  }

  private function getCalendarResetUsersQuery()
  {
    $systemMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    $calendarMethods = [Plan::RESET_TRAFFIC_FIRST_DAY_MONTH, Plan::RESET_TRAFFIC_FIRST_DAY_YEAR];

    return User::whereNotNull('plan_id')
      ->where(function ($query) {
        $query->where('expired_at', '>', time())
          ->orWhereNull('expired_at');
      })
      ->where('banned', 0)
      ->whereHas('plan', function ($query) use ($systemMethod, $calendarMethods) {
        $query->where(function ($methodQuery) use ($systemMethod, $calendarMethods) {
          $methodQuery->whereIn('reset_traffic_method', $calendarMethods);
          if (in_array($systemMethod, $calendarMethods, true)) {
            $methodQuery->orWhereNull('reset_traffic_method');
          }
        });
      })
      ->with('plan:id,name,reset_traffic_method');
  }

  private function getResetQuery()
  {
    return User::where('next_reset_at', '<=', time())
      ->whereNotNull('next_reset_at')
      ->where(function ($query) {
        $query->where('expired_at', '>', time())
          ->orWhereNull('expired_at');
      })
      ->where('banned', 0)
      ->whereNotNull('plan_id')
      ->with('plan:id,name,reset_traffic_method');
  }



  private function getNullResetTimeUsersQuery()
  {
    return User::whereNull('next_reset_at')
      ->whereNotNull('plan_id')
      ->where(function ($query) {
        $query->where('expired_at', '>', time())
          ->orWhereNull('expired_at');
      })
      ->where('banned', 0)
      ->with('plan:id,name,reset_traffic_method');
  }

  private function getAllUsersQuery()
  {
    return User::whereNotNull('plan_id')
      ->where(function ($query) {
        $query->where('expired_at', '>', time())
          ->orWhereNull('expired_at');
      })
      ->where('banned', 0)
      ->with('plan:id,name,reset_traffic_method');
  }

  private function afterChunk(int $chunkNumber): void
  {
    if ($chunkNumber % self::GC_EVERY_CHUNKS === 0) {
      gc_collect_cycles();
      usleep(self::CHUNK_PAUSE_US);
    }
  }

}

<?php

namespace App\Console\Commands;

use App\Services\UserOnlineService;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CleanupExpiredOnlineStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:expired-online-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset online_count to 0 for users missing fresh alive cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $affected = 0;

            if (UserOnlineService::isRealtimeIndexReady()) {
                $affected += $this->cleanupRealtimeIndex();
                if ($this->shouldRunCompatibilitySweep()) {
                    $affected += $this->cleanupDatabaseFallback();
                }
            } else {
                $affected += $this->cleanupDatabaseFallback();
            }

            $this->info("Expired online status cleaned. Affected: {$affected}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('CleanupExpiredOnlineStatus failed', ['error' => $e->getMessage()]);
            $this->error('Cleanup failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function cleanupRealtimeIndex(): int
    {
        $candidateIds = UserOnlineService::getExpiredRealtimeUserIds(time());
        if (empty($candidateIds)) {
            return 0;
        }

        $affected = 0;
        foreach (array_chunk($candidateIds, 1000) as $chunk) {
            $onlineCounts = app(UserOnlineService::class)->getOnlineCounts($chunk);

            $staleIds = [];
            foreach ($chunk as $userId) {
                if ((int) ($onlineCounts[$userId] ?? 0) <= 0) {
                    $staleIds[] = $userId;
                }
            }

            if (empty($staleIds)) {
                continue;
            }

            UserOnlineService::purgeRealtimeUsers($staleIds);
            UserOnlineService::forgetAliveCaches($staleIds);

            $affected += User::query()
                ->whereIn('id', $staleIds)
                ->where('online_count', '>', 0)
                ->update([
                    'online_count' => 0,
                    'last_online_at' => null,
                ]);
        }

        return $affected;
    }

    private function cleanupDatabaseFallback(): int
    {
        $affected = 0;

        User::query()
            ->where('online_count', '>', 0)
            ->chunkById(1000, function ($users) use (&$affected) {
                if ($users->isEmpty()) {
                    return;
                }

                $userIds = $users->pluck('id')
                    ->map(fn($id): int => (int) $id)
                    ->all();
                $onlineCounts = app(UserOnlineService::class)->getOnlineCounts($userIds);

                $staleIds = [];
                foreach ($userIds as $userId) {
                    if ((int) ($onlineCounts[$userId] ?? 0) <= 0) {
                        $staleIds[] = $userId;
                    }
                }

                if (empty($staleIds)) {
                    return;
                }

                $count = User::query()
                    ->whereIn('id', $staleIds)
                    ->update([
                        'online_count' => 0,
                        'last_online_at' => null,
                    ]);
                $affected += $count;
            }, 'id');

        return $affected;
    }

    private function shouldRunCompatibilitySweep(): bool
    {
        return ((int) floor(time() / 60)) % 10 === 0;
    }
}

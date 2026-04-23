<?php

namespace App\Console\Commands;

use App\Models\UserSyncEvent;
use Illuminate\Console\Command;

class UserSyncCleanup extends Command
{
    protected $signature = 'usersync:cleanup
        {--days= : Keep events for N days (default from config)}
        {--batch= : Delete batch size (default from config)}
        {--sleep-ms= : Sleep milliseconds between batches (default from config)}
        {--max-batches= : Stop after N batches (0 means no limit)}';
    protected $description = 'Cleanup old user sync events';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: admin_setting('user_sync_retention_days', config('user_sync.retention_days', 30)));
        if ($days <= 0) {
            $days = (int) config('user_sync.retention_days', 30);
        }
        if ($days <= 0) {
            $days = 30;
        }

        $batchSize = (int) ($this->option('batch') ?: config('user_sync.cleanup_batch_size', 5000));
        if ($batchSize <= 0) {
            $batchSize = 5000;
        }
        $batchSize = max(100, min($batchSize, 50000));

        $sleepMs = (int) ($this->option('sleep-ms') ?: config('user_sync.cleanup_sleep_ms', 0));
        if ($sleepMs < 0) {
            $sleepMs = 0;
        }
        $sleepMs = min($sleepMs, 10000);

        $maxBatches = (int) ($this->option('max-batches') ?: 0);
        if ($maxBatches < 0) {
            $maxBatches = 0;
        }

        $cutoff = now()->subDays($days);
        $deletedTotal = 0;
        $batches = 0;

        while (true) {
            if ($maxBatches > 0 && $batches >= $maxBatches) {
                break;
            }

            $ids = UserSyncEvent::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id', 'asc')
                ->limit($batchSize)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if (empty($ids)) {
                break;
            }

            $deleted = UserSyncEvent::query()->whereIn('id', $ids)->delete();
            if ($deleted <= 0) {
                break;
            }
            $deletedTotal += (int) $deleted;
            $batches++;

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("usersync: cleaned {$deletedTotal} events in {$batches} batches (keep {$days} days)");
        return self::SUCCESS;
    }
}

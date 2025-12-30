<?php

namespace App\Console\Commands;

use App\Models\UserSyncEvent;
use Illuminate\Console\Command;

class UserSyncCleanup extends Command
{
    protected $signature = 'usersync:cleanup {--days= : Keep events for N days (default from config)}';
    protected $description = 'Cleanup old user sync events';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('user_sync.retention_days', 30));
        if ($days <= 0) {
            $days = 30;
        }

        $cutoff = now()->subDays($days);
        $deleted = UserSyncEvent::query()->where('created_at', '<', $cutoff)->delete();
        $this->info("usersync: cleaned {$deleted} events (keep {$days} days)");
        return self::SUCCESS;
    }
}


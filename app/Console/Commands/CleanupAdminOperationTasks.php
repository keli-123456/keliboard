<?php

namespace App\Console\Commands;

use App\Services\AdminOperationTaskService;
use Illuminate\Console\Command;

class CleanupAdminOperationTasks extends Command
{
    protected $signature = 'admin-operations:cleanup {--limit=2000}';
    protected $description = 'Delete expired terminal admin operation tasks and their items';

    public function handle(AdminOperationTaskService $tasks): int
    {
        $count = $tasks->cleanupExpired(max(1, (int) $this->option('limit')));
        $this->info("Deleted {$count} expired admin operation task(s).");

        return self::SUCCESS;
    }
}

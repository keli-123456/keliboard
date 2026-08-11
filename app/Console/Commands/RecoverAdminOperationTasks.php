<?php

namespace App\Console\Commands;

use App\Services\AdminOperationTaskService;
use Illuminate\Console\Command;

class RecoverAdminOperationTasks extends Command
{
    protected $signature = 'admin-operations:recover-stale {--limit=100}';
    protected $description = 'Mark stale admin operation tasks as interrupted so failed items can be retried';

    public function handle(AdminOperationTaskService $tasks): int
    {
        $count = $tasks->recoverStale(max(1, (int) $this->option('limit')));
        $this->info("Recovered {$count} stale admin operation task(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\MessageDispatchService;
use Illuminate\Console\Command;

class ReleaseMessageDispatchTasks extends Command
{
    protected $signature = 'message-dispatch:release {--limit=200}';
    protected $description = 'Release due dispatch tasks into queue workers with quota control';

    public function handle(MessageDispatchService $service): int
    {
        $result = $service->releaseDueTasks((int) $this->option('limit'));
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}

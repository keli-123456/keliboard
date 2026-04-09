<?php

namespace App\Console\Commands;

use App\Services\MessageDispatchService;
use Illuminate\Console\Command;

class RecoverStuckMessageDispatchTasks extends Command
{
    protected $signature = 'message-dispatch:recover-stuck {--limit=200}';
    protected $description = 'Recover message dispatch tasks stuck in sending state';

    public function handle(MessageDispatchService $service): int
    {
        $result = $service->recoverStuckSendingTasks((int) $this->option('limit'));
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\TicketAiRequestLog;
use Illuminate\Console\Command;

class CleanupTicketAiRequestLogs extends Command
{
    protected $signature = 'cleanup:ticket-ai-logs';
    protected $description = 'Delete expired ticket AI operational request logs';

    public function handle(): int
    {
        if (!$this->hasLogTable()) {
            return self::SUCCESS;
        }

        $days = max(7, min(365, (int) admin_setting('ticket_ai_log_retention_days', 30)));
        TicketAiRequestLog::query()
            ->where('created_at', '<', time() - ($days * 86400))
            ->delete();

        return self::SUCCESS;
    }

    private function hasLogTable(): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable('v2_ticket_ai_request_log');
        } catch (\Throwable) {
            return false;
        }
    }
}

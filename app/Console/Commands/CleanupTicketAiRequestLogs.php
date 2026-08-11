<?php

namespace App\Console\Commands;

use App\Models\TicketAiRequestLog;
use App\Models\TicketAiSuggestion;
use Illuminate\Console\Command;

class CleanupTicketAiRequestLogs extends Command
{
    protected $signature = 'cleanup:ticket-ai-logs';
    protected $description = 'Delete expired ticket AI request logs and review drafts';

    public function handle(): int
    {
        if ($this->hasTable('v2_ticket_ai_request_log')) {
            $days = max(7, min(365, (int) admin_setting('ticket_ai_log_retention_days', 30)));
            TicketAiRequestLog::query()
                ->where('created_at', '<', time() - ($days * 86400))
                ->delete();
        }

        if ($this->hasTable('v2_ticket_ai_suggestion')) {
            $days = max(7, min(365, (int) admin_setting('ticket_ai_suggestion_retention_days', 90)));
            TicketAiSuggestion::query()
                ->where('created_at', '<', time() - ($days * 86400))
                ->delete();
        }

        return self::SUCCESS;
    }

    private function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupAnalyticsData extends Command
{
    protected $signature = 'cleanup:analytics';
    protected $description = 'Delete expired anonymous analytics and invitation tracking data';

    public function handle(): int
    {
        $batchSize = max(100, (int) config('analytics.cleanup_batch_size', 1000));
        $deleted = [
            'visitors' => $this->deleteDateRows(
                'v2_domain_visitor_daily',
                now()->subDays((int) config('analytics.retention.visitor_days', 35))->format('Y-m-d'),
                $batchSize
            ),
            'invite_clicks' => $this->deleteTimestampRows(
                'v2_invite_click',
                'last_clicked_at',
                time() - ((int) config('analytics.retention.invite_click_days', 180) * 86400),
                $batchSize
            ),
            'domain_metrics' => $this->deleteDateRows(
                'v2_domain_metric_daily',
                now()->subDays((int) config('analytics.retention.domain_metric_days', 400))->format('Y-m-d'),
                $batchSize
            ),
        ];

        if ($this->output !== null) {
            $this->info(sprintf(
                'Analytics cleanup completed: visitors=%d invite_clicks=%d domain_metrics=%d',
                $deleted['visitors'],
                $deleted['invite_clicks'],
                $deleted['domain_metrics']
            ));
        }

        return self::SUCCESS;
    }

    private function deleteDateRows(string $table, string $before, int $batchSize): int
    {
        return $this->deleteInBatches($table, 'record_date', $before, $batchSize);
    }

    private function deleteTimestampRows(string $table, string $column, int $before, int $batchSize): int
    {
        return $this->deleteInBatches($table, $column, $before, $batchSize);
    }

    private function deleteInBatches(string $table, string $column, string|int $before, int $batchSize): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        $deleted = 0;
        do {
            $ids = DB::table($table)
                ->where($column, '<', $before)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $count = DB::table($table)->whereIn('id', $ids->all())->delete();
            $deleted += $count;
        } while ($count === $batchSize);

        return $deleted;
    }
}

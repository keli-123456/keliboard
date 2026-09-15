<?php

namespace App\Console\Commands;

use App\Services\FinancialReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AgentFinanceAudit extends Command
{
    protected $signature = 'agent:finance-audit {--days=30} {--agent=} {--json}';
    protected $description = 'Read-only database reconciliation; does not verify gateway receipts or change balances';

    public function handle(FinancialReconciliationService $service): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        $agent = $this->option('agent');
        if ($days === false || ($agent !== null && filter_var($agent, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $this->error('days must be 1..365; agent must be a positive integer.');
            return self::INVALID;
        }
        $required = ['v2_order', 'v2_user', 'v2_plan', 'v2_site', 'v2_agent_user', 'v2_agent_order_context',
            'v2_agent_balance_hold', 'v2_agent_profit', 'v2_commission_log'];
        $missing = array_values(array_filter($required, fn (string $table) => !Schema::hasTable($table)));
        if ($missing) {
            $this->line(json_encode(['ready' => false, 'missing_tables' => $missing], JSON_UNESCAPED_SLASHES));
            return self::INVALID;
        }
        // Keep platform orders in scope: a stale agent-owned invite can lead to a platform order.
        $result = $service->overview(['days' => $days, 'agent_user_id' => $agent ? (int) $agent : null]);
        $report = [
            'ready' => true, 'read_only' => true, 'verification' => 'database_only',
            'gateway_receipts_verified' => false, 'mysql_concurrency_verified' => false,
            'generated_at' => $result['generated_at'], 'range' => $result['range'], 'filters' => $result['filters'],
            'summary' => $result['summary'], 'issue_breakdown' => $result['issue_breakdown'],
            'issues' => array_merge($result['issues'], ['data' => array_map(static fn (array $issue): array =>
                array_diff_key($issue, array_flip(['user_email', 'agent_email', 'site_name'])), $result['issues']['data'])]),
        ];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('Read-only database checks. This is not gateway reconciliation or release approval.');
            $this->line('Orders: ' . $result['summary']['order_count'] . '; issues: ' . $result['issues']['total']);
            $this->table(['Rule', 'Severity', 'Order', 'Agent'], array_map(static fn (array $issue): array =>
                [$issue['code'], $issue['severity'], $issue['trade_no'] ?? '-', $issue['agent_user_id'] ?? '-'], $report['issues']['data']));
            if ($result['issues']['limited']) $this->warn('Issue details are sampled; narrow the range for investigation.');
        }
        return $result['issues']['total'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

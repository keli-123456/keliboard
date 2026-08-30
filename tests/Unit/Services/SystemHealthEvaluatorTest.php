<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SystemHealthEvaluator;
use Tests\TestCase;

final class SystemHealthEvaluatorTest extends TestCase
{
    private SystemHealthEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new SystemHealthEvaluator();
    }

    public function test_marks_missing_today_traffic_as_critical_after_the_grace_period(): void
    {
        $now = strtotime('2026-07-28 12:00:00 UTC');
        $check = $this->evaluator->evaluateTraffic([
            'now' => $now,
            'today_start' => strtotime('2026-07-28 00:00:00 UTC'),
            'latest_updated_at' => 0,
            'today_rows' => 0,
            'month_total' => 1024,
        ]);

        $this->assertSame('critical', $check['status']);
        $this->assertSame('today_missing', $check['reason']);
    }

    public function test_marks_delayed_traffic_as_warning_before_it_becomes_stale(): void
    {
        $now = 2_000_000_000;
        $check = $this->evaluator->evaluateTraffic([
            'now' => $now,
            'today_start' => $now - 3600,
            'latest_updated_at' => $now - 1200,
            'today_rows' => 12,
            'month_total' => 1024,
        ]);

        $this->assertSame('warning', $check['status']);
        $this->assertSame('delayed', $check['reason']);
    }

    public function test_detects_primary_key_capacity_exhaustion(): void
    {
        $critical = $this->evaluator->evaluateDatabaseCapacity([
            'column_type' => 'int unsigned',
            'auto_increment' => '4200000000',
            'utilization_percent' => 97.78,
        ]);
        $healthy = $this->evaluator->evaluateDatabaseCapacity([
            'column_type' => 'bigint unsigned',
            'auto_increment' => '4200000000',
            'utilization_percent' => 0.00000003,
        ]);

        $this->assertSame('critical', $critical['status']);
        $this->assertSame('nearly_exhausted', $critical['reason']);
        $this->assertSame('healthy', $healthy['status']);
    }

    public function test_queue_failure_and_stopped_states_have_different_severity(): void
    {
        $warning = $this->evaluator->evaluateQueue([
            'available' => true,
            'running' => true,
            'failed_jobs' => 2,
        ]);
        $critical = $this->evaluator->evaluateQueue([
            'available' => true,
            'running' => false,
            'failed_jobs' => 0,
        ]);

        $this->assertSame('warning', $warning['status']);
        $this->assertSame('failed_jobs', $warning['reason']);
        $this->assertSame('critical', $critical['status']);
        $this->assertSame('stopped', $critical['reason']);
    }

    public function test_operation_tasks_distinguish_recent_failures_from_stale_work(): void
    {
        $warning = $this->evaluator->evaluateOperationTasks([
            'available' => true,
            'failed_recent' => 2,
            'stale' => 0,
        ]);
        $critical = $this->evaluator->evaluateOperationTasks([
            'available' => true,
            'failed_recent' => 0,
            'stale' => 1,
        ]);

        $this->assertSame('warning', $warning['status']);
        $this->assertSame('failed_recent', $warning['reason']);
        $this->assertSame('critical', $critical['status']);
        $this->assertSame('stale', $critical['reason']);
    }

    public function test_backup_requires_readiness_and_a_recent_automatic_result(): void
    {
        $now = 2_000_000_000;
        $current = $this->evaluator->evaluateBackup([
            'available' => true,
            'now' => $now,
            'enabled' => true,
            'metadata_ready' => true,
            'backup_path_writable' => true,
            'gzip_ready' => true,
            'latest_status' => 'succeeded',
            'latest_finished_at' => $now - 3600,
        ]);
        $disabled = $this->evaluator->evaluateBackup([
            'available' => true,
            'enabled' => false,
            'metadata_ready' => true,
            'backup_path_writable' => true,
            'gzip_ready' => true,
        ]);
        $failed = $this->evaluator->evaluateBackup([
            'available' => true,
            'enabled' => true,
            'metadata_ready' => true,
            'backup_path_writable' => true,
            'gzip_ready' => true,
            'latest_status' => 'failed',
        ]);

        $this->assertSame('healthy', $current['status']);
        $this->assertSame('current', $current['reason']);
        $this->assertSame('warning', $disabled['status']);
        $this->assertSame('disabled', $disabled['reason']);
        $this->assertSame('critical', $failed['status']);
        $this->assertSame('failed', $failed['reason']);
    }

    public function test_external_services_use_stored_domain_and_proxy_runtime_evidence(): void
    {
        $degraded = $this->evaluator->evaluateExternalServices([
            'available' => true,
            'domains_monitored' => 4,
            'domain_healthy' => 3,
            'domain_unknown' => 1,
        ]);
        $down = $this->evaluator->evaluateExternalServices([
            'available' => true,
            'proxy_enabled' => true,
            'proxy_configured' => 2,
            'proxy_healthy' => 0,
        ]);
        $notConfigured = $this->evaluator->evaluateExternalServices([
            'available' => true,
            'domains_monitored' => 0,
            'proxy_enabled' => false,
        ]);

        $this->assertSame('warning', $degraded['status']);
        $this->assertSame('degraded', $degraded['reason']);
        $this->assertSame('critical', $down['status']);
        $this->assertSame('down', $down['reason']);
        $this->assertSame('healthy', $notConfigured['status']);
        $this->assertSame('not_configured', $notConfigured['reason']);
    }

    public function test_summary_uses_the_highest_check_severity_and_orders_critical_first(): void
    {
        $result = $this->evaluator->evaluate([
            'checked_at' => 123,
            'traffic' => ['available' => false],
            'scheduler' => ['running' => true],
            'queue' => ['available' => true, 'running' => true],
            'log_storage' => ['bytes' => 0],
            'database_capacity' => ['supported' => false],
            'migrations' => ['available' => true, 'pending' => 1, 'applied' => 10, 'total' => 11],
            'operation_tasks' => ['available' => true, 'status' => 'healthy'],
            'backup' => [
                'available' => true,
                'enabled' => true,
                'metadata_ready' => true,
                'backup_path_writable' => true,
                'gzip_ready' => true,
                'latest_status' => 'succeeded',
                'latest_finished_at' => time(),
            ],
            'external_services' => ['available' => true],
        ]);

        $this->assertSame('critical', $result['status']);
        $this->assertSame(1, $result['summary']['critical']);
        $this->assertSame(1, $result['summary']['warning']);
        $this->assertSame(9, array_sum($result['summary']));
        $this->assertSame('traffic', $result['checks'][0]['key']);
    }
}

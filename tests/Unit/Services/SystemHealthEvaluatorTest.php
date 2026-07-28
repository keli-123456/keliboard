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
        ]);

        $this->assertSame('critical', $result['status']);
        $this->assertSame(1, $result['summary']['critical']);
        $this->assertSame(1, $result['summary']['warning']);
        $this->assertSame('traffic', $result['checks'][0]['key']);
    }
}

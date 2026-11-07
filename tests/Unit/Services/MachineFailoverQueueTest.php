<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\MachineFailoverRunJob;
use App\Jobs\MachineProvisionJob;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue;
use Tests\TestCase;

final class MachineFailoverQueueTest extends TestCase
{
    private function horizon(bool $enabled): array
    {
        $key = 'MACHINE_FAILOVER_WORKER_ENABLED';
        $env = $_ENV; $server = $_SERVER;
        try {
            $_ENV[$key] = $_SERVER[$key] = $enabled ? 'true' : 'false';
            return require base_path('config/horizon.php');
        } finally { $_ENV = $env; $_SERVER = $server; }
    }
    public function test_dedicated_queue_cannot_borrow_the_admin_pool(): void
    {
        $job = new MachineFailoverRunJob('synthetic', 1, 'synthetic');
        $queue = require base_path('config/queue.php');
        $this->assertSame('redis_failover', $job->connection); $this->assertSame('machine_failover', $job->queue);
        $this->assertSame(MachineFailoverQueue::JOB_TIMEOUT, $job->timeout);
        $this->assertSame('machine_failover', $queue['connections'][$job->connection]['queue']);
        $this->assertSame(90, $queue['connections'][$job->connection]['retry_after']);
        $this->assertSame('default', $queue['connections'][$job->connection]['connection']);
        $provision = new MachineProvisionJob('synthetic');
        $this->assertSame('redis', $provision->connection); $this->assertSame('admin_operations', $provision->queue);
    }
    public function test_opt_in_preserves_every_existing_pool_without_a_second_master(): void
    {
        $disabled = $this->horizon(false); $enabled = $this->horizon(true);
        foreach (['production', 'local'] as $environment) {
            $before = $disabled['environments'][$environment]; $after = $enabled['environments'][$environment];
            $this->assertSame(['Xboard', 'RiskAI', 'AdminOperations', 'Backup'], array_keys($before));
            $this->assertArrayNotHasKey('MachineFailover', $before);
            $this->assertSame(MachineFailoverQueue::supervisor(), $after['MachineFailover']);
            unset($after['MachineFailover']); $this->assertSame($before, $after);
            foreach ($before as $pool) $this->assertNotContains(MachineFailoverQueue::NAME, $pool['queue']);
        }
        $this->assertSame($disabled['prefix'], $enabled['prefix']); $this->assertSame($disabled['use'], $enabled['use']);
        $this->assertArrayNotHasKey('redis_failover:machine_failover', $disabled['waits']);
        $this->assertSame(120, $enabled['waits']['redis_failover:machine_failover']);
    }
    public function test_legacy_admin_tickets_cannot_execute_native_steps_on_the_old_pool(): void
    {
        foreach ([['redis', 'admin_operations'], ['redis', 'machine_failover'], ['redis_failover', 'admin_operations']] as [$connection, $queue]) {
            $runner = $this->createMock(\App\Services\ServerMachine\Cloud\MachineFailoverRunner::class);
            $runner->expects($this->never())->method('process');
            $delivery = $this->createMock(\Illuminate\Contracts\Queue\Job::class);
            $delivery->method('getConnectionName')->willReturn($connection); $delivery->method('getQueue')->willReturn($queue);
            $job = new MachineFailoverRunJob('synthetic', 1, 'synthetic'); $job->setJob($delivery); $job->handle($runner);
        }
    }
    public function test_only_the_dedicated_delivery_can_advance_native_work(): void
    {
        $runner = $this->createMock(\App\Services\ServerMachine\Cloud\MachineFailoverRunner::class);
        $runner->expects($this->once())->method('process')->with('synthetic', 1, 'synthetic');
        $delivery = $this->createMock(\Illuminate\Contracts\Queue\Job::class);
        $delivery->method('getConnectionName')->willReturn('redis_failover'); $delivery->method('getQueue')->willReturn('machine_failover');
        $job = new MachineFailoverRunJob('synthetic', 1, 'synthetic'); $job->setJob($delivery); $job->handle($runner);
    }
    public function test_worker_limits_fit_the_existing_real_lease_contract(): void
    {
        $pool = MachineFailoverQueue::supervisor();
        $this->assertFalse($pool['balance']); $this->assertSame(1, $pool['minProcesses']); $this->assertSame(1, $pool['maxProcesses']);
        $this->assertSame(128, $pool['memory']); $this->assertSame(100, $pool['maxJobs']); $this->assertSame(900, $pool['maxTime']);
        $this->assertSame(10, $pool['nice']);
        $this->assertSame(1, $pool['tries']); $this->assertSame(80, $pool['timeout']);
        $this->assertLessThan($pool['timeout'], MachineFailoverQueue::JOB_TIMEOUT);
        $this->assertLessThan(MachineFailoverQueue::RETRY_AFTER, $pool['timeout']);
        $this->assertLessThan(120, MachineFailoverQueue::RETRY_AFTER);
        $defaults = require base_path('config/machine_failover.php');
        $this->assertFalse($defaults['runner_enabled']); $this->assertFalse($defaults['allow_replacement']);
        $gate = new \App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate();
        $this->expectException(\App\Services\ServerMachine\Cloud\CloudException::class); $gate->assertAllowed('runner');
    }
}

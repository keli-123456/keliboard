<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Console\Commands\QueueHealth;
use App\Services\QueueConsumerHealth;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class QueueConsumerHealthTest extends TestCase
{
    private string $masterName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->masterName = MasterSupervisor::basename() . '-test';
        config(['app.env' => 'local', 'horizon.defaults' => [], 'horizon.environments.local' => [
            'Xboard' => ['connection' => 'redis', 'queue' => ['send_email', 'order_handle'], 'minProcesses' => 1, 'maxProcesses' => 2],
        ]]);
    }

    public function test_running_master_without_consumers_is_unhealthy(): void
    {
        $this->repositories([]);
        $result = (new QueueConsumerHealth())->snapshot();
        $this->assertFalse($result['healthy']);
        $this->assertSame(['redis:send_email', 'redis:order_handle'], $result['missing_queues']);
    }

    public function test_each_queue_needs_a_consumer_not_just_a_positive_total(): void
    {
        $this->repositories(['redis:order_handle' => 20]);
        $result = (new QueueConsumerHealth())->snapshot();
        $this->assertFalse($result['healthy']);
        $this->assertSame(['redis:send_email'], $result['missing_queues']);
    }

    public function test_running_combined_queue_workers_cover_all_their_queues(): void
    {
        $this->repositories(['redis:send_email,order_handle' => 1]);
        $result = (new QueueConsumerHealth())->snapshot();
        $this->assertTrue($result['healthy']);
        $this->assertSame(1, $result['processes']);
    }

    public function test_paused_and_orphan_supervisors_do_not_count(): void
    {
        $this->repositories(['redis:send_email,order_handle' => 1], 'paused');
        $this->assertFalse((new QueueConsumerHealth())->snapshot()['healthy']);
        $this->repositories(['redis:send_email,order_handle' => 1], 'running', false);
        $this->assertFalse((new QueueConsumerHealth())->snapshot()['healthy']);
    }

    public function test_local_gate_does_not_accept_a_different_hosts_workers(): void
    {
        $this->masterName = 'another-host-test';
        $this->repositories(['redis:send_email,order_handle' => 1]);
        $this->assertTrue((new QueueConsumerHealth())->snapshot()['healthy']);
        $this->assertFalse((new QueueConsumerHealth())->snapshot(true)['healthy']);
    }

    public function test_missing_environment_is_not_a_healthy_empty_plan(): void
    {
        $this->repositories(['redis:send_email,order_handle' => 1]);
        config(['app.env' => 'unknown']);
        $this->assertFalse((new QueueConsumerHealth())->snapshot()['healthy']);
    }

    public function test_environment_patterns_and_disabled_supervisors_are_respected(): void
    {
        $plan = config('horizon.environments.local');
        $plan['disabled'] = ['connection' => 'redis', 'queue' => ['disabled'], 'minProcesses' => 1, 'maxProcesses' => 0];
        config(['app.env' => 'production-eu', 'horizon.environments' => ['production-*' => $plan]]);
        $this->repositories(['redis:send_email,order_handle' => 1]);
        $this->assertTrue((new QueueConsumerHealth())->snapshot()['healthy']);
    }

    public function test_health_command_exit_code_blocks_a_false_success(): void
    {
        $this->repositories([]);
        $command = new QueueHealth();
        $command->setLaravel(app());
        $output = new BufferedOutput();
        $this->assertSame(1, $command->run(new ArrayInput(['--local' => true]), $output));
        $this->assertFalse(json_decode($output->fetch(), true)['healthy']);
        $this->repositories(['redis:send_email,order_handle' => 1]);
        $this->assertSame(0, $command->run(new ArrayInput(['--local' => true]), new BufferedOutput()));
    }

    private function repositories(array $processes, string $status = 'running', bool $attached = true): void
    {
        $name = $this->masterName . ':Xboard';
        $masters = $this->createMock(MasterSupervisorRepository::class);
        $masters->method('all')->willReturn([(object) [
            'name' => $this->masterName, 'status' => 'running', 'supervisors' => $attached ? [$name] : [],
        ]]);
        $supervisors = $this->createMock(SupervisorRepository::class);
        $supervisors->method('all')->willReturn([(object) compact('name', 'status', 'processes')]);
        app()->instance(MasterSupervisorRepository::class, $masters);
        app()->instance(SupervisorRepository::class, $supervisors);
    }
}

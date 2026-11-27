<?php

namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\MachineFailoverOperatorPermit;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue as Queue;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;

class WorkMachineFailoverOperator extends Command
{
    protected $signature = 'machines:work-operator-failover';
    protected $description = 'Run the standalone manual failover queue worker';

    public function handle(Dispatcher $events): int
    {
        MachineFailoverOperatorPermit::requireEnabled();
        // Queue timeout and termination handling must be available in this process.
        if (!function_exists('pcntl_async_signals') || !function_exists('posix_kill')) return self::FAILURE;
        $owner = bin2hex(random_bytes(16)); $lastHeartbeat = 0;
        $events->listen(Looping::class, function (Looping $event) use ($owner, &$lastHeartbeat) {
            if ($event->connectionName !== Queue::CONNECTION || $event->queue !== Queue::OPERATOR_NAME || $lastHeartbeat + 3 > time()) return;
            if (!Cache::put(Queue::OPERATOR_HEARTBEAT, $owner, Queue::OPERATOR_HEARTBEAT_SECONDS))
                throw new \RuntimeException('operator_heartbeat_unavailable');
            $lastHeartbeat = time();
        });
        try {
            return $this->call('queue:work', ['connection' => Queue::CONNECTION, '--queue' => Queue::OPERATOR_NAME,
                '--timeout' => Queue::JOB_TIMEOUT, '--tries' => 1, '--sleep' => 1, '--memory' => 128, '--max-jobs' => 100, '--max-time' => 900]);
        } finally {
            if (Cache::get(Queue::OPERATOR_HEARTBEAT) === $owner) Cache::forget(Queue::OPERATOR_HEARTBEAT);
        }
    }
}

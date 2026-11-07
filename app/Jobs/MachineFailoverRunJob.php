<?php
namespace App\Jobs;

use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class MachineFailoverRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = MachineFailoverQueue::JOB_TIMEOUT;
    public function __construct(public readonly string $execution, public readonly int $generation, public readonly string $ticket)
    {
        $this->onConnection(MachineFailoverQueue::CONNECTION); $this->onQueue(MachineFailoverQueue::NAME);
    }
    public function handle(MachineFailoverRunner $runner): void
    {
        // Old serialized tickets must not execute cloud work on the admin pool; the fenced sweeper recovers them.
        if ($this->job && ($this->job->getConnectionName() !== MachineFailoverQueue::CONNECTION
            || $this->job->getQueue() !== MachineFailoverQueue::NAME)) return;
        $runner->process($this->execution, $this->generation, $this->ticket);
    }
}

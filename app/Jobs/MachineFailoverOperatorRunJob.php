<?php

namespace App\Jobs;

use App\Services\ServerMachine\Cloud\MachineFailoverQueue;
use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class MachineFailoverOperatorRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = MachineFailoverQueue::JOB_TIMEOUT;

    public function __construct(public readonly string $execution, public readonly int $generation, public readonly string $ticket)
    {
        $this->onConnection(MachineFailoverQueue::CONNECTION)->onQueue(MachineFailoverQueue::OPERATOR_NAME);
    }

    public function handle(MachineFailoverRunner $runner): void
    {
        if ($this->job && ($this->job->getConnectionName() !== MachineFailoverQueue::CONNECTION
            || $this->job->getQueue() !== MachineFailoverQueue::OPERATOR_NAME)) return;
        $runner->processOperator($this->execution, $this->generation, $this->ticket);
    }
}

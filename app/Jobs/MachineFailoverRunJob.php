<?php
namespace App\Jobs;

use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class MachineFailoverRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = 75;
    public function __construct(public readonly string $execution, public readonly int $generation, public readonly string $ticket)
    {
        $this->onConnection('redis'); $this->onQueue('admin_operations');
    }
    public function handle(MachineFailoverRunner $runner): void { $runner->process($this->execution, $this->generation, $this->ticket); }
}

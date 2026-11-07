<?php
namespace App\Jobs;

use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class MachineProvisionJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = 60;
    public int $uniqueFor = 90;
    public function __construct(public readonly string $taskId) { $this->onConnection('redis'); $this->onQueue('admin_operations'); }
    public function uniqueId(): string { return $this->taskId; }
    public function handle(MachineProvisionService $service): void { $service->process($this->taskId); }
}

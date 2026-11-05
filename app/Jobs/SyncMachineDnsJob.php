<?php

namespace App\Jobs;

use App\Services\ServerMachine\Dns\MachineDnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncMachineDnsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = 60;
    public int $uniqueFor = 180;
    public function __construct(public readonly int $bindingId, public readonly int $revision)
    {
        $this->onConnection('redis');
        $this->onQueue('admin_operations');
    }
    public function uniqueId(): string { return $this->bindingId . ':' . $this->revision; }
    public function handle(MachineDnsService $service): void { $service->sync($this->bindingId, $this->revision); }
}

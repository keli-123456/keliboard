<?php

namespace App\Jobs;

use App\Services\ServerMachine\MachineRiskEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordMachineRiskEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public int $timeout = 45;
    public function __construct(public readonly array $episode, public readonly ?int $recoveredAt = null)
    {
        $this->onConnection('redis_ai'); $this->onQueue('risk_ai');
    }
    public function handle(MachineRiskEventService $service): void { $service->persist($this->episode, $this->recoveredAt); }
}

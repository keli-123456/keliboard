<?php

namespace App\Jobs;

use App\Models\MachineRiskEvent;
use App\Services\ServerMachine\MachineRiskAnalysis;
use App\Services\ServerMachine\MachineRiskInvestigationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeMachineRiskEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public int $timeout = 60;
    public bool $failOnTimeout = true;
    public function __construct(public readonly int $eventId, public readonly string $attempt)
    {
        $this->onConnection('redis_ai'); $this->onQueue('risk_ai');
    }
    public function handle(MachineRiskAnalysis $service): void { $service->process($this->eventId, $this->attempt); }
    public function failed(?\Throwable $error): void
    {
        if (MachineRiskEvent::query()->whereKey($this->eventId)->where('ai_attempt', $this->attempt)
            ->whereIn('ai_status', ['pending', 'analyzing'])->update(['ai_status' => 'failed', 'ai_error_code' => 'worker_failed', 'ai_finished_at' => time(), 'updated_at' => time()])) {
            app(MachineRiskInvestigationService::class)->notify(MachineRiskEvent::query()->findOrFail($this->eventId), 'report');
        }
    }
}

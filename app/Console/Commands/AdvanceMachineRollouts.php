<?php

namespace App\Console\Commands;

use App\Models\MachineRollout;
use App\Models\MachineRolloutTarget;
use App\Services\ServerMachine\MachineRolloutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdvanceMachineRollouts extends Command
{
    protected $signature = 'machines:advance-rollouts';
    protected $description = 'Evaluate saved health evidence and advance one machine per rollout';

    public function handle(MachineRolloutService $rollouts): int
    {
        if (!Schema::hasTable('v2_machine_rollout') || !Schema::hasTable('v2_machine_rollout_target')) return self::SUCCESS;
        foreach (MachineRollout::whereIn('status', ['running', 'awaiting_gate'])->orderByRaw('COALESCE(last_tick_at, 0)')->limit(50)->pluck('id') as $id) {
            $rollouts->tick($id);
        }
        $cutoff = now()->subDays(30);
        foreach (MachineRollout::whereIn('status', ['completed', 'cancelled'])->where('updated_at', '<', $cutoff)->limit(100)->pluck('id') as $id) {
            DB::transaction(function () use ($id, $cutoff): void {
                $row = MachineRollout::whereKey($id)->lockForUpdate()->first();
                if (!$row || !in_array($row->status, ['completed', 'cancelled'], true) || (int) $row->updated_at >= $cutoff->timestamp) return;
                MachineRolloutTarget::where('rollout_id', $row->id)->delete();
                $row->delete();
            });
        }
        return self::SUCCESS;
    }
}

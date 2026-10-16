<?php

namespace App\Services\ServerMachine;

use App\Models\MachineRolloutTarget;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class MachineUpgradeQueue
{
    public function enqueue(int $id, string $component, string $version, bool $force = false, ?string $rolloutId = null, array $pins = []): ServerMachine
    {
        return DB::transaction(function () use ($id, $component, $version, $force, $rolloutId, $pins) {
            $machine = ServerMachine::query()->lockForUpdate()->find($id);
            if (!$machine || !$machine->is_active) {
                throw new RuntimeException('机器不存在或已停用', 422);
            }
            if (Schema::hasTable('v2_machine_rollout_target')) {
                $reserved = MachineRolloutTarget::where('active_machine_id', $id)->value('rollout_id');
                if ($reserved !== null && $reserved !== $rolloutId) {
                    throw new RuntimeException('机器已加入灰度升级，请先停止对应灰度任务', 409);
                }
            }
            if (!$force && in_array(data_get($machine->upgrade_state, 'status'), ['queued', 'dispatched', 'running'], true)) {
                throw new RuntimeException('该机器已有进行中的升级任务', 409);
            }
            $machine->forceFill(['upgrade_state' => [
                'id' => (string) Str::uuid(), 'status' => 'queued', 'component' => $component,
                'target_version' => $version, 'requested_at' => now()->timestamp, 'updated_at' => now()->timestamp,
                ...($rolloutId ? ['rollout_id' => $rolloutId] : []), ...$pins,
            ]])->save();
            return $machine;
        });
    }
}

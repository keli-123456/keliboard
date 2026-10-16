<?php

namespace App\Services\ServerMachine;

use App\Models\MachineRollout;
use App\Models\MachineRolloutTarget;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Models\ServerMachineRelease;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MachineRolloutService
{
    public function __construct(private MachineRolloutEvidence $evidence, private MachineUpgradeQueue $upgrades, private MachineReleaseDistributionService $distribution) {}

    public function create(int $adminId, array $input): MachineRollout
    {
        try {
        return DB::transaction(function () use ($adminId, $input) {
            $existing = MachineRollout::where('admin_id', $adminId)->where('client_token', $input['client_token'])->first();
            if ($existing) {
                return $existing;
            }
            $release = ServerMachineRelease::find($input['release_id']);
            if (!$release) {
                throw new RuntimeException('请选择面板仓库中的 kelinode-rs 正式版本');
            }
            $pin = $release->only(['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256']);
            $this->release($pin);
            $machines = ServerMachine::whereIn('id', $input['machine_ids'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($machines->count() !== count($input['machine_ids'])) {
                throw new RuntimeException('部分机器已不存在');
            }
            foreach ($machines as $machine) {
                $this->ready($machine);
                if (MachineRolloutTarget::where('active_machine_id', $machine->id)->exists()) {
                    throw new RuntimeException('部分机器已有未结束的灰度任务');
                }
                $sample = $this->current($machine);
                if ($sample['binary_sha256'] === $pin['binary_sha256']) {
                    throw new RuntimeException('部分机器已经运行目标二进制，无需重复升级');
                }
                $this->baseline($machine, $sample);
            }
            $rollout = MachineRollout::create([
                'id' => (string) Str::uuid(), 'admin_id' => $adminId, 'client_token' => $input['client_token'],
                'status' => 'running', 'release' => $pin, 'observe_seconds' => $input['observe_minutes'] * 60,
            ]);
            foreach ($input['machine_ids'] as $position => $id) {
                MachineRolloutTarget::create([
                    'rollout_id' => $rollout->id, 'machine_id' => $id, 'active_machine_id' => $id,
                    'position' => $position, 'name' => $machines[$id]->name, 'status' => 'pending',
                ]);
            }
            return $rollout;
        }, 3);
        } catch (QueryException $error) {
            $existing = MachineRollout::where('admin_id', $adminId)->where('client_token', $input['client_token'])->first();
            if ($existing) return $existing;
            throw new RuntimeException('暂时无法创建灰度任务，请刷新记录后重试');
        }
    }

    public function tick(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $rollout = MachineRollout::lockForUpdate()->find($id);
            if (!$rollout || !in_array($rollout->status, ['running', 'awaiting_gate'], true)) {
                return;
            }
            $rollout->last_tick_at = now()->timestamp;
            $rollout->save();
            $targets = $rollout->targets()->get();
            $machines = ServerMachine::whereIn('id', $targets->pluck('machine_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            try {
                $this->release($rollout->release);
                foreach ($targets as $target) {
                    if ($target->status === 'passed') {
                        $this->monitor($target, $machines[$target->machine_id] ?? null, $rollout);
                    }
                }
                if ($rollout->status === 'awaiting_gate') {
                    return;
                }
                $target = $targets->first(fn ($row) => $row->status !== 'passed');
                if (!$target) {
                    $rollout->update(['status' => 'completed', 'reason' => null]);
                    $rollout->targets()->update(['active_machine_id' => null]);
                    return;
                }
                $machine = $machines[$target->machine_id] ?? null;
                if ($target->status === 'pending') {
                    if (!$machine) throw new RuntimeException('machine_missing');
                    $this->ready($machine);
                    $sample = $this->current($machine);
                    $baseline = $this->baseline($machine, $sample);
                    if ($sample['binary_sha256'] === $rollout->release['binary_sha256']) throw new RuntimeException('already_target');
                    $queued = $this->upgrades->enqueue((int) $machine->id, 'kelinode-rs', $rollout->release['version'], false, $rollout->id, [
                        'expected_archive_sha256' => $rollout->release['sha256'], 'expected_binary_sha256' => $rollout->release['binary_sha256'],
                    ]);
                    $target->update(['status' => 'upgrading', 'upgrade_id' => $queued->upgrade_state['id'], 'baseline' => $baseline, 'samples' => [], 'queued_at' => now()->timestamp]);
                    return;
                }
                if (!$machine) throw new RuntimeException('machine_missing');
                $this->monitor($target, $machine, $rollout);
                if ($target->status === 'passed') {
                    if ($target->position === 0 && $targets->count() > 1) {
                        $rollout->update(['status' => 'awaiting_gate', 'reason' => null]);
                    }
                }
            } catch (RuntimeException $error) {
                if ($error instanceof QueryException) throw $error;
                // Never issue a restart, rollback or subsequent upgrade after a failed gate.
                $reason = preg_match('/^[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'preflight_failed';
                $rollout->update(['status' => 'paused', 'reason' => $reason]);
            }
        });
    }

    private function monitor(MachineRolloutTarget $target, ?ServerMachine $machine, MachineRollout $rollout): void
    {
        if (!$machine || !$machine->is_active) throw new RuntimeException('machine_missing');
        $sample = $this->current($machine);
        $state = $machine->upgrade_state ?? [];
        if (($state['id'] ?? '') !== $target->upgrade_id || ($state['rollout_id'] ?? '') !== $rollout->id) throw new RuntimeException('upgrade_replaced');
        if (($state['status'] ?? '') === 'failed' || ($state['rollback_performed'] ?? false)) throw new RuntimeException('upgrade_failed');
        if (in_array($state['status'] ?? '', ['queued', 'dispatched', 'running'], true)) {
            if (now()->timestamp - $target->queued_at > 900) throw new RuntimeException('upgrade_timeout');
            return;
        }
        if (($state['status'] ?? '') !== 'succeeded') throw new RuntimeException('upgrade_unconfirmed');
        if ($sample['binary_sha256'] !== $rollout->release['binary_sha256']
            || ltrim($sample['version'], 'vV') !== ltrim($rollout->release['version'], 'vV')
            || ($state['running_binary_sha256'] ?? '') !== $rollout->release['binary_sha256']) throw new RuntimeException('binary_mismatch');
        $before = $target->baseline;
        $reference = $before[count($before) - 1];
        if ($sample['session'] === $reference['session']) throw new RuntimeException('process_not_replaced');
        $watch = $target->watch;
        if ($watch && ($sample['session'] !== $watch['session'] || $sample['oom']['scope'] !== $watch['oom']['scope'])) throw new RuntimeException('unexpected_restart');
        $oomBaseline = $watch['oom'] ?? $reference['oom'];
        if ($sample['oom']['scope'] !== $oomBaseline['scope']) throw new RuntimeException('oom_scope_changed');
        if ($sample['oom']['oom'] > $oomBaseline['oom'] || $sample['oom']['oom_kill'] > $oomBaseline['oom_kill']) throw new RuntimeException('oom_observed');
        if ($sample['metrics']['memory_trim_error_total'] > 0 || $sample['metrics']['user_apply_error_total'] > 0) throw new RuntimeException('runtime_errors');
        if ($sample['oom']['oom'] < $oomBaseline['oom'] || $sample['oom']['oom_kill'] < $oomBaseline['oom_kill']) throw new RuntimeException('counter_reset');
        $target->watch = ['session' => $sample['session'], 'oom' => $sample['oom']];
        if (now()->timestamp - $target->queued_at > max(7200, $rollout->observe_seconds + 1800) && $target->status !== 'passed') throw new RuntimeException('insufficient_evidence');
        if ($target->status === 'passed' || $sample['uptime_seconds'] < 600 || $sample['at'] <= (int) $target->last_sample_at) {
            $target->save();
            return;
        }
        $gap = $target->last_sample_at ? $sample['at'] - $target->last_sample_at : 0;
        $target->last_sample_at = $sample['at'];
        $target->status = 'observing';
        $samples = $target->samples ?? [];
        $baselineLast = $before[count($before) - 1];
        $matched = $this->evidence->comparable($baselineLast, $sample);
        if (!$matched || $gap > 180) {
            $samples = [];
            $target->samples = [];
            $target->observing_at = null;
            $target->matched_count = 0;
        }
        if ($matched && ($samples === [] || $sample['at'] - $samples[count($samples) - 1]['at'] >= 60)) {
            $samples[] = $sample;
            $target->samples = array_slice($samples, -5);
            $target->matched_count++;
            $target->observing_at ??= $sample['at'];
            $target->status = 'observing';
            if ($this->evidence->regression($before, $samples)) {
                $target->save();
                throw new RuntimeException('performance_regression');
            }
            if ($target->matched_count >= 3 && $sample['at'] - $target->observing_at >= $rollout->observe_seconds) {
                $target->status = 'passed';
            }
        }
        $target->save();
    }

    private function ready(ServerMachine $machine): void
    {
        if (!$machine->is_active || in_array(data_get($machine->upgrade_state, 'status'), ['queued', 'dispatched', 'running'], true)) throw new RuntimeException('machine_not_ready');
        if (data_get($machine->load_status, 'system.os') !== 'linux' || data_get($machine->load_status, 'system.arch') !== 'x86_64') throw new RuntimeException('platform_unsupported');
        $sample = $this->current($machine);
        if ($sample['uptime_seconds'] < 600) throw new RuntimeException('baseline_warming_up');
    }

    private function current(ServerMachine $machine): array
    {
        $sample = $this->evidence->sample($machine->load_status ?? []);
        if (!$sample) throw new RuntimeException('observation_unsupported');
        if ($sample['at'] > now()->timestamp + 30 || now()->timestamp - $sample['at'] > 300) throw new RuntimeException('report_stale');
        return $sample;
    }

    private function baseline(ServerMachine $machine, array $reference): array
    {
        $samples = [];
        foreach (ServerMachineLoadHistory::where('machine_id', $machine->id)->where('created_at', '>=', now()->subMinutes(15))->orderByDesc('id')->limit(16)->get(['load_status']) as $row) {
            $sample = $this->evidence->sample($row->load_status ?? []);
            if ($sample && $sample['session'] === $reference['session'] && $sample['binary_sha256'] === $reference['binary_sha256']
                && $sample['uptime_seconds'] >= 600 && $sample['at'] <= $reference['at'] && $sample['at'] >= now()->timestamp - 900 && $this->evidence->comparable($reference, $sample)) {
                $samples[$sample['at']] = $sample;
            }
        }
        $samples[$reference['at']] = $reference;
        ksort($samples, SORT_NUMERIC);
        $samples = array_slice(array_values($samples), -5);
        if (count($samples) < 3 || $samples[count($samples) - 1]['at'] - $samples[0]['at'] < 120) throw new RuntimeException('baseline_insufficient');
        return $samples;
    }

    private function release(array $pin): ServerMachineRelease
    {
        if ($this->distribution->source() !== MachineReleaseDistributionService::SOURCE_PANEL || ($pin['component'] ?? '') !== 'kelinode-rs'
            || !preg_match('/^[a-f0-9]{64}$/D', $pin['sha256'] ?? '') || !preg_match('/^[a-f0-9]{64}$/D', $pin['binary_sha256'] ?? '')) throw new RuntimeException('release_unpinned');
        $release = $this->distribution->findLocalRelease('kelinode-rs', $pin['version'], $pin['platform']);
        if (!$release || (int) $release->id !== (int) $pin['id'] || $release->sha256 !== $pin['sha256'] || $release->binary_sha256 !== $pin['binary_sha256']) throw new RuntimeException('release_changed');
        return $release;
    }

    public function continue(MachineRollout $rollout): MachineRollout
    {
        return DB::transaction(function () use ($rollout) {
            $locked = MachineRollout::lockForUpdate()->findOrFail($rollout->id);
            if ($locked->status !== 'awaiting_gate') throw new RuntimeException('只有试升级通过且当前健康的任务可以放行');
            $this->tick($locked->id);
            $locked->refresh();
            if ($locked->status !== 'awaiting_gate') return $locked;
            $locked->update(['status' => 'running']);
            return $locked;
        });
    }

    public function cancel(MachineRollout $rollout): MachineRollout
    {
        return DB::transaction(function () use ($rollout) {
            $locked = MachineRollout::lockForUpdate()->findOrFail($rollout->id);
            if (!in_array($locked->status, ['completed', 'cancelled'], true)) {
                $locked->update(['status' => 'cancelled']);
                $locked->targets()->update(['active_machine_id' => null]);
            }
            return $locked;
        });
    }

    public function serialize(MachineRollout $rollout): array
    {
        return [
            'id' => $rollout->id, 'status' => $rollout->status, 'reason' => $rollout->reason,
            'release' => $rollout->release, 'observe_seconds' => $rollout->observe_seconds,
            'last_tick_at' => $rollout->last_tick_at, 'created_at' => $rollout->created_at,
            'targets' => ($rollout->relationLoaded('targets') ? $rollout->targets : $rollout->targets()->get())->map(fn ($target) => [
                'machine_id' => (int) $target->machine_id, 'name' => $target->name, 'status' => $target->status,
                'position' => (int) $target->position, 'queued_at' => $target->queued_at, 'observing_at' => $target->observing_at,
                'sample_count' => $target->matched_count,
                'baseline_version' => data_get($target->baseline, '0.version'),
                'baseline_sha256' => data_get($target->baseline, '0.binary_sha256'),
                'before' => $this->evidence->summary($target->baseline ?? []),
                'after' => $this->evidence->summary($target->samples ?? []),
            ])->all(),
        ];
    }

    public function restore(MachineRollout $rollout, int $machineId): void
    {
        DB::transaction(function () use ($rollout, $machineId): void {
            $locked = MachineRollout::lockForUpdate()->findOrFail($rollout->id);
            if (!in_array($locked->status, ['paused', 'cancelled', 'completed'], true)) throw new RuntimeException('请先停止灰度任务，再人工回退');
            $target = $locked->targets()->where('machine_id', $machineId)->first();
            $baseline = data_get($target?->baseline, '0');
            if (!$baseline) throw new RuntimeException('这台机器没有可用的升级前基线');
            $release = $this->distribution->findLocalRelease('kelinode-rs', $baseline['version'], MachineReleaseDistributionService::PLATFORM_LINUX_X86_64);
            if (!$release || $release->binary_sha256 !== $baseline['binary_sha256']) throw new RuntimeException('升级前版本不在面板仓库，或文件校验值已变化');
            $this->release($release->only(['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256']));
            $machine = ServerMachine::lockForUpdate()->find($machineId);
            if (!$machine || data_get($machine->load_status, 'runtime.binary_sha256') === $baseline['binary_sha256']) throw new RuntimeException('机器不存在或已经运行基线版本');
            $locked->update(['status' => 'cancelled']);
            $locked->targets()->update(['active_machine_id' => null]);
            $this->upgrades->enqueue($machineId, 'kelinode-rs', $release->version, false, $locked->id, [
                'expected_archive_sha256' => $release->sha256, 'expected_binary_sha256' => $release->binary_sha256,
            ]);
        });
    }
}

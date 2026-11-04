<?php

namespace App\Services\ServerMachine;

use App\Models\MachineRolloutTarget;
use App\Models\ServerMachine;
use App\Services\Node\NodeReportSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class MachineUpgradeQueue
{
    public const ACTIVE = ['queued', 'dispatched', 'running'];

    public function instanceTargets(array $rows, int $now): array
    {
        $targets = [];
        foreach ($rows as $id => $row) {
            $version = data_get($row, 'runtime.version');
            $ip = self::address(data_get($row, 'ip_observation.current.panel_seen'));
            $until = (int) data_get($row, 'runtime.valid_until');
            if (NodeReportSource::installation($id) === null || $ip === null || $until < $now
                || !is_string($version) || !preg_match('/^v?0\.1\.\d+(?:[-+][0-9A-Za-z._-]+)?$/D', $version)
                || version_compare(ltrim($version, 'v'), '0.1.385', '<')) continue;
            $targets[] = ['installation_id' => $id, 'observed_ip' => $ip, 'version' => $version, 'valid_until' => $until];
        }
        return $targets;
    }

    public static function matchesReporter(array $state, ?string $installation, ?string $ip, bool $shared): bool
    {
        if (!array_key_exists('target_installation_id', $state)) return !$shared;
        $address = self::address($ip);
        return $installation !== null && $state['target_installation_id'] === $installation
            && $address !== null && ($state['target_observed_ip'] ?? null) === $address;
    }

    private static function address(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) ? inet_ntop(inet_pton($value)) : null;
    }

    public function enqueue(int $id, string $component, string $version, bool $force = false, ?string $rolloutId = null, array $pins = [], ?string $installationId = null, ?string $observedIp = null): ServerMachine
    {
        return DB::transaction(function () use ($id, $component, $version, $force, $rolloutId, $pins, $installationId, $observedIp) {
            $machine = ServerMachine::query()->lockForUpdate()->find($id);
            if (!$machine || !$machine->is_active) {
                throw new RuntimeException('机器不存在或已停用', 422);
            }
            $shared = app(MachineIdentityGuard::class)->conflicted($id);
            if ($installationId === null && $shared) throw new RuntimeException('机器凭据共用，请选择要升级的实例', 409);
            if (Schema::hasTable('v2_machine_rollout_target')) {
                $reserved = MachineRolloutTarget::where('active_machine_id', $id)->value('rollout_id');
                if ($reserved !== null && $reserved !== $rolloutId) {
                    throw new RuntimeException('机器已加入灰度升级，请先停止对应灰度任务', 409);
                }
            }
            $state = (array) $machine->upgrade_state;
            $expiredInstance = isset($state['target_installation_id']) && ($state['status'] ?? null) === 'queued'
                && ($state['requested_at'] ?? 0) < now()->timestamp - 1200;
            if (in_array($state['status'] ?? null, self::ACTIVE, true) && !$expiredInstance
                && (!$force || $installationId !== null || isset($state['target_installation_id']))) {
                throw new RuntimeException('该机器已有进行中的升级任务', 409);
            }
            if ($installationId !== null) {
                if ($component !== 'kelinode-rs' || $rolloutId !== null) {
                    throw new RuntimeException('按实例升级仅支持 kelinode-rs', 422);
                }
                $rows = app(MachineAddressInventory::class)->snapshots([$machine])[$id] ?? [];
                $target = collect($this->instanceTargets($rows, now()->timestamp))->firstWhere('installation_id', $installationId);
                if (!$target || $target['observed_ip'] !== self::address($observedIp)) {
                    throw new RuntimeException('实例已离线或来源地址变化，请刷新后重试', 409);
                }
                $distribution = app(MachineReleaseDistributionService::class);
                $release = $distribution->source() === MachineReleaseDistributionService::SOURCE_PANEL
                    ? $distribution->findLocalRelease('kelinode-rs', $version, MachineReleaseDistributionService::PLATFORM_LINUX_X86_64)
                    : null;
                if (!$release || !preg_match('/^v?\d+\.\d+\.\d+$/D', $release->version)
                    || !preg_match('/^[0-9a-f]{64}$/D', (string) $release->sha256)
                    || !preg_match('/^[0-9a-f]{64}$/D', (string) $release->binary_sha256)
                    || !version_compare(ltrim($release->version, 'v'), ltrim($target['version'], 'v'), '>')) {
                    throw new RuntimeException('请选择版本仓库中比该实例当前版本更新的 kelinode-rs 版本', 422);
                }
                $version = $release->version;
                $pins = ['target_installation_id' => $installationId, 'target_observed_ip' => $target['observed_ip'], 'target_from_version' => $target['version'],
                    'expected_archive_sha256' => $release->sha256, 'expected_binary_sha256' => $release->binary_sha256];
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

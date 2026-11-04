<?php

namespace App\Services\ServerMachine;

use App\Services\Node\NodeReportSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MachineIdentityGuard
{
    public function observe(int $machineId, Request $request): bool
    {
        return $this->observeInstallation($machineId, $request->header('X-Keli-Instance-Id'));
    }
    public function observeInstallation(int $machineId, mixed $installation): bool
    {
        $id = NodeReportSource::installation($installation);
        if ($id === null) return $this->conflicted($machineId);
        $key = 'machine_installations:' . $machineId;
        $multiple = Cache::lock($key . ':lock', 5)->block(3, function () use ($key, $id): bool {
            $rows = $this->fresh((array) Cache::get($key, []));
            if (isset($rows[$id]) || count($rows) < 8) $rows[$id] = time();
            Cache::put($key, $rows, $this->ttl());
            return count($rows) > 1;
        });
        return $multiple || Cache::has('machine_report_overlap:' . $machineId);
    }

    public function observeNodeOverlap(int $machineId, array $rows): void
    {
        $fresh = array_filter($rows, fn ($row) => ($row['machine_id'] ?? null) === $machineId
            && ($row['last_seen_at'] ?? 0) >= time() - $this->ttl());
        $legacyIp = $fresh['machine:' . $machineId]['reported_ip'] ?? null;
        $stable = array_filter($fresh, fn ($row) => !empty($row['installation_id']));
        // IP is only corroboration of overlapping legacy reports, never an installation identifier.
        $mixed = $legacyIp && array_filter($stable, fn ($row) => !empty($row['reported_ip']) && $row['reported_ip'] !== $legacyIp);
        if (count($stable) > 1 || $mixed) Cache::put('machine_report_overlap:' . $machineId, true, $this->ttl());
    }

    public function conflicted(int $machineId): bool
    {
        return count($this->fresh((array) Cache::get('machine_installations:' . $machineId, []))) > 1
            || Cache::has('machine_report_overlap:' . $machineId);
    }

    public function conflicts(array $machineIds): array
    {
        if (!$machineIds) return [];
        $keys = [];
        foreach ($machineIds as $id) array_push($keys, 'machine_installations:' . $id, 'machine_report_overlap:' . $id);
        $cache = Cache::many($keys);
        $result = [];
        foreach ($machineIds as $id) {
            $result[$id] = !empty($cache['machine_report_overlap:' . $id])
                || count($this->fresh((array) ($cache['machine_installations:' . $id] ?? []))) > 1;
        }
        return $result;
    }
    private function fresh(array $rows): array { return array_filter($rows, fn ($at) => is_numeric($at) && $at >= time() - $this->ttl()); }
    private function ttl(): int { return max(180, (int) admin_setting('server_push_interval', 60) * 3); }
}

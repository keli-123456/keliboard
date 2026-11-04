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
        return Cache::lock($key . ':lock', 5)->block(3, function () use ($key, $id): bool {
            $rows = $this->fresh((array) Cache::get($key, []));
            if (isset($rows[$id]) || count($rows) < 8) $rows[$id] = time();
            Cache::put($key, $rows, $this->ttl());
            return count($rows) > 1;
        });
    }
    public function conflicted(int $machineId): bool
    {
        return count($this->fresh((array) Cache::get('machine_installations:' . $machineId, []))) > 1;
    }
    private function fresh(array $rows): array { return array_filter($rows, fn ($at) => is_numeric($at) && $at >= time() - $this->ttl()); }
    private function ttl(): int { return max(180, (int) admin_setting('server_push_interval', 60) * 3); }
}

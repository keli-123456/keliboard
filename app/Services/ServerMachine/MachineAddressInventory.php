<?php

namespace App\Services\ServerMachine;

use App\Models\ServerMachine;
use App\Services\Node\NodeReportSource;
use Illuminate\Support\Facades\Cache;

class MachineAddressInventory
{
    private const TTL = 86400;
    private const LIMIT = 8;

    private function key(ServerMachine $machine): string
    {
        return 'machine_addresses:' . $machine->id . ':' . hash('sha256', (string) $machine->token);
    }

    public function record(ServerMachine $machine, string $installation, ?array $status, int $now, array $runtime = [], ?array $probe = null, mixed $net = null, ?array $tick = null): void
    {
        $key = $this->key($machine);
        try {
            $lock = Cache::lock($key . ':lock', 3);
            if (!$lock->get()) return;
            try {
                $rows = (array) Cache::get($key, []);
                $previous = $rows[$installation] ?? [];
                if ($status === null && !$previous) return;
                $next = $status === null ? $previous : $this->group($installation, $status, $now, $previous) + $previous;
                if ($status !== null) {
                    $next['runtime'] = $runtime;
                    if ($sample = $this->network($net, $now, false)) $next['network'] = $sample;
                } elseif ($tick !== null) {
                    $fast = $previous['fast_network'] ?? [];
                    if (($fast['session'] ?? null) === $tick['session'] && ($fast['sequence'] ?? 0) >= $tick['sequence']) return;
                    // An accepted heartbeat without a rate does not refresh the last measurement.
                    $next['fast_network'] = array_replace($this->network($net, $now, true) ?? $fast, $tick);
                }
                $oldProbe = $previous['entry_probe'] ?? null;
                if ($probe !== null && ($oldProbe === null || $probe['revision'] !== $oldProbe['revision']
                    || ($probe['state'] === 'ready' && $probe['checked_at'] > $oldProbe['checked_at']))) {
                    $next['entry_probe'] = $probe;
                    $next['entry_probe_updated_at'] = $now;
                }
                $rows[$installation] = $next;
                uasort($rows, fn ($a, $b) => $b['at'] <=> $a['at']);
                Cache::put($key, array_slice($rows, 0, self::LIMIT, true), self::TTL);
            } finally {
                $lock->release();
            }
        } catch (\Throwable) {
            // Address presentation must never interrupt status or configuration reporting.
        }
    }

    public function snapshots(iterable $machines, bool $strict = false): array
    {
        $keys = [];
        foreach ($machines as $machine) $keys[$machine->id] = $this->key($machine);
        if (!$keys) return [];
        try { $cache = Cache::many(array_values($keys)); } catch (\Throwable $error) {
            if ($strict) throw $error;
            return [];
        }
        return array_map(fn ($key) => (array) ($cache[$key] ?? []), $keys);
    }

    public function probes(array $rows, int $now): array
    {
        $probe = app(MachineEntryProbe::class);
        $results = [];
        foreach ($rows as $id => $row) {
            if (isset($row['entry_probe']) && ($row['at'] ?? 0) >= $now - self::TTL) {
                $results[$id] = $probe->snapshot($row + ['entry_probe_installation_id' => $id], $now);
            }
        }
        return $results;
    }

    public function rates(array $rows, int $now): array
    {
        $rates = [];
        foreach ($rows as $id => $row) {
            $full = $row['network'] ?? [];
            $fast = $row['fast_network'] ?? [];
            $sample = ($fast['at'] ?? 0) >= ($full['at'] ?? 0) ? $fast : $full;
            if (($sample['valid_until'] ?? 0) >= $now && (isset($sample['rx']) || isset($sample['tx']))) {
                $rates[$id] = array_intersect_key($sample, ['rx' => true, 'tx' => true, 'fresh_until' => true, 'valid_until' => true]);
            }
        }
        return $rates;
    }

    private function network(mixed $net, int $now, bool $fast): ?array
    {
        $sample = [];
        foreach (['rx', 'tx'] as $direction) {
            $value = is_array($net) ? ($net[$direction . '_rate'] ?? null) : null;
            if (is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 1e15) {
                $sample[$direction] = (float) $value;
            }
        }
        return $sample ? $sample + ['at' => $now, 'fresh_until' => $now + ($fast ? 15 : 90), 'valid_until' => $now + ($fast ? 60 : 300)] : null;
    }

    public function groups(array $records, array $reporters, array $status, bool $shared, int $now): array
    {
        $groups = array_filter($records, fn ($row) => ($row['at'] ?? 0) >= $now - self::TTL);
        if (!$shared && !$groups && !empty($status['ip'])) {
            $id = NodeReportSource::installation($status['entry_probe_installation_id'] ?? null);
            $groups[$id ?? 'legacy'] = $this->group($id, (array) $status['ip'], (int) ($status['updated_at'] ?? 0), $status);
        }
        foreach ($reporters as $reporter) {
            $id = NodeReportSource::installation($reporter['installation_id'] ?? null);
            $key = $id ?? 'legacy';
            $group = $groups[$key] ?? ['installation_id' => $id, 'at' => 0, 'ips' => [], 'ip_observation' => []];
            $ip = $this->literal($reporter['reported_ip'] ?? null);
            if ($ip && count($group['ips']) < self::LIMIT) $group['ips'][$ip] ??= ['ip' => $ip, 'observed' => true];
            $group['at'] = max($group['at'], (int) ($reporter['last_seen_at'] ?? 0));
            $groups[$key] = $group;
        }
        $groups = array_filter($groups, fn ($row) => !empty($row['ips']));
        // Prefer a recent identified reporter; never synthesize a dual-stack pair across reporters.
        uasort($groups, fn ($a, $b) => ($b['at'] >= $now - 300) <=> ($a['at'] >= $now - 300)
            ?: ($b['installation_id'] !== null) <=> ($a['installation_id'] !== null) ?: $b['at'] <=> $a['at']);
        return array_values(array_map(function ($row): array {
            $group = ['installation_id' => $row['installation_id'], 'ips' => array_values($row['ips']),
                'changes' => $row['ip_observation']['changes'] ?? [], 'last_seen_at' => $row['at']];
            if (isset($row['runtime'])) $group['runtime'] = $row['runtime'];
            return $group;
        }, array_slice($groups, 0, self::LIMIT, true)));
    }

    private function group(?string $installation, array $status, int $now, array $previous): array
    {
        $ips = $fields = [];
        foreach (['public_ipv4', 'public_ipv6'] as $field) {
            $ip = MachineEntryProbe::publicIp($status[$field] ?? null);
            if ($ip && str_contains($ip, ':') === ($field === 'public_ipv6')) {
                $fields[$field] = $ip;
                $ips[$ip] = ['ip' => $ip, 'observed' => false];
            }
        }
        foreach (array_slice((array) ($status['local'] ?? []), 0, 24) as $value) {
            if (($ip = MachineEntryProbe::publicIp($value)) !== null && count($ips) < self::LIMIT - 1) $ips[$ip] = ['ip' => $ip, 'observed' => false];
        }
        if ($observed = $this->literal($status['panel_seen'] ?? null)) {
            $fields['panel_seen'] = $observed;
            $ips[$observed] ??= ['ip' => $observed, 'observed' => true];
        }
        return ['installation_id' => $installation, 'at' => $now, 'ips' => $ips,
            'ip_observation' => app(MachineIpHistory::class)->observe($previous, $fields, $now)];
    }

    private function literal(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) ? inet_ntop(inet_pton($value)) : null;
    }
}

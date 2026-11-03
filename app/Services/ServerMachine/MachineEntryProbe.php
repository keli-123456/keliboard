<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use Symfony\Component\HttpFoundation\IpUtils;

/** Operator-defined outbound ICMP policy, not an independent censorship diagnosis. */
class MachineEntryProbe
{
    public static function publicIp(mixed $value): ?string
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
        $blocked = ['0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24',
            '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '::ffff:0:0/96', '64:ff9b::/96', '2001::/23', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8'];
        if (IpUtils::checkIp($value, $blocked)) return null;
        if (str_contains($value, ':') && !IpUtils::checkIp($value, '2000::/3')) return null;
        return inet_ntop(inet_pton($value)) ?: null;
    }

    public function configuration(bool $active = true): array
    {
        $raw = admin_setting('server_entry_probe_targets', []);
        $targets = [];
        $valid = is_array($raw) && count($raw) <= 8;
        foreach (is_array($raw) ? array_slice($raw, 0, 8) : [] as $value) {
            $ip = self::publicIp($value);
            if ($ip === null) { $valid = false; break; }
            $targets[$ip] = ['id' => substr(hash('sha256', $ip), 0, 16), 'ip' => $ip];
        }
        $config = ['schema' => 1, 'enabled' => $active && $valid && $targets !== [] && in_array(admin_setting('server_entry_probe_enable', false), [true, 1, '1'], true),
            'interval_seconds' => max(5, min(600, (int) admin_setting('server_entry_probe_interval', 120))),
            'timeout_seconds' => 2, 'targets' => $valid ? array_values($targets) : []];
        $config['revision'] = hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
        return $config;
    }

    public function normalizeReport(mixed $report): ?array
    {
        if (!is_array($report) || ($report['schema'] ?? null) !== 1 || !is_string($report['revision'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $report['revision']) || !is_int($report['checked_at'] ?? null)
            || !in_array($report['state'] ?? null, ['ready', 'pending', 'expired', 'error'], true)
            || !is_array($report['targets'] ?? null) || count($report['targets']) > 8) return null;
        $rows = []; $ids = [];
        foreach ($report['targets'] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match('/^[a-f0-9]{16}$/D', $row['id'])
                || isset($ids[$row['id']]) || !in_array($row['status'] ?? null, ['reachable', 'unreachable', 'error'], true)) return null;
            $ids[$row['id']] = true;
            $rows[] = ['id' => $row['id'], 'status' => $row['status']];
        }
        if (array_key_exists('consecutive_failures', $report)
            && (!is_int($report['consecutive_failures']) || $report['consecutive_failures'] < 0 || $report['consecutive_failures'] > 2)) return null;
        return (array_key_exists('consecutive_failures', $report) ? ['consecutive_failures' => $report['consecutive_failures']] : [])
            + ['schema' => 1, 'revision' => $report['revision'], 'checked_at' => $report['checked_at'],
            'state' => $report['state'], 'targets' => $rows];
    }

    public function snapshot(array $status, int $now): array
    {
        $config = $this->configuration(data_get($status, 'machine.is_active', true) !== false);
        $result = ['source' => 'agent_outbound_icmp', 'criterion' => 'any_target_success', 'observed_at' => $now,
            'status' => $config['enabled'] ? 'unknown' : ($config['targets'] === [] ? 'unconfigured' : 'disabled'),
            'blocked_by_policy' => null, 'checked_at' => null, 'configured_targets' => count($config['targets']),
            'reachable_targets' => 0, 'unreachable_targets' => 0, 'unknown_targets' => count($config['targets']),
            'valid_until' => null, 'consecutive_failures' => null, 'results' => array_map(fn ($target) => $target + ['status' => 'unknown'], $config['targets']),
            'independent_vantages' => 0];
        if (!$config['enabled']) return $result;
        $report = $this->normalizeReport($status['entry_probe'] ?? null);
        $at = $status['entry_probe_updated_at'] ?? $status['updated_at'] ?? null;
        if (!$report || $report['revision'] !== $config['revision'] || $report['state'] !== 'ready'
            || !is_int($at) || $at > $now + 30 || $at < $now - 300
            || $report['checked_at'] < $now - min(900, $config['interval_seconds'] * 2 + 30)
            || $report['checked_at'] > $now + 30) return $result;
        $expected = array_column($config['targets'], 'id');
        $reachable = $unreachable = 0;
        foreach ($report['targets'] as $row) {
            if (!in_array($row['id'], $expected, true)) return $result;
            if ($row['status'] === 'reachable') $reachable++;
            if ($row['status'] === 'unreachable') $unreachable++;
        }
        $byId = array_column($report['targets'], 'status', 'id');
        $result['results'] = array_map(fn ($target) => $target + ['status' => $byId[$target['id']] ?? 'unknown'], $config['targets']);
        $result['checked_at'] = $report['checked_at'];
        $result['consecutive_failures'] = $report['consecutive_failures'] ?? null;
        $result['valid_until'] = min($at + 300, $report['checked_at'] + min(900, $config['interval_seconds'] * 2 + 30));
        $result['reachable_targets'] = $reachable;
        $result['unreachable_targets'] = $unreachable;
        $result['unknown_targets'] = count($expected) - $reachable - $unreachable;
        if ($reachable > 0) {
            $result['status'] = 'reachable'; $result['blocked_by_policy'] = false;
        } elseif ($unreachable === count($expected) && ($report['consecutive_failures'] ?? 2) >= 2) {
            $result['status'] = 'suspected_blocked'; $result['blocked_by_policy'] = true;
        }
        return $result;
    }
}

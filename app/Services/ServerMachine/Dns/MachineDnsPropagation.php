<?php
namespace App\Services\ServerMachine\Dns;

use App\Services\ServerMachine\Cloud\CloudException;

// Observations describe selected recursive resolvers, never global propagation or execution authority.
class MachineDnsPropagation
{
    public const RESOLVERS = ['cloudflare', 'google'];
    public function evaluate(array $plan, array $observations, int $now): array
    {
        $name = self::hostname($plan['hostname'] ?? null); $changed = $plan['changed_at'] ?? null;
        if (!is_int($changed) || $changed < 1 || $changed > $now || !is_array($plan['expected'] ?? null)
            || !$plan['expected'] || array_diff(array_keys($plan['expected']), ['A', 'AAAA']) || count($observations) > 4
            || !array_is_list($observations) || !is_array($plan['previous_ttl'] ?? []) || !is_array($plan['lines'] ?? null) || !array_is_list($plan['lines']) || !$plan['lines']
            || array_filter($plan['lines'], fn ($line) => !is_string($line) || !in_array($line, ['default', 'telecom', 'unicom', 'mobile', 'edu', 'oversea'], true))) throw new CloudException('invalid_dns_propagation');
        foreach ($observations as $o) if (!is_array($o) || !in_array($o['resolver'] ?? null, self::RESOLVERS, true)
            || !is_string($o['type'] ?? null) || !array_key_exists($o['type'], $plan['expected'])) throw new CloudException('invalid_dns_propagation');
        $wait = $plan['wait_until'] ?? $changed;
        if (!is_int($wait) || $wait < $changed || $wait > $changed + 172800) throw new CloudException('invalid_dns_propagation');
        $known = true; $expected = [];
        foreach ($plan['expected'] as $type => $addresses) {
            if (!is_array($addresses) || !array_is_list($addresses) || count($addresses) > 150) throw new CloudException('invalid_dns_propagation');
            $expected[$type] = $this->addresses($addresses, $type);
            $ttl = $plan['previous_ttl'][$type] ?? $plan['negative_ttl'] ?? null;
            if (!$this->ttl($ttl)) $known = false; else $wait = max($wait, $changed + $ttl);
        }
        $rows = []; $matches = true; $unknown = false;
        foreach (self::RESOLVERS as $resolver) foreach ($expected as $type => $addresses) {
            $found = array_values(array_filter($observations, fn ($o) => is_array($o) && ($o['resolver'] ?? null) === $resolver && ($o['type'] ?? null) === $type));
            $row = count($found) === 1 ? $this->observation($found[0], $name, $type, $addresses, $changed, $now) : ['state' => 'invalid', 'addresses' => [], 'ttl' => null, 'checked_at' => null];
            $row = ['resolver' => $resolver, 'type' => $type] + $row; $rows[] = $row;
            $matches = $matches && $row['state'] === 'match';
            $unknown = $unknown || in_array($row['state'], ['invalid', 'timeout', 'dns_error', 'cname'], true);
            if (in_array($row['state'], ['mismatch', 'nxdomain', 'nodata'], true) && $row['ttl'] !== null)
                $wait = max($wait, $row['checked_at'] + $row['ttl']);
        }
        $partial = array_diff($plan['lines'], ['default']) !== [];
        $elapsed = $known && $now >= $wait;
        $state = !$known ? 'baseline_required' : ($unknown ? 'unknown' : (!$elapsed ? 'waiting_ttl' : (!$matches ? 'mismatch' : ($partial ? 'partial_scope' : 'converged'))));
        return ['schema' => 1, 'state' => $state, 'scope' => 'selected_public_resolvers', 'hostname' => $name,
            'checked_at' => $now, 'valid_until' => $now + 60, 'changed_at' => $changed, 'wait_until' => $wait,
            'ttl_elapsed' => $elapsed, 'remaining_seconds' => $known ? max(0, $wait - $now) : null,
            'selected_resolvers_match' => $matches, 'unobserved_lines' => array_values(array_diff($plan['lines'], ['default'])),
            'propagation_verified' => false, 'global_propagation_verified' => false, 'rows' => $rows];
    }

    public static function hostname(mixed $name): string
    {
        if (!is_string($name)) throw new CloudException('invalid_dns_propagation');
        $name = strtolower(str_ends_with($name, '.') ? substr($name, 0, -1) : $name);
        if (strlen($name) > 253 || !str_contains($name, '.') || filter_var($name, FILTER_VALIDATE_IP)
            || !filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) throw new CloudException('invalid_dns_propagation');
        return $name;
    }
    private function observation(array $o, string $name, string $type, array $expected, int $changed, int $now): array
    {
        $invalid = ['state' => 'invalid', 'addresses' => [], 'ttl' => null, 'checked_at' => null];
        $at = $o['checked_at'] ?? null;
        if (!is_int($at) || $at < $changed || $at > $now || $at < $now - 60) return $invalid;
        if (($o['status'] ?? null) === 'timeout') return array_replace($invalid, ['state' => 'timeout', 'checked_at' => $at]);
        $b = $o['body'] ?? null; $number = $type === 'A' ? 1 : 28;
        if (($o['status'] ?? null) !== 'ok' || !is_array($b) || !is_int($b['Status'] ?? null) || ($b['TC'] ?? null) !== false
            || ($b['CD'] ?? false) !== false || !is_array($b['Question'] ?? null) || !array_is_list($b['Question']) || count($b['Question']) !== 1
            || !is_array($b['Question'][0]) || !is_string($b['Question'][0]['name'] ?? null)
            || strtolower(rtrim($b['Question'][0]['name'], '.')) !== $name || ($b['Question'][0]['type'] ?? null) !== $number) return $invalid;
        if (!in_array($b['Status'], [0, 3], true)) return array_replace($invalid, ['state' => 'dns_error', 'checked_at' => $at]);
        $answer = $b['Answer'] ?? []; $authority = $b['Authority'] ?? [];
        if (!is_array($answer) || !array_is_list($answer) || count($answer) > 150 || !is_array($authority) || !array_is_list($authority) || count($authority) > 10) return $invalid;
        $values = []; $ttl = 0;
        foreach ($answer as $r) {
            if (!is_array($r) || !is_int($r['type'] ?? null)) return $invalid;
            if ($r['type'] === 5) return array_replace($invalid, ['state' => 'cname', 'checked_at' => $at]);
            if ($r['type'] !== $number) { if (in_array($r['type'], [1, 28], true)) return $invalid; continue; }
            if (!is_string($r['name'] ?? null) || strtolower(rtrim($r['name'], '.')) !== $name || !$this->ttl($r['TTL'] ?? null)
                || !is_string($r['data'] ?? null) || !filter_var($r['data'], FILTER_VALIDATE_IP, $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) return $invalid;
            $values[] = inet_ntop(inet_pton($r['data'])); $ttl = max($ttl, $r['TTL']);
        }
        if ($b['Status'] === 3 && $values) return $invalid;
        $values = array_values(array_unique($values)); sort($values);
        if (!$values) {
            $ttl = $this->negativeTtl($authority, $name);
            $state = !$expected && $ttl !== null ? 'match' : ($b['Status'] === 3 ? 'nxdomain' : 'nodata');
        } else $state = $b['Status'] === 0 && $values === $expected ? 'match' : 'mismatch';
        return ['state' => $state, 'addresses' => $values, 'ttl' => $ttl, 'checked_at' => $at];
    }
    private function negativeTtl(array $authority, string $name): ?int
    {
        $values = [];
        foreach ($authority as $r) {
            if (!is_array($r) || ($r['type'] ?? null) !== 6 || !is_string($r['data'] ?? null) || strlen($r['data']) > 1024
                || !is_string($r['name'] ?? null) || !$this->ttl($r['TTL'] ?? null)) continue;
            $zone = strtolower(rtrim($r['name'], '.'));
            if ($zone === '' || $name !== $zone && !str_ends_with($name, '.' . $zone)) continue;
            $parts = preg_split('/\s+/', trim($r['data'])); $minimum = $parts[6] ?? null;
            if (count($parts) !== 7 || !ctype_digit($minimum ?? '') || !$this->ttl((int) $minimum)) continue;
            $values[] = min($r['TTL'], (int) $minimum);
        }
        return count($values) === 1 ? $values[0] : null;
    }
    private function addresses(array $addresses, string $type): array
    {
        $values = [];
        foreach ($addresses as $value) {
            if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP, $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) throw new CloudException('invalid_dns_propagation');
            $values[] = inet_ntop(inet_pton($value));
        }
        $values = array_values(array_unique($values)); sort($values); return $values;
    }
    private function ttl(mixed $value): bool { return is_int($value) && $value >= 0 && $value <= 86400; }
}

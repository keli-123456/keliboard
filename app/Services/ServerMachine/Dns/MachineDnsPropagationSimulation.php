<?php
namespace App\Services\ServerMachine\Dns;

use App\Services\ServerMachine\Cloud\CloudException;

// A separate recursive-cache fixture. It cannot call the provider or the public HTTP reader.
class MachineDnsPropagationSimulation
{
    public const SCENARIOS = ['ttl_wait', 'converged', 'old_cache', 'nxdomain', 'timeout', 'mixed', 'cname', 'servfail'];
    public function check(array $payload, string $scenario, ?array $previous = null): array
    {
        if (!in_array($scenario, self::SCENARIOS, true)) throw new CloudException('invalid_dns_propagation');
        $set = $payload['snapshot']['set']; $types = array_values(array_unique(array_column($payload['snapshot']['slots'], 'type')));
        $expected = []; $ttl = [];
        foreach ($types as $type) {
            $records = array_values(array_filter($payload['plan']['expected'], fn ($r) => $r['line'] === 'default' && $r['type'] === $type));
            $initial = array_values(array_filter($payload['initial'], fn ($r) => $r['line'] === 'default' && $r['type'] === $type));
            $expected[$type] = array_values(array_unique(array_column($records, 'content')));
            $ttl[$type] = $initial ? max(array_column($initial, 'ttl')) : null;
        }
        $lines = array_values(array_unique(array_column($payload['snapshot']['slots'], 'line')));
        if (!in_array('default', $lines, true)) throw new CloudException('dns_propagation_scope_unobservable');
        $changed = $previous['changed_at'] ?? time();
        // Negative-cache lifetime is explicit synthetic SOA evidence, never an assumed live baseline.
        $plan = ['hostname' => $set['hostname'], 'expected' => $expected, 'previous_ttl' => $ttl, 'negative_ttl' => 300,
            'changed_at' => $changed, 'wait_until' => $previous['wait_until'] ?? $changed, 'lines' => $lines];
        $deadline = max($changed + max(array_map(fn ($v) => $v ?? 300, $ttl)), $plan['wait_until']);
        $now = max($previous['checked_at'] ?? $changed, $scenario === 'ttl_wait' ? $changed : $deadline + 1);
        $observations = [];
        foreach (MachineDnsPropagation::RESOLVERS as $resolver) foreach ($expected as $type => $addresses) {
            $number = $type === 'A' ? 1 : 28;
            $body = ['Status' => 0, 'TC' => false, 'CD' => false, 'Question' => [['name' => $set['hostname'] . '.', 'type' => $number]],
                'Answer' => array_map(fn ($value) => ['name' => $set['hostname'] . '.', 'type' => $number, 'TTL' => $set['ttl'], 'data' => $value], $addresses)];
            if ($scenario === 'old_cache' || $scenario === 'mixed' && $resolver === 'google')
                $body['Answer'] = [['name' => $set['hostname'] . '.', 'type' => $number, 'TTL' => 120, 'data' => $type === 'A' ? '203.0.113.251' : '2001:db8:ffff::1']];
            if ($scenario === 'nxdomain' || !$addresses) {
                $body['Status'] = $scenario === 'nxdomain' ? 3 : 0; $body['Answer'] = [];
                $body['Authority'] = [['name' => $set['hostname'], 'type' => 6, 'TTL' => 300, 'data' => 'ns.example.test. hostmaster.example.test. 1 3600 600 86400 300']];
            }
            if ($scenario === 'cname') $body['Answer'] = [['name' => $set['hostname'], 'type' => 5, 'TTL' => 60, 'data' => 'unrelated.example.test']];
            if ($scenario === 'servfail') { $body['Status'] = 2; unset($body['Answer']); }
            $observations[] = ['resolver' => $resolver, 'type' => $type, 'checked_at' => $now,
                'status' => $scenario === 'timeout' ? 'timeout' : 'ok', 'body' => $body];
        }
        return ['mode' => 'simulation', 'evidence_origin' => 'synthetic', 'scenario' => $scenario, 'network_queries' => 0,
            'virtual_wait_seconds' => $now - $changed] + app(MachineDnsPropagation::class)->evaluate($plan, $observations, $now);
    }
}

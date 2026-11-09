<?php
namespace App\Services\ServerMachine\Dns;

use App\Models\MachineDnsSetFixture as Fixture;
use App\Models\MachineDnsSetRuntime as Runtime;
use App\Services\ServerMachine\Cloud\CloudException;
use Illuminate\Support\Facades\DB;

// The simulated provider has its own durable state, separate from the executor journal.
class MachineDnsRecordSetSimulationTransport implements MachineDnsRecordSetTransport
{
    public function __construct(private MachineDnsRecordSetProvider $provider) {}
    public function seed(string $run, array $set, array $records, string $scenario): void
    {
        Fixture::query()->create(['run_id' => $run, 'payload' => ['scope' => $this->hash($set), 'records' => $records,
            'scenario' => $scenario, 'calls' => [], 'writes' => 0, 'consumed' => [], 'clock_offset' => 0]]);
    }
    public function inventory(string $run, array $set): array
    {
        $row = $this->row($run, $set); $p = $row->payload; $p['calls'][] = ['kind' => 'inventory'];
        if ($p['scenario'] === 'record_changed' && empty($p['consumed']['edit'])) {
            $p['consumed']['edit'] = true; $p['records'][0]['content'] = $p['records'][0]['type'] === 'A' ? '203.0.113.250' : '2001:db8:ffff::1';
        }
        $limited = $p['scenario'] === 'readback_rate_limited' && $p['writes'] > 0 && empty($p['consumed']['limit']);
        if ($limited) $p['consumed']['limit'] = true;
        $row->payload = $p; $row->save();
        if ($limited) throw new DnsRequestFailure('provider_rate_limited', 120);
        return $this->normalize($set, $p['records']);
    }
    public function write(string $run, array $set, array $action, ?string $knownId = null): string
    {
        $row = $this->row($run, $set); $p = $row->payload;
        $wire = $this->provider->mutation($set, $action, $knownId); $p['calls'][] = ['kind' => $action['kind'], 'wire' => $wire];
        $reason = null; $unknown = false; $apply = true;
        if ($p['scenario'] === 'permission_denied') { $reason = 'provider_forbidden'; $apply = false; }
        if ($p['scenario'] === 'partial_failure' && $p['writes'] >= 1) { $reason = 'dns_partial_failure'; $apply = false; }
        if (empty($p['consumed']['unknown']) && (in_array($p['scenario'], ['unknown_applied', 'unknown_unresolved', 'create_unknown'], true)
            || $p['scenario'] === 'claim_unknown' && $action['kind'] === 'claim')) {
            $p['consumed']['unknown'] = true; $unknown = true; $apply = $p['scenario'] !== 'unknown_unresolved';
        }
        $id = $knownId ?? $action['before']['id'] ?? ($set['provider'] === 'cloudflare'
            ? substr(hash('sha256', $run . ':' . $action['id'] . ':' . count($p['calls'])), 0, 32)
            : '90000000000000000000' . str_pad((string) count($p['calls']), 5, '0', STR_PAD_LEFT));
        if ($apply) {
            $index = $this->index($p['records'], $id);
            if ($action['kind'] === 'delete') { if ($index !== null) array_splice($p['records'], $index, 1); }
            elseif ($action['kind'] === 'claim') {
                if ($index === null) throw new CloudException('dns_ownership_changed');
                $p['records'][$index]['marker'] = $action['after']['marker'];
            } else {
                $record = ['id' => $id] + $action['after'];
                if ($action['kind'] === 'create' && $set['provider'] === 'aliyun') $record['marker'] = '';
                if ($index === null) $p['records'][] = $record; else $p['records'][$index] = $record;
            }
            $p['writes']++;
        }
        $row->payload = $p; $row->save();
        if ($reason) throw new DnsException($reason);
        if ($unknown) throw new DnsRequestFailure('provider_unavailable');
        return $id;
    }
    public function elapse(string $run, int $seconds): void
    {
        if ($seconds < 0 || $seconds > 86400) throw new CloudException('invalid_dns_simulation');
        $row = Fixture::query()->findOrFail($run); $p = $row->payload; $p['clock_offset'] += $seconds; $row->payload = $p; $row->save();
    }
    public function metrics(string $run): array { return Fixture::query()->findOrFail($run)->payload; }
    private function row(string $run, array $set): Fixture
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('Provider I/O must not run inside a database transaction.');
        if (Runtime::query()->find($run)?->mode !== 'simulation') throw new CloudException('dns_driver_mismatch');
        $row = Fixture::query()->findOrFail($run);
        if (!hash_equals($row->payload['scope'], $this->hash($set))) throw new CloudException('dns_context_changed');
        return $row;
    }
    private function normalize(array $set, array $records): array
    {
        $rows = array_map(function ($r) use ($set) {
            if ($set['provider'] === 'cloudflare') return ['id' => $r['id'], 'name' => $r['hostname'], 'type' => $r['type'], 'content' => $r['content'],
                'ttl' => $r['ttl'], 'comment' => $r['marker'], 'proxied' => $r['proxied'], 'locked' => $r['locked']];
            return ['RecordId' => $r['id'], 'DomainName' => $set['zone'], 'RR' => $set['hostname'] === $set['zone'] ? '@' : substr($set['hostname'], 0, -strlen('.' . $set['zone'])),
                'Type' => $r['type'], 'Value' => $r['content'], 'TTL' => $r['ttl'], 'Line' => $r['line'], 'Remark' => $r['marker'], 'Locked' => $r['locked'], 'Status' => 'ENABLE'];
        }, $records);
        $pages = [];
        foreach (array_chunk($rows, 50) ?: [[]] as $index => $chunk) $pages[] = $set['provider'] === 'cloudflare'
            ? ['success' => true, 'result' => $chunk, 'result_info' => ['page' => $index + 1, 'per_page' => 50, 'total_count' => count($rows), 'total_pages' => max(1, (int) ceil(count($rows) / 50))]]
            : ['PageNumber' => $index + 1, 'PageSize' => 50, 'TotalCount' => count($rows), 'DomainRecords' => ['Record' => $chunk]];
        return $this->provider->inventory($set, $pages);
    }
    private function index(array $records, string $id): ?int { foreach ($records as $i => $r) if ($r['id'] === $id) return $i; return null; }
    private function hash(array $set): string { return hash('sha256', json_encode($set, JSON_THROW_ON_ERROR)); }
}

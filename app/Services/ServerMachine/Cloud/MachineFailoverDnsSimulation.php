<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverDnsRun as Run;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetPlanner;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Persistent rehearsal only. There is intentionally no cloud, DNS, SSH or queue transport.
class MachineFailoverDnsSimulation
{
    public const SCENARIOS = ['replace', 'bootstrap', 'detach', 'restore', 'source_recovered', 'missing_ipv6', 'foreign_record',
        'ownership_changed', 'duplicate_record', 'inventory_incomplete', 'record_changed', 'partial_failure', 'permission_denied',
        'unknown_applied', 'unknown_unresolved', 'create_unknown', 'claim_unknown', 'readback_rate_limited'];

    public function __construct(private MachineFailoverDnsRegistry $dns, private MachineFailoverServiceRegistry $registry,
        private MachineDnsRecordSetPlanner $planner, private MachineDnsRecordSetProvider $provider) {}

    public function executeLive(): never { throw new CloudException('dns_set_simulation_only'); }

    public function start(int $set, int $revision, int $serviceRevision, int $target, string $scenario, string $request, int $admin): array
    {
        $this->registry->actor($admin);
        if (!$this->dns->available()) throw new CloudException('migration_required');
        if (!in_array($scenario, self::SCENARIOS, true) || !preg_match('/^[a-f0-9]{32}$/D', $request)) throw new CloudException('invalid_dns_simulation');
        return $this->registry->locked(fn () => DB::transaction(function () use ($set, $revision, $serviceRevision, $target, $scenario, $request, $admin) {
            $this->registry->actor($admin); $key = hash('sha256', $admin . ':' . $request);
            $intent = hash('sha256', json_encode([$set, $revision, $serviceRevision, $target, $scenario], JSON_THROW_ON_ERROR));
            if ($old = Run::query()->where('request_key', $key)->first()) {
                if (!hash_equals($old->intent_hash, $intent)) throw new CloudException('request_conflict');
                return $this->report($old->id, $admin);
            }
            $snapshot = $this->dns->snapshot($set, $serviceRevision, $revision);
            if (!in_array($target, array_column($snapshot['slots'], 'member_id'), true)
                || !collect($snapshot['members'])->firstWhere('id', $target)['enabled'] && $scenario !== 'detach') throw new CloudException('invalid_dns_members');
            foreach (['run', 'execution'] as $table) if (\Illuminate\Support\Facades\Schema::hasTable('v2_machine_failover_' . $table)
                && DB::table('v2_machine_failover_' . $table)->where('service_id', $snapshot['set']['service_id'])->where('status', 'running')->exists()) throw new CloudException('run_active');
            if (Run::query()->where('service_id', $snapshot['set']['service_id'])->where('status', 'running')->exists()) throw new CloudException('run_active');
            if (Run::query()->count() >= 500 || Run::query()->where('service_id', $snapshot['set']['service_id'])->count() >= 100) throw new CloudException('simulation_limit');
            [$addresses, $records] = $this->seed($snapshot, $target, $scenario);
            $id = (string) Str::uuid(); $reason = null; $plan = ['actions' => [], 'expected' => [], 'protected' => [], 'foreign' => []];
            try {
                $inventory = $this->inventory($snapshot['set'], $records, $scenario === 'inventory_incomplete');
                $plan = $this->planner->plan($snapshot, $addresses, $inventory, in_array($scenario, ['detach', 'restore'], true) ? $scenario : 'replace',
                    in_array($scenario, ['bootstrap', 'create_unknown', 'claim_unknown'], true) ? null : $target);
            } catch (CloudException $error) { $reason = $error->getMessage(); }
            foreach ($plan['actions'] as $index => &$action) $action += ['id' => hash('sha256', $id . ':' . $index), 'state' => 'prepared', 'known_id' => null, 'requests' => []];
            unset($action);
            $run = Run::query()->create(['id' => $id, 'set_id' => $set, 'service_id' => $snapshot['set']['service_id'], 'set_revision' => $revision,
                'service_revision' => $serviceRevision, 'admin_id' => $admin, 'request_key' => $key, 'intent_hash' => $intent,
                'context_hash' => $this->hash($snapshot), 'scenario' => $scenario, 'status' => $reason ? 'blocked' : ($scenario === 'source_recovered' ? 'retained' : 'running'),
                'reason' => $reason ?? ($scenario === 'source_recovered' ? 'source_recovered' : null),
                'payload' => ['snapshot' => $snapshot, 'target' => $target, 'plan' => $plan, 'records' => $records,
                    'initial' => $records, 'writes' => 0, 'reads' => 0, 'virtual_wait_seconds' => 0, 'events' => []]]);
            return $this->report($run->id, $admin);
        }));
    }

    public function advance(string $id, int $admin, int $steps = 200): array
    {
        $this->registry->actor($admin);
        if ($steps < 1 || $steps > 200) throw new CloudException('invalid_dns_simulation');
        return $this->registry->locked(function () use ($id, $admin, $steps) {
            $run = $this->owned($id, $admin);
            for ($i = 0; $i < $steps && $run->status === 'running'; $i++) {
                $payload = $run->payload;
                try {
                    $this->registry->actor($admin);
                    $snapshot = $this->dns->snapshot($run->set_id, $run->service_revision, $run->set_revision);
                    if (!hash_equals($run->context_hash, $this->hash($snapshot))) throw new CloudException('dns_context_changed');
                    if ($run->cursor >= count($payload['plan']['actions'])) {
                        $this->verifySet($payload); $run->status = 'completed';
                    } else $this->step($run, $payload);
                } catch (CloudException $error) {
                    $run->status = $payload['writes'] > 0 || $this->hasDispatched($payload) ? 'needs_review' : 'blocked';
                    $run->reason = $error->getMessage();
                }
                $run->payload = $payload; $run->save();
            }
            return $this->report($id, $admin);
        });
    }

    private function step(Run $run, array &$p): void
    {
        $action = &$p['plan']['actions'][$run->cursor]; $set = $p['snapshot']['set'];
        if (in_array($action['state'], ['awaiting_readback', 'dispatched', 'claim_dispatched'], true)) {
            $p['reads']++; $this->verifyAction($action, $p['records']); $action['state'] = 'verified'; $run->cursor++; return;
        }
        if ($action['state'] === 'awaiting_claim') {
            $id = $action['known_id']; $index = $this->index($p['records'], $id);
            if ($index === null || $p['records'][$index]['marker'] !== '' || !$this->planner->same($p['records'][$index], array_replace($action['after'], ['marker' => ''])))
                throw new CloudException('dns_ownership_changed');
            $claim = array_replace($action, ['kind' => 'claim']); $action['requests'][] = $this->provider->mutation($set, $claim, $id);
            $action['state'] = 'claim_dispatched'; $run->payload = $p; $run->save();
            $p['records'][$index]['marker'] = $action['after']['marker']; $p['writes']++;
            $p['events'][] = ['stage' => 'claim', 'slot_id' => $action['slot_id'], 'outcome' => $run->scenario === 'claim_unknown' ? 'unknown' : 'acknowledged'];
            $action['state'] = 'awaiting_readback'; return;
        }
        if ($action['state'] !== 'prepared') throw new CloudException('dns_original_intent_only');
        if ($run->scenario === 'partial_failure' && $run->cursor === 1) throw new CloudException('dns_partial_failure');
        if ($run->scenario === 'record_changed' && $run->cursor === 0 && $action['before']) {
            $index = $this->index($p['records'], $action['before']['id']); $p['records'][$index]['content'] = $action['before']['type'] === 'A' ? '203.0.113.250' : '2001:db8:ffff::1';
        }
        $index = $action['before'] ? $this->index($p['records'], $action['before']['id']) : null;
        if ($action['before'] && ($index === null || $p['records'][$index] !== $action['before'])) throw new CloudException('dns_record_changed');
        $action['requests'][] = $this->provider->mutation($set, $action); $action['state'] = 'dispatched';
        $run->payload = $p; $run->save();
        if ($run->scenario === 'permission_denied') throw new CloudException('provider_forbidden');
        if ($run->scenario !== 'unknown_unresolved') {
            if ($action['kind'] === 'delete') array_splice($p['records'], $index, 1);
            else {
                $id = $action['before']['id'] ?? ($set['provider'] === 'cloudflare' ? substr($action['id'], 0, 32) : '90000000000000000000' . str_pad((string) $run->cursor, 3, '0', STR_PAD_LEFT));
                $record = ['id' => $id] + $action['after'];
                if ($action['kind'] === 'create' && $set['provider'] === 'aliyun') $record['marker'] = '';
                if ($index === null) $p['records'][] = $record; else $p['records'][$index] = $record;
                if ($action['kind'] === 'create' && $run->scenario !== 'create_unknown') $action['known_id'] = $id;
            }
            $p['writes']++;
        }
        $unknown = in_array($run->scenario, ['unknown_applied', 'unknown_unresolved', 'create_unknown'], true);
        $p['events'][] = ['stage' => $action['kind'], 'slot_id' => $action['slot_id'], 'outcome' => $unknown ? 'unknown' : 'acknowledged'];
        if ($action['kind'] === 'create' && $set['provider'] === 'aliyun') {
            if ($action['known_id'] === null) throw new CloudException('dns_unclaimed_record');
            $action['state'] = 'awaiting_claim'; return;
        }
        if ($run->scenario === 'readback_rate_limited' && $run->cursor === 0) {
            $p['reads']++; $p['virtual_wait_seconds'] += 120;
            $p['events'][] = ['stage' => 'readback', 'slot_id' => $action['slot_id'], 'outcome' => 'rate_limited'];
        }
        $action['state'] = 'awaiting_readback';
    }

    private function verifyAction(array $action, array $records): void
    {
        if ($action['kind'] === 'delete') {
            if ($this->index($records, $action['before']['id']) !== null) throw new CloudException('dns_original_intent_only');
            return;
        }
        $matches = array_values(array_filter($records, fn ($r) => ($r['marker'] ?? null) === $action['after']['marker']));
        if (count($matches) !== 1 || !$this->planner->same($matches[0], $action['after'])
            || $action['before'] && $matches[0]['id'] !== $action['before']['id']) throw new CloudException('dns_original_intent_only');
    }

    private function verifySet(array &$p): void
    {
        $records = $this->inventory($p['snapshot']['set'], $p['records']); $p['reads']++;
        foreach ([...$p['plan']['protected'], ...$p['plan']['foreign']] as $before) {
            $index = $this->index($records, $before['id']);
            if ($index === null || $records[$index] !== $before) throw new CloudException('dns_untargeted_change');
        }
        $owned = array_values(array_filter($records, fn ($r) => str_starts_with($r['marker'], 'keli-set-')));
        if (count($owned) !== count($p['plan']['expected'])) throw new CloudException('dns_set_mismatch');
        foreach ($p['plan']['expected'] as $wanted) {
            $matches = array_values(array_filter($owned, fn ($r) => $r['marker'] === $wanted['marker']));
            if (count($matches) !== 1 || !$this->planner->same($matches[0], $wanted)) throw new CloudException('dns_set_mismatch');
        }
    }

    private function seed(array $snapshot, int $target, string $scenario): array
    {
        $addresses = []; $initial = []; $indexes = [];
        foreach ($snapshot['members'] as $index => $member) {
            $n = $index + 1; $indexes[$member['id']] = $n;
            $addresses[$member['id']] = ['A' => '192.0.2.' . $n, 'AAAA' => '2001:db8:' . $n . '::1'];
        }
        foreach ($snapshot['slots'] as $index => $slot) {
            $value = $addresses[$slot['member_id']][$slot['type']];
            if ($scenario === 'restore' && $slot['member_id'] === $target) $value = $slot['type'] === 'A' ? '198.51.100.' . $indexes[$target] : '2001:db8:' . $indexes[$target] . '::2';
            $initial[] = ['id' => $snapshot['set']['provider'] === 'cloudflare' ? substr(hash('sha256', 'synthetic:' . $slot['marker']), 0, 32) : '90000000000000000000' . str_pad((string) ($index + 500), 3, '0', STR_PAD_LEFT),
                'hostname' => $snapshot['set']['hostname'], 'type' => $slot['type'], 'line' => $slot['line'], 'content' => $value,
                'ttl' => $snapshot['set']['ttl'], 'marker' => $slot['marker'], 'proxied' => false, 'locked' => false];
        }
        if (!in_array($scenario, ['restore', 'detach', 'source_recovered', 'bootstrap', 'create_unknown', 'claim_unknown'], true))
            $addresses[$target] = ['A' => '198.51.100.' . $indexes[$target], 'AAAA' => '2001:db8:' . $indexes[$target] . '::2'];
        if ($scenario === 'missing_ipv6') unset($addresses[$target]['AAAA']);
        if (in_array($scenario, ['bootstrap', 'create_unknown', 'claim_unknown'], true)) $initial = [];
        if ($scenario === 'foreign_record') { $row = $initial[0]; $row['id'] = $snapshot['set']['provider'] === 'cloudflare' ? str_repeat('e', 32) : '123456'; $row['marker'] = ''; $initial[] = $row; }
        if ($scenario === 'ownership_changed') $initial[0]['locked'] = true;
        if ($scenario === 'duplicate_record') { $row = $initial[0]; $row['id'] = $snapshot['set']['provider'] === 'cloudflare' ? str_repeat('e', 32) : '123456'; $initial[] = $row; }
        return [$addresses, $initial];
    }

    private function inventory(array $set, array $records, bool $incomplete = false): array
    {
        $rows = array_map(function ($r) use ($set) {
            if ($set['provider'] === 'cloudflare') return ['id' => $r['id'], 'name' => $r['hostname'], 'type' => $r['type'], 'content' => $r['content'],
                'ttl' => $r['ttl'], 'comment' => $r['marker'], 'proxied' => $r['proxied'], 'locked' => $r['locked']];
            return ['RecordId' => $r['id'], 'DomainName' => $set['zone'], 'RR' => $set['hostname'] === $set['zone'] ? '@' : substr($set['hostname'], 0, -strlen('.' . $set['zone'])),
                'Type' => $r['type'], 'Value' => $r['content'], 'TTL' => $r['ttl'], 'Line' => $r['line'], 'Remark' => $r['marker'], 'Locked' => $r['locked'], 'Status' => 'ENABLE'];
        }, $records);
        $chunks = array_chunk($rows, 50) ?: [[]]; $total = count($rows) + (int) $incomplete; $pages = [];
        foreach ($chunks as $index => $chunk) $pages[] = $set['provider'] === 'cloudflare'
            ? ['success' => true, 'result' => $chunk, 'result_info' => ['page' => $index + 1, 'per_page' => 50, 'total_count' => $total, 'total_pages' => max(1, (int) ceil($total / 50))]]
            : ['PageNumber' => $index + 1, 'PageSize' => 50, 'TotalCount' => $total, 'DomainRecords' => ['Record' => $chunk]];
        return $this->provider->inventory($set, $pages);
    }

    public function stop(string $id, int $admin): array
    {
        $this->registry->actor($admin);
        return $this->registry->locked(function () use ($id, $admin) {
            $run = $this->owned($id, $admin);
            if ($run->status === 'running') $run->fill(['status' => 'stopped', 'reason' => 'cancelled_by_admin'])->save();
            return $this->report($id, $admin);
        });
    }
    public function report(string $id, int $admin): array
    {
        $this->registry->actor($admin); $run = $this->owned($id, $admin); $p = $run->payload;
        $publicRecord = fn ($r) => $r === null ? null : array_intersect_key($r, array_flip(['id', 'hostname', 'type', 'line', 'content', 'ttl']));
        return MachineFailoverDnsRegistry::CLOSED + $run->toArray() + ['mode' => 'simulation', 'evidence_origin' => 'synthetic', 'business_verified' => false,
            'target_member_id' => $p['target'], 'provider' => $p['snapshot']['set']['provider'], 'hostname' => $p['snapshot']['set']['hostname'],
            'simulated_writes' => $p['writes'], 'readback_attempts' => $p['reads'], 'virtual_wait_seconds' => $p['virtual_wait_seconds'],
            'records' => array_map($publicRecord, $p['records']), 'events' => $p['events'],
            'actions' => array_map(fn ($a) => ['id' => $a['id'], 'member_id' => $a['member_id'], 'slot_id' => $a['slot_id'], 'kind' => $a['kind'], 'state' => $a['state'],
                'before' => $publicRecord($a['before']), 'after' => $publicRecord($a['after']), 'request_count' => count($a['requests'])], $p['plan']['actions'])];
    }
    public function resolve(int $service, string $request, int $admin): ?array
    {
        $this->registry->actor($admin);
        if (!preg_match('/^[a-f0-9]{32}$/D', $request)) throw new CloudException('invalid_dns_simulation');
        $run = Run::query()->where('request_key', hash('sha256', $admin . ':' . $request))->first();
        if (!$run) return null;
        if ($run->service_id !== $service) throw new CloudException('request_conflict');
        return $this->report($run->id, $admin);
    }
    private function owned(string $id, int $admin): Run
    {
        if (!$this->dns->available()) throw new CloudException('migration_required');
        $run = Run::query()->findOrFail($id);
        if ($run->admin_id !== $admin) throw new CloudException('permission_revoked');
        return $run;
    }
    private function index(array $records, string $id): ?int { foreach ($records as $index => $row) if ($row['id'] === $id) return $index; return null; }
    private function hash(array $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
    private function hasDispatched(array $p): bool { return count(array_filter($p['plan']['actions'], fn ($a) => $a['state'] !== 'prepared')) > 0; }
}

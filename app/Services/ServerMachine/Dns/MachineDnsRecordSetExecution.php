<?php
namespace App\Services\ServerMachine\Dns;

use App\Models\MachineDnsSetRuntime as Runtime;
use App\Models\MachineFailoverDnsRun as Run;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverDnsRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineDnsRecordSetExecution
{
    public const READBACK_LIMIT = 12;
    public const LEASE_SECONDS = 120;
    public function __construct(private MachineFailoverDnsRegistry $dns, private MachineFailoverServiceRegistry $registry,
        private MachineDnsRecordSetPlanner $planner, private MachineDnsRecordSetProvider $provider,
        private MachineDnsRecordSetSimulationTransport $simulation, private MachineDnsRecordSetHttpTransport $http,
        private MachineFailoverExecutionGate $gate) {}

    public function available(): bool { return Schema::hasTable('v2_machine_dns_set_runtime') && Schema::hasTable('v2_machine_dns_set_fixture'); }
    public function initialize(Run $run, string $mode = 'native'): void
    {
        if ($mode !== 'simulation') $this->gate->assertAllowed('dns_set_start');
        if (!$this->available()) throw new CloudException('migration_required');
        if (!in_array($mode, ['native', 'simulation'], true) || Runtime::query()->whereKey($run->id)->exists()) throw new CloudException('dns_original_intent_only');
        if ($mode === 'native') app(\App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)->guard($run, false);
        $p = $run->payload; $p['engine_version'] = 2; $p['deadline_at'] = $this->now() + 1800;
        foreach ($p['plan']['actions'] as &$action) { $action['seal'] = $this->seal($action); $action['readback_attempts'] = 0; }
        unset($action); $run->payload = $p; $run->save();
        Runtime::query()->create(['run_id' => $run->id, 'mode' => $mode]);
        if ($mode === 'simulation') $this->simulation->seed($run->id, $p['snapshot']['set'], $p['records'], $run->scenario);
    }

    // Each tick owns a bounded lease, with no provider I/O inside a database transaction.
    public function tick(string $id, int $admin): bool
    {
        $this->registry->actor($admin); $row = $this->runtime($id); $run = $this->owned($id, $admin);
        if ($row->mode !== 'simulation') $this->gate->assertAllowed('dns_set_advance');
        $token = bin2hex(random_bytes(24));
        $generation = DB::transaction(function () use ($id, $token) {
            $r = Runtime::query()->whereKey($id)->lockForUpdate()->firstOrFail(); $run = Run::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'running' || $r->next_check_at > $this->clock($r) || $r->lease_hash && $r->lease_until > $this->now()) return null;
            $r->fill(['generation' => $r->generation + 1, 'lease_hash' => hash('sha256', $token), 'lease_until' => $this->now() + self::LEASE_SECONDS])->save();
            return $r->generation;
        });
        if ($generation === null) return false;
        try {
            $run->refresh(); $p = $run->payload;
            if (($p['engine_version'] ?? null) !== 2) throw new CloudException('dns_legacy_read_only');
            $this->context($run, $admin);
            if ($row->fresh()->stop_requested_at) $this->settleStop($run, $p, $token, $generation);
            elseif ($run->cursor >= count($p['plan']['actions'])) {
                $records = $this->driver($row)->inventory($id, $p['snapshot']['set']); $p['reads']++;
                $this->verifySet($p, $records); $p['records'] = $records;
                $this->save($run, $p, $token, $generation, 'completed');
            } else $this->step($run, $p, $token, $generation, $row);
        } catch (DnsRequestFailure $error) {
            $run->refresh(); $p = $run->payload; $p['inventory_failures'] = ($p['inventory_failures'] ?? 0) + 1;
            $retry = $error->retryable && $p['inventory_failures'] < self::READBACK_LIMIT;
            $this->save($run, $p, $token, $generation, $retry ? null : ($this->dispatched($p) ? 'needs_review' : 'blocked'), $this->reason($error),
                $retry ? $this->clock($this->runtime($id)) + max(1, min(86400, $error->retryAfterSeconds)) : null);
        } catch (CloudException|DnsException $error) {
            $run->refresh(); $p = $run->payload;
            $this->save($run, $p, $token, $generation, $this->dispatched($p) ? 'needs_review' : 'blocked', $this->reason($error));
        } finally {
            Runtime::query()->whereKey($id)->where('generation', $generation)->where('lease_hash', hash('sha256', $token))
                ->update(['lease_hash' => null, 'lease_until' => null]);
        }
        return true;
    }

    private function step(Run $run, array $p, string $token, int $generation, Runtime $runtime): void
    {
        $a = &$p['plan']['actions'][$run->cursor]; $set = $p['snapshot']['set']; $driver = $this->driver($runtime);
        $this->journal($a, $set);
        if (in_array($a['state'], ['dispatched', 'claim_dispatched', 'awaiting_readback'], true)) {
            $this->readback($run, $p, $token, $generation, false); return;
        }
        if (!in_array($a['state'], ['prepared', 'awaiting_claim'], true)) throw new CloudException('dns_original_intent_only');
        $records = $driver->inventory($run->id, $set); $p['reads']++;
        $claim = $a['state'] === 'awaiting_claim';
        $working = $claim ? $this->applied($p['records'], $a, $a['known_id'], false) : $p['records'];
        if (!$this->equal($working, $records)) throw new CloudException('dns_record_changed');
        $operation = $claim ? array_replace($a, ['kind' => 'claim']) : $a;
        $wire = $this->provider->mutation($set, $operation, $claim ? $a['known_id'] : null);
        $a['requests'][] = $wire; $a['state'] = $claim ? 'claim_dispatched' : 'dispatched';
        $a['dispatch_hash'] = $this->hash($wire); $a['readback_attempts'] = 0;
        if (!$this->save($run, $p, $token, $generation, null, null, null, true)) return;
        if (!$this->mayWrite($run->id, $token, $generation)) return;
        try {
            $id = $driver->write($run->id, $set, $operation, $claim ? $a['known_id'] : null);
            if (!preg_match($set['provider'] === 'cloudflare' ? '/^[a-f0-9]{32}$/D' : '/^[0-9]{1,40}$/D', $id)
                || $operation['kind'] !== 'create' && $id !== ($a['known_id'] ?? $a['before']['id'])) throw new CloudException('dns_ownership_changed');
            if ($a['kind'] === 'create' && !$claim) $a['known_id'] = $id;
            $a['state'] = $a['kind'] === 'create' && $set['provider'] === 'aliyun' && !$claim ? 'awaiting_claim' : 'awaiting_readback';
            $p['writes']++; $p['events'][] = ['stage' => $operation['kind'], 'slot_id' => $a['slot_id'], 'outcome' => 'acknowledged'];
            $this->save($run, $p, $token, $generation);
        } catch (DnsRequestFailure $e) {
            $a['state'] = 'awaiting_readback';
            $p['events'][] = ['stage' => $operation['kind'], 'slot_id' => $a['slot_id'], 'outcome' => 'unknown'];
            $unclaimed = $a['kind'] === 'create' && $set['provider'] === 'aliyun' && !$a['known_id'];
            $this->save($run, $p, $token, $generation, $unclaimed ? 'needs_review' : null, $unclaimed ? 'dns_unclaimed_record' : null);
        }
    }

    private function readback(Run $run, array $p, string $token, int $generation, bool $stopping): void
    {
        $a = &$p['plan']['actions'][$run->cursor]; $runtime = $this->runtime($run->id);
        $this->journal($a, $p['snapshot']['set']);
        if ($a['readback_attempts'] >= self::READBACK_LIMIT) throw new CloudException('dns_original_intent_only');
        if ($a['kind'] === 'create' && $p['snapshot']['set']['provider'] === 'aliyun' && !$a['known_id']) throw new CloudException('dns_unclaimed_record');
        $a['readback_attempts']++; $p['reads']++;
        if (!$this->save($run, $p, $token, $generation)) return;
        try { $records = $this->driver($runtime)->inventory($run->id, $p['snapshot']['set']); }
        catch (DnsRequestFailure $e) {
            if (!$e->retryable) throw $e;
            $this->wait($run, $p, $token, $generation, $e->retryAfterSeconds); return;
        }
        $matches = $a['kind'] === 'delete' ? [] : array_values(array_filter($records, fn ($r) => $r['marker'] === $a['after']['marker']));
        $id = $a['before']['id'] ?? $a['known_id'] ?? ($matches[0]['id'] ?? null);
        $match = $a['kind'] === 'delete' ? !array_filter($records, fn ($r) => $r['id'] === $a['before']['id'])
            : count($matches) === 1 && $matches[0]['id'] === $id && $this->planner->same($matches[0], $a['after']);
        if (!$match) {
            if ($stopping || $a['readback_attempts'] >= self::READBACK_LIMIT) throw new CloudException('dns_original_intent_only');
            $this->wait($run, $p, $token, $generation, 20); return;
        }
        if (!$this->equal($this->applied($p['records'], $a, $id), $records)) throw new CloudException('dns_untargeted_change');
        $a['state'] = 'verified'; $p['records'] = $records; $run->cursor++;
        $this->save($run, $p, $token, $generation, $stopping ? 'stopped' : null, $stopping ? 'cancelled_by_admin' : null);
    }

    public function stop(string $id, int $admin): void
    {
        $this->registry->actor($admin); $this->owned($id, $admin); $this->runtime($id);
        DB::transaction(function () use ($id) {
            $r = Runtime::query()->whereKey($id)->lockForUpdate()->firstOrFail(); $run = Run::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'running' || $r->stop_requested_at) return;
            $r->fill(['stop_requested_at' => $this->now(), 'next_check_at' => null])->save();
        });
        $this->tick($id, $admin);
    }
    private function settleStop(Run $run, array $p, string $token, int $generation): void
    {
        $a = $p['plan']['actions'][$run->cursor] ?? null;
        if ($a && $a['state'] === 'awaiting_claim') throw new CloudException('dns_unclaimed_record');
        if ($a && in_array($a['state'], ['dispatched', 'claim_dispatched', 'awaiting_readback'], true)) {
            $this->readback($run, $p, $token, $generation, true); return;
        }
        $this->save($run, $p, $token, $generation, 'stopped', 'cancelled_by_admin');
    }
    private function wait(Run $run, array $p, string $token, int $generation, int $seconds): void
    {
        $a = $p['plan']['actions'][$run->cursor];
        if ($a['readback_attempts'] >= self::READBACK_LIMIT) throw new CloudException('dns_original_intent_only');
        $this->save($run, $p, $token, $generation, null, null, $this->clock($this->runtime($run->id)) + max(1, min(86400, $seconds)));
    }
    private function save(Run $run, array $p, string $token, int $generation, ?string $status = null, ?string $reason = null, ?int $next = null, bool $dispatch = false): bool
    {
        return DB::transaction(function () use ($run, $p, $token, $generation, $status, $reason, $next, $dispatch) {
            if ($this->runtime($run->id)->mode === 'native')
                app(\App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)->lockParent($run);
            $r = Runtime::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (!$this->owns($r, $token, $generation) || $dispatch && $r->stop_requested_at) return false;
            $current = Run::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'running') return false;
            if ($dispatch || $status === 'completed') $this->context($current, $current->admin_id, true, $dispatch);
            // A stop arriving during I/O may retain the receipt, but cannot complete the full run.
            $status = $status === 'completed' && $r->stop_requested_at ? 'stopped' : $status;
            $current->fill(['payload' => $p, 'cursor' => $run->cursor, 'reason' => $reason, 'status' => $status ?? 'running'])->save();
            $r->fill(['next_check_at' => $next, 'finished_at' => $status !== null ? $this->now() : null])->save(); return true;
        });
    }
    private function context(Run $run, int $admin, bool $lock = false, bool $dispatch = false): void
    {
        $this->registry->actor($admin);
        if ($lock) {
            \App\Models\MachineFailoverService::query()->whereKey($run->service_id)->lockForUpdate()->firstOrFail();
            \App\Models\MachineFailoverDnsSet::query()->whereKey($run->set_id)->lockForUpdate()->firstOrFail();
        }
        $snapshot = $this->dns->snapshot($run->set_id, $run->service_revision, $run->set_revision);
        if (!hash_equals($run->context_hash, $this->hash($snapshot))) throw new CloudException('dns_context_changed');
        if ($run->payload['deadline_at'] <= $this->clock($this->runtime($run->id))) throw new CloudException('dns_intent_expired');
        if ($this->runtime($run->id)->mode === 'native')
            app(\App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)->guard($run, $dispatch, !$lock);
    }
    private function mayWrite(string $id, string $token, int $generation): bool
    {
        $r = $this->runtime($id);
        if ($r->stop_requested_at || !$this->owns($r, $token, $generation)) return false;
        if ($r->mode === 'native') app(\App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)->guard(Run::query()->findOrFail($id), true);
        return true;
    }
    private function owns(Runtime $r, string $token, int $generation): bool
    {
        return $r->generation === $generation && $r->lease_until > $this->now() && $r->lease_hash !== null && hash_equals($r->lease_hash, hash('sha256', $token));
    }
    private function driver(Runtime $r): MachineDnsRecordSetTransport
    {
        if ($r->mode === 'simulation') return $this->simulation;
        if ($r->mode !== 'native') throw new CloudException('dns_driver_mismatch');
        $this->gate->assertAllowed('dns_set_transport'); return $this->http;
    }
    public function clock(Runtime $r): int { return $this->now() + ($r->mode === 'simulation' ? $this->simulation->metrics($r->run_id)['clock_offset'] : 0); }
    private function now(): int { return Carbon::now()->timestamp; }
    private function runtime(string $id): Runtime
    {
        if (!$this->available()) throw new CloudException('migration_required');
        $r = Runtime::query()->find($id); if (!$r) throw new CloudException('dns_legacy_read_only'); return $r;
    }
    private function owned(string $id, int $admin): Run
    {
        $run = Run::query()->findOrFail($id); if ($run->admin_id !== $admin) throw new CloudException('permission_revoked'); return $run;
    }
    private function applied(array $records, array $a, ?string $id, bool $claimed = true): array
    {
        if ($a['kind'] !== 'create') $records = array_values(array_filter($records, fn ($r) => $r['id'] !== $a['before']['id']));
        if ($a['kind'] !== 'delete') { if (!$id) throw new CloudException('dns_ownership_changed');
            $record = ['id' => $id] + $a['after']; if (!$claimed) $record['marker'] = ''; $records[] = $record; }
        return $records;
    }
    private function verifySet(array $p, array $records): void
    {
        if (!$this->equal($p['records'], $records)) throw new CloudException('dns_untargeted_change');
        $owned = array_values(array_filter($records, fn ($r) => str_starts_with($r['marker'], 'keli-set-')));
        if (count($owned) !== count($p['plan']['expected'])) throw new CloudException('dns_set_mismatch');
        foreach ($p['plan']['expected'] as $wanted) {
            $matches = array_values(array_filter($owned, fn ($r) => $r['marker'] === $wanted['marker']));
            if (count($matches) !== 1 || !$this->planner->same($matches[0], $wanted)) throw new CloudException('dns_set_mismatch');
        }
    }
    public function verifyReadback(Run $run, array $records): void
    {
        if ($run->status !== 'completed' || $run->cursor !== count($run->payload['plan']['actions'])) throw new CloudException('native_dns_receipt_required');
        $this->verifySet($run->payload, $records);
    }
    private function equal(array $a, array $b): bool
    {
        usort($a, fn ($a, $b) => strcmp($a['id'], $b['id'])); usort($b, fn ($a, $b) => strcmp($a['id'], $b['id'])); return $a === $b;
    }
    private function seal(array $a): string { return $this->hash(array_intersect_key($a, array_flip(['id', 'kind', 'slot_id', 'member_id', 'before', 'after']))); }
    private function journal(array $a, array $set): void
    {
        if (!is_string($a['seal'] ?? null) || !hash_equals($a['seal'], $this->seal($a))) throw new CloudException('dns_journal_changed');
        if ($a['state'] === 'prepared') {
            if ($a['requests'] !== []) throw new CloudException('dns_journal_changed');
            return;
        }
        $expected = [$this->provider->mutation($set, $a)];
        if (count($a['requests']) === 2 && $set['provider'] === 'aliyun' && $a['kind'] === 'create' && $a['known_id'])
            $expected[] = $this->provider->mutation($set, array_replace($a, ['kind' => 'claim']), $a['known_id']);
        if ($expected !== $a['requests'] || ($a['dispatch_hash'] ?? null) !== $this->hash($expected[array_key_last($expected)])) throw new CloudException('dns_journal_changed');
    }
    private function hash(array $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)); }
    private function dispatched(array $p): bool { return count(array_filter($p['plan']['actions'], fn ($a) => $a['state'] !== 'prepared')) > 0; }
    private function reason(\Throwable $e): string { return preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $e->getMessage()) ? $e->getMessage() : 'dns_outcome_unknown'; }
}

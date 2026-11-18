<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetPlanner;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use App\Services\ServerMachine\MachineEntryProbe;

require_once __DIR__ . '/usdo1_existing_a.php';

/** Single-source round. The injected ports are simulation only; callers hold an exclusive journal lock. */
final class Usdo1Round
{
    private const ROLES = ['control', 'source', 'candidate'];
    private const RECOVERY = ['drain', 'rollback', 'cleanup'];
    private const RESERVE = 2100;
    private const REPORT_AGE = 30;
    private const REPORT_GAP = 20;
    private const REPORT_COUNT = 3;
    private array $state;
    private array $dnsSet = ['provider' => 'aliyun', 'zone' => Usdo1ExistingA::ZONE, 'hostname' => Usdo1ExistingA::NAME, 'ttl' => 600];
    private array $targetIds;
    private string $probeRevision;
    private string $binding;
    private bool $busy = false;

    public function __construct(private array $scope, private \Closure $port, private \Closure $load,
        private \Closure $save, private \Closure $clock, private ?\Closure $boundDns = null)
    {
        self::need(app()->runningUnitTests() && app('db')->connection()->getDriverName() === 'sqlite'
            && app('db')->connection()->getDatabaseName() === ':memory:', 'offline_environment_required');
        self::need(($scope['bound_native_dns'] ?? false) === ($boundDns !== null), 'dns_binding_invalid');
        $rollback = $scope['rollback'] ?? [];
        $baseline = $scope['baseline'] ?? [];
        self::need(is_string($scope['run_id'] ?? null) && preg_match('/^keli-accept-[a-z0-9-]{8,70}$/D', $scope['run_id']) === 1
            && is_int($scope['account_id'] ?? null) && $scope['account_id'] > 0
            && is_int($scope['started_at'] ?? null) && $scope['started_at'] > 0 && is_int($scope['deadline'] ?? null)
            && self::RESERVE < $scope['deadline'] - $scope['started_at'] && $scope['deadline'] - $scope['started_at'] <= 7200
            && ($rollback['machine_id'] ?? null) === 17 && self::externalId($rollback['external_id'] ?? null)
            && self::ipv4($rollback['ipv4'] ?? null) && ($baseline['Value'] ?? null) === $rollback['ipv4']
            && ($baseline['TTL'] ?? null) === 60 && ($baseline['RR'] ?? null) === 'usdo1'
            && ($baseline['Type'] ?? null) === 'A' && ($baseline['Status'] ?? null) === 'Enable'
            && ($baseline['Locked'] ?? null) === false && ($baseline['Line'] ?? null) === 'default'
            && is_string($baseline['Remark'] ?? null) && self::hex($scope['binary_sha256'] ?? null, 64), 'scope_invalid');
        (new MachineDnsRecordSetProvider)->record($this->dnsSet, $baseline);
        MachineDnsRecordSetProvider::aliyunMarker($scope['marker'] ?? '');
        $protected = $scope['protected_ids'] ?? null;
        self::need(is_array($protected) && array_is_list($protected) && in_array($rollback['external_id'], $protected, true), 'protected_inventory_invalid');
        foreach ($protected as $id) self::need(self::externalId($id), 'protected_inventory_invalid');
        $targets = $scope['probe_ipv4_targets'] ?? null;
        self::need(is_array($targets) && array_is_list($targets) && count($targets) === 4, 'targets_invalid');
        foreach ($targets as $ip) self::need(self::ipv4($ip), 'targets_invalid');
        self::need(count(array_unique($targets)) === 4, 'targets_invalid');
        $this->targetIds = array_map(fn (string $ip) => substr(hash('sha256', $ip), 0, 16), $targets);
        $this->probeRevision = hash('sha256', json_encode(['schema' => 1, 'enabled' => true, 'interval_seconds' => 5, 'timeout_seconds' => 2,
            'targets' => array_map(fn ($id, $ip) => ['id' => $id, 'ip' => $ip], $this->targetIds, $targets)], JSON_THROW_ON_ERROR));
        $this->binding = self::digest($scope);
        $this->state = $this->readState();
    }

    private static function need(bool $ok, string $reason): void
    {
        if (!$ok) throw new \RuntimeException('usdo1_round_' . $reason);
    }

    private static function digest(array $value): string { return hash('sha256', Json::encode($value)); }

    private static function externalId(mixed $value): bool { return is_string($value) && preg_match('/^[1-9][0-9]{0,15}$/D', $value) === 1; }

    private static function hex(mixed $value, int $size): bool
    { return is_string($value) && strlen($value) === $size && preg_match('/^[a-f0-9]+$/D', $value) === 1; }

    private static function ipv4(mixed $value): bool
    { return is_string($value) && !str_contains($value, ':') && MachineEntryProbe::publicIp($value) === $value; }

    private function readState(): array
    {
        $state = ($this->load)('round');
        self::need($state !== null || ($this->load)('dns') === null, 'journal_missing');
        $state ??= ['binding' => $this->binding, 'phase' => 'control', 'last_at' => $this->scope['started_at'],
            'roles' => [], 'windows' => ['source' => [], 'candidate' => []], 'stop_sent' => false,
            'source_episode' => null, 'cutover_verified' => false, 'passed' => false, 'first_error' => null, 'recovery_error' => null];
        self::need(is_array($state) && ($state['binding'] ?? null) === $this->binding && is_int($state['last_at'] ?? null)
            && is_array($state['roles'] ?? null) && array_diff(array_keys($state['roles']), self::ROLES) === []
            && is_array($state['windows']['source'] ?? null) && is_array($state['windows']['candidate'] ?? null)
            && is_bool($state['stop_sent'] ?? null) && is_bool($state['cutover_verified'] ?? null) && is_bool($state['passed'] ?? null)
            && array_key_exists('first_error', $state) && array_key_exists('recovery_error', $state)
            && in_array($state['phase'] ?? null, [...self::ROLES, 'adopt', 'qualify-source', 'qualify-candidate', 'confirm-cutover', ...self::RECOVERY, 'closed'], true),
            'journal_changed');
        foreach ($state['roles'] as $role => $entry) {
            self::need(is_array($entry) && Json::same($entry['intent'] ?? null, $this->request($role))
                && array_key_exists('pin', $entry) && ($entry['pin'] === null || is_array($entry['pin']))
                && is_bool($entry['confirmed'] ?? null) && is_bool($entry['delete_sent'] ?? null)
                && array_key_exists('first_absent_at', $entry) && ($entry['first_absent_at'] === null || is_int($entry['first_absent_at'])), 'journal_changed');
        }
        self::need(array_key_exists('source_episode', $state)
            && (isset($state['roles']['candidate']) ? self::hex($state['source_episode'], 64) && isset($state['roles']['source']['pin'])
                : $state['source_episode'] === null)
            && (!isset($state['roles']['source']) || isset($state['roles']['control']['pin']))
            && (!in_array($state['phase'], ['source', 'adopt', 'qualify-source', 'candidate', 'qualify-candidate', 'confirm-cutover'], true)
                || isset($state['roles']['control']['pin']))
            && (!in_array($state['phase'], ['adopt', 'qualify-source', 'candidate', 'qualify-candidate', 'confirm-cutover'], true)
                || isset($state['roles']['source']['pin']))
            && (!in_array($state['phase'], ['qualify-candidate', 'confirm-cutover'], true) || isset($state['roles']['candidate']['pin']))
            && (!($state['phase'] === 'control' && $state['roles'] === []) || ($this->load)('dns') === null), 'journal_changed');
        return $state;
    }

    private function persist(): void
    {
        ($this->save)('round', $this->state);
        self::need(Json::same(($this->load)('round'), $this->state), 'journal_unconfirmed');
    }

    private function request(string $role): array
    {
        $id = substr(self::digest([$this->binding, 'provision', $role]), 0, 32);
        return ['role' => $role, 'request_id' => $id, 'label' => 'keli-' . $id, 'account_id' => $this->scope['account_id'],
            'kind' => $role === 'candidate' ? 'native' : 'setup'];
    }

    private function call(string $operation, array $args = []): array
    {
        $response = ($this->port)($operation, ['run_id' => $this->scope['run_id'], 'binding' => $this->binding] + $args);
        self::need(is_array($response), 'port_reply_invalid');
        return $response;
    }

    private function now(): int
    {
        $now = ($this->clock)();
        self::need(is_int($now) && $now >= $this->state['last_at'], 'clock_regressed');
        return $now;
    }

    private function canAdvance(): bool { return $this->now() < $this->scope['deadline'] - self::RESERVE; }

    public function step(bool $stop = false): array
    {
        self::need(!$this->busy, 'reentered');
        $this->state = $this->readState();
        if ($this->state['phase'] === 'closed') return $this->state;
        $this->busy = true;
        try {
            $phase = $this->state['phase'];
            if ($stop || (!in_array($phase, self::RECOVERY, true) && !$this->canAdvance())) {
                $this->state['first_error'] ??= $stop ? 'usdo1_round_stop_requested' : 'usdo1_round_deadline';
                if (!in_array($phase, self::RECOVERY, true)) $this->state['phase'] = 'drain';
                $this->persist();
            }
            $this->state['last_at'] = $this->now();
            match ($this->state['phase']) {
                'control', 'source', 'candidate' => $this->provision($this->state['phase']),
                'adopt' => $this->adopt(),
                'qualify-source' => $this->sourceWindow(),
                'qualify-candidate' => $this->candidateWindow(),
                'confirm-cutover' => $this->confirmCutover(),
                'drain' => $this->drain(),
                'rollback' => $this->rollback(),
                'cleanup' => $this->cleanup(),
            };
        } catch (\Throwable $error) {
            $reason = $error instanceof \RuntimeException && preg_match('/^usdo1_(?:round|existing_a)_[a-z_]{1,60}$/D', $error->getMessage())
                ? $error->getMessage() : 'usdo1_round_port_failed';
            if (in_array($this->state['phase'], self::RECOVERY, true)) {
                if ($reason !== 'usdo1_existing_a_cache_drain_pending') $this->state['recovery_error'] ??= $reason;
                if ($this->state['phase'] === 'cleanup') $this->resetAbsenceReads();
            } else {
                $this->state['first_error'] ??= $reason;
                $this->state['phase'] = 'drain';
            }
        } finally { $this->busy = false; }
        $this->persist();
        return $this->state;
    }

    private function provision(string $role): void
    {
        $request = $this->request($role);
        if (!isset($this->state['roles'][$role])) {
            if ($role === 'candidate' && !$this->collect('source', 'failed')) return;
            if ($role === 'candidate') $this->state['source_episode'] = end($this->state['windows']['source'])['episode'];
            $this->state['roles'][$role] = ['intent' => $request, 'pin' => null, 'delete_sent' => false, 'first_absent_at' => null, 'confirmed' => false];
            $this->persist();
            self::need($this->canAdvance(), 'deadline');
            self::need($role !== 'candidate' || $this->qualified('source'), 'source_evidence_expired');
            $this->call('dispatch-provision', $request + ($role === 'candidate' ? [
                'source' => $this->state['roles']['source']['pin'], 'episode_key' => $this->state['source_episode'],
                'episode_sha256' => self::digest($this->state['windows']['source']), 'workflow' => 'agent_readiness_v1',
            ] : []));
            return;
        }
        $receipt = $this->readProvision($role);
        self::need($receipt['status'] !== 'not-created', 'provision_not_created');
        if ($receipt['status'] !== 'created' || !$receipt['ready']) return;
        $this->state['phase'] = ['control' => 'source', 'source' => 'adopt', 'candidate' => 'qualify-candidate'][$role];
    }

    private function readProvision(string $role): array
    {
        $request = $this->request($role);
        $resource = &$this->state['roles'][$role];
        self::need(Json::same($resource['intent'], $request), 'provision_intent_changed');
        $receipt = $this->call('read-provision', $request);
        self::need(Json::same($receipt['intent'] ?? null, $request)
            && in_array($receipt['status'] ?? null, ['pending', 'not-created', 'created'], true), 'provision_reply_invalid');
        if ($receipt['status'] !== 'created') {
            self::need($resource['pin'] === null && ($receipt['pin'] ?? null) === null, 'provision_identity_changed');
            return $receipt;
        }
        $pin = $receipt['pin'] ?? [];
        self::need(is_array($pin) && self::externalId($pin['external_id'] ?? null)
            && !in_array($pin['external_id'], $this->scope['protected_ids'], true)
            && ($pin['account_id'] ?? null) === $request['account_id'] && ($pin['label'] ?? null) === $request['label']
            && ($pin['request_id'] ?? null) === $request['request_id']
            && is_int($pin['created_at'] ?? null) && $this->scope['started_at'] <= $pin['created_at'] && $pin['created_at'] <= $this->now()
            && self::ipv4($pin['ipv4'] ?? null) && $pin['ipv4'] !== $this->scope['rollback']['ipv4']
            && ($role === 'control' ? array_key_exists('installation_id', $pin) && $pin['installation_id'] === null
                : self::hex($pin['installation_id'] ?? null, 32)) && is_bool($receipt['ready'] ?? null), 'provision_pin_invalid');
        foreach ($this->state['roles'] as $other => $row) {
            if ($other === $role || $row['pin'] === null) continue;
            self::need($row['pin']['external_id'] !== $pin['external_id'] && $row['pin']['ipv4'] !== $pin['ipv4']
                && $row['pin']['installation_id'] !== $pin['installation_id'], 'role_identity_reused');
        }
        self::need($resource['pin'] === null || Json::same($resource['pin'], $pin), 'provision_identity_changed');
        $resource['pin'] = $pin;
        return $receipt;
    }

    private function adopt(): void
    {
        $this->dns()->adopt();
        $this->state['phase'] = 'qualify-source';
    }

    private function sourceWindow(): void
    {
        if ($this->collect('source', 'failed')) $this->state['phase'] = 'candidate';
    }

    private function candidateWindow(): void
    {
        $source = $this->collect('source', 'failed');
        if (!$this->collect('candidate', 'healthy') || !$source) return;
        $dns = $this->dns();
        $record = $dns->adopt();
        $address = $this->state['roles']['candidate']['pin']['ipv4'];
        if ($this->boundDns !== null) {
            $this->persist();
            if (($this->boundDns)('publish', $dns) !== true) return;
            self::need($dns->candidateRecord()['Value'] === $address, 'candidate_unconfirmed');
            $this->state['phase'] = 'confirm-cutover';
            return;
        }
        $plan = (new MachineDnsRecordSetPlanner)->plan(['set' => $this->dnsSet, 'members' => [['id' => 1, 'enabled' => true]],
            'slots' => [['id' => 1, 'member_id' => 1, 'marker' => $this->scope['marker'], 'type' => 'A', 'line' => 'default']]],
            [1 => ['A' => $address]], [$record], 'replace', 1);
        self::need(count($plan['actions']) === 1 && $plan['actions'][0]['kind'] === 'update', 'not_single_a_update');
        $this->persist();
        $dns->cutover($plan['actions'][0]);
        $this->state['phase'] = 'confirm-cutover';
    }

    private function confirmCutover(): void
    {
        if (!$this->externalDns($this->dns()->candidateRecord())) return;
        $this->state['cutover_verified'] = true;
        $this->state['phase'] = 'drain';
    }

    private function collect(string $role, string $want): bool
    {
        $pin = $this->state['roles'][$role]['pin'];
        $observation = $this->call('read-observation', ['role' => $role, 'pin' => $pin, 'probe_revision' => $this->probeRevision]);
        $window = &$this->state['windows'][$role];
        if ($observation === []) { $window = []; return false; }
        self::need(Json::same($observation['pin'] ?? null, $pin) && ($observation['binary_sha256'] ?? null) === $this->scope['binary_sha256']
            && self::hex($observation['session'] ?? null, 32), 'observation_identity_changed');
        $now = $this->now();
        $probe = (new MachineEntryProbe)->normalizeReport($observation['probe'] ?? null);
        $agentAt = $observation['agent_at'] ?? null;
        if (!$probe || $probe['state'] !== 'ready' || !$this->fresh($agentAt, $now) || !$this->fresh($probe['checked_at'], $now)
            || min($agentAt, $probe['checked_at']) < $pin['created_at']) {
            $window = [];
            return false;
        }
        self::need($probe['revision'] === $this->probeRevision, 'probe_targets_changed');
        foreach ($probe['targets'] as $target) self::need(in_array($target['id'], $this->targetIds, true)
            && ($target['source_ip'] ?? null) === $pin['ipv4'], 'probe_target_or_address_changed');
        $statuses = array_column($probe['targets'], 'status');
        $signal = in_array('reachable', $statuses, true) ? 'healthy'
            : (count($statuses) === 4 && $statuses === array_fill(0, 4, 'unreachable') ? 'failed' : 'unknown');
        self::need($role !== 'source' || $signal !== 'healthy' || $this->state['phase'] === 'qualify-source', 'source_recovered');
        if ($signal !== $want) { $window = []; return false; }
        $native = $role === 'source' ? ($observation['native'] ?? []) : [];
        if ($role === 'source') {
            self::need(($native['checked_at'] ?? null) === $probe['checked_at'] && ($native['state'] ?? null) === 'failed'
                && self::hex($native['context'] ?? null, 64) && is_int($native['failures'] ?? null) && $native['failures'] > 0
                && is_bool($native['threshold_reached'] ?? null)
                && (!$native['threshold_reached'] || self::hex($native['episode_key'] ?? null, 64)), 'native_evidence_unbound');
            self::need($this->state['source_episode'] === null || ($native['episode_key'] ?? null) === $this->state['source_episode'], 'source_episode_changed');
        }
        $row = ['agent_at' => $agentAt, 'checked_at' => $probe['checked_at'], 'session' => $observation['session'],
            'context' => $native['context'] ?? null, 'failures' => $native['failures'] ?? null, 'threshold' => $native['threshold_reached'] ?? null,
            'episode' => $native['episode_key'] ?? null, 'probe_sha256' => self::digest([$probe, $native])];
        if ($window !== []) {
            $prior = end($window);
            self::need($row['agent_at'] >= $prior['agent_at'] && $row['checked_at'] >= $prior['checked_at'], 'observation_regressed');
            self::need($row['checked_at'] !== $prior['checked_at'] || $row['probe_sha256'] === $prior['probe_sha256'], 'probe_replay_changed');
            if ($row['session'] !== $prior['session'] || $row['context'] !== $prior['context']
                || $row['agent_at'] - $prior['agent_at'] > self::REPORT_GAP || $row['checked_at'] - $prior['checked_at'] > self::REPORT_GAP
                || ($role === 'source' && $row['failures'] < $prior['failures'])) $window = [];
            elseif ($row['agent_at'] === $prior['agent_at'] || $row['checked_at'] - $prior['checked_at'] < 5) return $this->qualified($role);
        }
        $window = array_slice([...$window, $row], -self::REPORT_COUNT);
        return $this->qualified($role);
    }

    private function fresh(mixed $at, int $now): bool { return is_int($at) && 0 <= $now - $at && $now - $at <= self::REPORT_AGE; }

    private function qualified(string $role): bool
    {
        $window = $this->state['windows'][$role];
        if (count($window) !== self::REPORT_COUNT) return false;
        $now = $this->now();
        foreach ($window as $row) if (!$this->fresh($row['agent_at'], $now) || !$this->fresh($row['checked_at'], $now)) return false;
        $last = end($window);
        return $role === 'candidate' || $last['failures'] >= self::REPORT_COUNT && $last['threshold'] === true;
    }

    private function dns(): Usdo1ExistingA
    {
        return new Usdo1ExistingA($this->scope['run_id'], $this->scope['baseline'], $this->state['roles']['source']['pin']['ipv4'],
            $this->scope['marker'], $this->scope['started_at'], $this->scope['deadline'],
            fn (string $name, string $id) => $this->call('read-dns', ['name' => $name, 'record_id' => $id]),
            fn (array $wire) => $this->call('update-dns', ['wire' => $wire]), $this->dnsAllowed(...),
            fn () => ($this->load)('dns'), fn (array $journal) => ($this->save)('dns', $journal), $this->clock);
    }

    private function dnsAllowed(string $operation, array $details): bool
    {
        return match ($operation) {
            'claim', 'publish-source' => $this->state['phase'] === 'adopt' && $this->canAdvance(),
            'native-evidence', 'native-cutover' => $this->state['phase'] === 'qualify-candidate' && $this->canAdvance()
                && $this->qualified('source') && $this->qualified('candidate')
                && ($details['plan']['after']['content'] ?? $details['after']['Value'] ?? null) === $this->state['roles']['candidate']['pin']['ipv4'],
            'stop-and-drain' => $this->drained(),
            'settle-intent' => $this->drained() && $this->settled($details['intent'], $details['observation']),
            'restore-source', 'restore-candidate', 'unclaim-baseline', 'unclaim-restored' => $this->state['phase'] === 'rollback'
                && $this->boundDns === null && $this->drained() && $this->rollbackTargetReady(),
            'external-rollback-readback' => $this->externalDns($details['baseline']),
            default => false,
        };
    }

    private function settled(array $intent, array $row): bool
    {
        $hash = self::digest($intent);
        $receipt = $this->call('settle-dns', ['intent_sha256' => $hash, 'intent' => $intent]);
        return ($receipt['intent_sha256'] ?? null) === $hash && ($receipt['in_flight'] ?? null) === false
            && Json::same($row, $intent[($receipt['applied'] ?? null) === true ? 'after' : 'before']) && is_bool($receipt['applied'] ?? null);
    }

    private function externalDns(array $row): bool
    {
        $answer = $this->call('read-external-dns', ['name' => Usdo1ExistingA::NAME, 'record_id' => $row['RecordId']]);
        $expected = [$row['Value']];
        return Json::same($answer, ['authority_1' => $expected, 'authority_2' => $expected,
            'recursive_1' => $expected, 'recursive_2' => $expected]);
    }

    private function drained(): bool
    {
        if (!$this->state['stop_sent']) return false;
        $stop = $this->call('read-stop');
        return ($stop['binding'] ?? null) === $this->binding && ($stop['write_fenced'] ?? null) === true
            && ($stop['in_flight'] ?? null) === 0 && ($stop['fault_restored'] ?? null) === true;
    }

    private function drain(): void
    {
        if (!$this->state['stop_sent']) {
            $this->state['stop_sent'] = true;
            $this->persist();
            $this->call('stop-writer');
        }
        if ($this->drained()) $this->state['phase'] = 'rollback';
    }

    private function rollbackTargetReady(): bool
    {
        $status = $this->call('read-rollback-target', ['pin' => $this->scope['rollback']]);
        return Json::same($status['pin'] ?? null, $this->scope['rollback']) && ($status['healthy'] ?? null) === true;
    }

    private function baselineUntouched(): bool
    {
        $row = $this->scope['baseline'];
        return ($this->load)('dns') === null && Json::same($this->call('read-dns', [
            'name' => Usdo1ExistingA::NAME, 'record_id' => $row['RecordId']]), [$row]);
    }

    private function rollback(): void
    {
        self::need($this->drained() && $this->rollbackTargetReady(), 'rollback_unverified');
        if (isset($this->state['roles']['source']['pin']) && ($this->load)('dns') !== null) {
            if ($this->boundDns !== null) {
                if (($this->boundDns)('restore', null) !== true) return;
            } else $this->dns()->rollback();
        }
        else self::need($this->baselineUntouched(), 'baseline_changed');
        $this->state['phase'] = 'cleanup';
    }

    private function cleanupAllowed(): bool
    {
        self::need($this->drained() && $this->rollbackTargetReady(), 'rollback_unverified');
        if (isset($this->state['roles']['source']['pin']) && ($this->load)('dns') !== null) {
            if ($this->boundDns !== null && ($this->boundDns)('clear-for-cleanup', null) !== true) return false;
            if ($this->boundDns === null) $this->dns()->confirmDnsClearForCleanup();
            else self::need($this->externalDns($this->scope['baseline']), 'baseline_changed');
        } else self::need($this->baselineUntouched() && $this->externalDns($this->scope['baseline']), 'baseline_changed');
        return true;
    }

    private function cleanup(): void
    {
        if (!$this->cleanupAllowed()) {
            $this->resetAbsenceReads();
            return;
        }
        // Resolve every paid intent before deleting anything, including a lost candidate create response.
        foreach (array_keys($this->state['roles']) as $role) if ($this->readProvision($role)['status'] === 'pending') {
            $this->resetAbsenceReads();
            return;
        }
        foreach (array_reverse(self::ROLES) as $role) {
            if (!isset($this->state['roles'][$role]['pin']) || $this->state['roles'][$role]['confirmed']) continue;
            $this->cleanResource($role);
            return;
        }
        $this->state['phase'] = 'closed';
        $this->state['passed'] = $this->state['cutover_verified'] && $this->state['first_error'] === null && $this->state['recovery_error'] === null;
    }

    private function resetAbsenceReads(): void
    {
        foreach ($this->state['roles'] as &$resource) if (!$resource['confirmed']) $resource['first_absent_at'] = null;
    }

    private function cleanResource(string $role): void
    {
        $resource = &$this->state['roles'][$role];
        $pin = $resource['pin'];
        $prior = $resource['first_absent_at'];
        $resource['first_absent_at'] = null;
        $receipt = $this->call('read-resource', ['role' => $role, 'pin' => $pin]);
        self::need(Json::same($receipt['pin'] ?? null, $pin) && in_array($receipt['status'] ?? null, ['present', 'absent'], true), 'cleanup_read_unverified');
        if ($receipt['status'] === 'absent') {
            $resource['first_absent_at'] = $prior ?? $this->now();
            $resource['confirmed'] = $this->now() - $resource['first_absent_at'] >= 10;
        } elseif (!$resource['delete_sent'] && $this->cleanupAllowed()) {
            $resource['delete_sent'] = true;
            $this->persist();
            $this->call('delete-resource', ['role' => $role, 'pin' => $pin]);
        }
    }
}

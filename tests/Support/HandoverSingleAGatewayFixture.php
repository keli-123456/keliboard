<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence as Evidence;
use App\Services\ServerMachine\Cloud\MachineIntentJson;
use PDO;

// Test-only gateway. There is no provider client, production lease, or route to this fixture.
final class HandoverSingleAGatewayFixture
{
    public const OPERATOR = 17;
    private const WINDOW_SECONDS = 7200;
    private const WORK = ['queued' => 0, 'in_flight' => 0, 'unresolved' => 0];
    private const WRITERS = ['legacy_active' => 'legacy-surrogate', 'forward_active' => 'keliboard',
        'rollback_active' => 'rollback-operator', 'legacy_restored' => 'legacy-surrogate'];

    public function __construct(private PDO $db, private HandoverSingleADnsFixture $dns, private Evidence $evidence)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new \LogicException('Fixture requires SQLite.');
    }

    public function initialize(int $now, array $legacyWork = []): array
    {
        $this->db->exec('CREATE TABLE rehearsal_gateway (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, state TEXT NOT NULL)');
        $s = ['run' => bin2hex(random_bytes(16)), 'epoch' => 0, 'deadline' => $now + self::WINDOW_SECONDS,
            'legacy_work' => $legacyWork + self::WORK, 'operations' => [], 'closures' => []];
        $grant = $this->advance($s, 'legacy_active', $now);
        $this->db->prepare('INSERT INTO rehearsal_gateway VALUES (1, 1, ?)')->execute([MachineIntentJson::encode($s)]);
        return $grant;
    }

    public function status(): array { return $this->load()[1]; }

    public function assertAccess(array $grant, int $now): void { $this->assertWriter($this->status(), $grant, $now); }

    public function stopWrites(int $now): void
    {
        [$rev, $s] = $this->load();
        $stopped = match ($s['phase']) {
            'legacy_active' => 'legacy_stopped', 'forward_active' => 'forward_stopped', 'rollback_active' => 'rollback_stopped',
            default => throw new CloudException('rehearsal_wrong_phase'),
        };
        $this->advance($s, $stopped, $now);
        $this->save($rev, $s);
    }

    public function stopScope(): array { return $this->stopScopeFor($this->status()); }

    public function takeOver(array $proof, int $now): array
    {
        [$rev, $s] = $this->load();
        $scope = $this->stopScopeFor($s);
        $this->assertWindow($s, $now);
        $receipt = $this->evidence->stopped($scope, self::OPERATOR, $proof, $now);
        if (!MachineIntentJson::same($s['legacy_work'], self::WORK) || $receipt['observed_at'] < $s['at']
            || $receipt['fence_sha256'] !== hash('sha256', $scope['fence_id'])
            || $receipt['baseline_sha256'] !== $scope['baseline_sha256']) {
            throw new CloudException('rehearsal_stop_not_observed');
        }
        $s['stop_receipt'] = $receipt;
        $grant = $this->advance($s, 'forward_active', $now);
        $this->save($rev, $s);
        return $grant;
    }

    public function write(array $grant, string $requestKey, array $desired, int $now): bool
    {
        [$rev, $s] = $this->load();
        $this->assertWriter($s, $grant, $now);
        $leg = match ($s['phase']) {
            'forward_active' => 'forward', 'rollback_active' => 'rollback',
            default => throw new CloudException('rehearsal_write_out_of_scope'),
        };
        [$before, $after] = $this->records($leg);
        if (!preg_match('/^[a-z0-9_-]{1,64}$/D', $requestKey) || !MachineIntentJson::same($desired, $after)) {
            throw new CloudException('rehearsal_write_out_of_scope');
        }
        if (isset($s['operations'][$leg])) {
            if ($s['operations'][$leg]['intent']['request_key'] === $requestKey) return false;
            throw new CloudException('rehearsal_write_already_dispatched');
        }
        $this->requireRecord($before);
        $this->requireHealthy($after['content']);
        $intent = ['operation_id' => $s['run'] . ':' . $leg, 'request_key' => $requestKey,
            'epoch' => $s['epoch'], 'before' => $before, 'after' => $after];
        $s['operations'][$leg] = ['intent' => $intent, 'dispatched_at' => $now, 'receipt' => null];
        $s['at'] = $now;

        // A committed dispatch with no result must be reconciled, never re-sent by a new holder.
        $this->save($rev, $s);
        if ($this->dns->send($intent)) $this->recordReceipt($intent['operation_id'], $now);
        return true;
    }

    public function recordReceipt(string $operationId, int $now): bool
    {
        [$rev, $s] = $this->load();
        foreach ($s['operations'] as $leg => $op) {
            if ($op['intent']['operation_id'] !== $operationId || $op['receipt'] !== null || isset($s['closures'][$leg])) continue;
            $receipt = $this->dns->finalObservation($op['intent']);
            if ($receipt === null) return false;
            $s['operations'][$leg]['receipt'] = $receipt;
            $s['at'] = $now;
            $this->save($rev, $s);
            return true;
        }
        return false;
    }

    public function reviewClosure(): array { return $this->closureFor($this->status()); }

    public function close(array $proof, int $now): void
    {
        [$rev, $s] = $this->load();
        $review = $this->closureFor($s);
        $scope = $review['scope'];
        $receipt = $this->evidence->closed($scope, $proof, $now);
        if (!MachineIntentJson::same($review['outcomes'], $receipt['outcomes'])) {
            throw new CloudException('rehearsal_closure_not_observed');
        }
        $s['closures'][$scope['leg']] = $receipt + ['operator_id' => self::OPERATOR, 'closed_at' => $now];
        $this->advance($s, $scope['leg'] . '_closed', $now);
        $this->save($rev, $s);
    }

    public function allowRollback(int $now): array
    {
        [$rev, $s] = $this->load();
        if ($s['phase'] !== 'forward_closed' || $this->result($s, 'forward') !== 'confirmed_applied') {
            throw new CloudException('rehearsal_rollback_not_ready');
        }
        $this->assertWindow($s, $now);
        $this->requireRecord(HandoverSingleADnsFixture::switched());
        $this->requireHealthy(HandoverSingleADnsFixture::OLD_IP);
        $grant = $this->advance($s, 'rollback_active', $now);
        $this->save($rev, $s);
        return $grant;
    }

    public function handBack(int $now): array
    {
        [$rev, $s] = $this->load();
        if (!($s['phase'] === 'rollback_closed' && $this->result($s, 'rollback') === 'confirmed_applied')
            && !($s['phase'] === 'forward_closed' && $this->result($s, 'forward') !== 'confirmed_applied')) {
            throw new CloudException('rehearsal_handback_not_ready');
        }
        $this->assertWindow($s, $now);
        $this->requireRecord(HandoverSingleADnsFixture::ORIGINAL);
        $this->requireHealthy(HandoverSingleADnsFixture::OLD_IP);
        if ($this->dns->resolvedIp() !== HandoverSingleADnsFixture::OLD_IP) throw new CloudException('rehearsal_dns_not_propagated');
        $grant = $this->advance($s, 'legacy_restored', $now);
        $this->save($rev, $s);
        return $grant;
    }

    private function stopScopeFor(array $s): array
    {
        if ($s['phase'] !== 'legacy_stopped') throw new CloudException('rehearsal_legacy_not_fenced');
        $this->requireRecord(HandoverSingleADnsFixture::ORIGINAL);
        return ['run' => $s['run'], 'service' => 'fixture-service', 'epoch' => $s['epoch'], 'expires_at' => $s['deadline'],
            'fence_id' => 'fixture-' . $s['run'], 'baseline_sha256' => Evidence::hash($this->dns->record()),
            'plan_sha256' => Evidence::hash($this->records('forward'))];
    }

    private function closureFor(array $s): array
    {
        $leg = match ($s['phase']) {
            'forward_stopped' => 'forward', 'rollback_stopped' => 'rollback',
            default => throw new CloudException('rehearsal_close_requires_stop'),
        };
        $op = $s['operations'][$leg] ?? null;
        $outcomes = $operations = [];
        if ($op !== null) {
            $observed = $this->dns->finalObservation($op['intent']);
            if ($observed === null || ($op['receipt'] !== null && !MachineIntentJson::same($op['receipt'], $observed))) {
                throw new CloudException('rehearsal_outcome_unknown');
            }
            $operations[] = ['operation_id' => $op['intent']['operation_id'], 'intent_sha256' => Evidence::hash($op['intent']),
                'known_outcome' => $op['receipt']['result'] ?? null];
            $outcomes[] = $observed;
        } else {
            $this->requireRecord($this->records($leg)[0]);
        }

        return ['scope' => ['run' => $s['run'], 'service' => 'fixture-service', 'leg' => $leg, 'epoch' => $s['epoch'],
            'settled_at' => $s['at'], 'journal_sha256' => Evidence::hash($s['operations']),
            'record_sha256' => Evidence::hash($this->dns->record()), 'operations' => $operations], 'outcomes' => $outcomes];
    }

    private function records(string $leg): array
    {
        $pair = [HandoverSingleADnsFixture::ORIGINAL, HandoverSingleADnsFixture::switched()];
        return $leg === 'forward' ? $pair : array_reverse($pair);
    }

    private function result(array $s, string $leg): ?string { return $s['closures'][$leg]['outcomes'][0]['result'] ?? null; }

    private function requireRecord(array $expected): void
    {
        if (!MachineIntentJson::same($expected, $this->dns->record())) throw new CloudException('rehearsal_dns_drift');
    }

    private function requireHealthy(string $ip): void
    {
        if (!$this->dns->healthy($ip)) throw new CloudException('rehearsal_endpoint_unhealthy');
    }

    private function assertWriter(array $s, array $grant, int $now): void
    {
        if ($s['token_sha256'] === null || ($grant['writer'] ?? null) !== (self::WRITERS[$s['phase']] ?? null)
            || ($grant['run'] ?? null) !== $s['run'] || ($grant['epoch'] ?? null) !== $s['epoch']
            || !is_string($grant['token'] ?? null) || !hash_equals($s['token_sha256'], hash('sha256', $grant['token']))) {
            throw new CloudException('rehearsal_writer_fenced');
        }
        $this->assertWindow($s, $now);
    }

    private function assertWindow(array $s, int $now): void
    {
        if ($now >= $s['deadline']) throw new CloudException('rehearsal_window_expired');
    }

    private function advance(array &$s, string $phase, int $now): ?array
    {
        $s['phase'] = $phase;
        $s['epoch']++;
        $s['at'] = $now;
        $s['token_sha256'] = null;
        if (!isset(self::WRITERS[$phase])) return null;

        $token = bin2hex(random_bytes(32));
        $s['token_sha256'] = hash('sha256', $token);
        return ['run' => $s['run'], 'epoch' => $s['epoch'], 'writer' => self::WRITERS[$phase], 'token' => $token];
    }

    private function load(): array
    {
        $row = $this->db->query('SELECT revision, state FROM rehearsal_gateway WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        return [(int) $row['revision'], json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR)];
    }

    private function save(int $revision, array $s): void
    {
        $update = $this->db->prepare('UPDATE rehearsal_gateway SET revision = revision + 1, state = ? WHERE id = 1 AND revision = ?');
        $update->execute([MachineIntentJson::encode($s), $revision]);
        if ($update->rowCount() !== 1) throw new CloudException('rehearsal_state_changed');
    }
}

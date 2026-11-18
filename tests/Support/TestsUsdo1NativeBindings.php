<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Models\MachineFailoverPlan;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence as Evidence;
use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Keli\Acceptance\Usdo1ExistingA;
use Keli\Acceptance\Usdo1Native;
use Keli\Acceptance\Usdo1Rollback;
use Keli\Acceptance\Usdo1Round;

require_once dirname(__DIR__, 2) . '/tools/machine-acceptance/usdo1_rollback.php';
require_once dirname(__DIR__, 2) . '/tools/machine-acceptance/usdo1_round.php';

trait TestsUsdo1NativeBindings
{
    private function usdo1Refuses(string $reason, \Closure $operation): void
    {
        try { $operation(); self::fail('Expected refusal: ' . $reason); }
        catch (CloudException $error) { self::assertSame($reason, $error->getMessage()); }
    }

    public function test_usdo1_bound_native_holds_publication_and_uses_independent_stopped_rollback(): void
    {
        $this->nodeReadiness = true;
        app()->instance('env', 'testing');
        (require base_path('database/migrations/2026_11_07_220000_create_machine_failover_runtime.php'))->up();
        $targets = ['192.0.1.31', '192.0.1.32', '192.0.1.33', '192.0.1.34'];
        $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
            'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => $targets]);
        $packagedBinary = getenv('KELI_USDO1_PACKAGED_ELF_SHA256');
        if ($packagedBinary !== false) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $packagedBinary);
            $this->release->fill(['binary_sha256' => $packagedBinary])->save();
        }
        $this->fixtures = [$fixture = $this->fixture(2, usdo1: true)];
        $journal = []; $wires = []; $events = []; $pins = []; $present = []; $absent = [];
        $load = function (string $name) use (&$journal): ?array { return $journal[$name] ?? null; };
        $save = function (string $name, array $value) use (&$journal): void {
            $journal[$name] = json_decode(Json::encode($value), true, flags: JSON_THROW_ON_ERROR);
        };
        $now = $startedAt = time(); $clock = function () use (&$now): int { return $now; };
        $runId = 'keli-accept-usdo1-native-fixture';
        $f = $this->startSets(['A'], 'aliyun', legacyDns: false, usdo1: function ($service, $view) use ($load, $save, $runId, $startedAt) {
            config(['machine_failover.runner_enabled' => false, 'machine_failover.allow_rotation' => false,
                'machine_acceptance.allow_verification' => false]);
            $fixture = $this->fixtures[0];
            $id = (string) array_key_first(array_filter($this->records, fn ($row) => ($row['DomainName'] ?? null) === Usdo1ExistingA::ZONE
                && ($row['RR'] ?? null) === 'usdo1'));
            $baseline = array_replace($this->records[$id], ['Value' => '192.0.1.17', 'TTL' => 60, 'Remark' => 'original-mark', 'Status' => 'Enable']);
            $marker = $this->records[$id]['Remark']; $this->records[$id] = $baseline;
            $plan = MachineFailoverPlan::query()->where('policy_id', $fixture['policy']->id)->latest('id')->firstOrFail();
            $scope = ['run_id' => $runId, 'record_id' => $id, 'service_id' => $service['id'],
                'service_revision' => $view['service_revision'], 'plan_id' => $plan->id, 'policy_id' => $fixture['policy']->id,
                'source_task_id' => $fixture['source']->id, 'release_id' => $this->release->id, 'admin_id' => $this->admin->id,
                'account_id' => $fixture['accounts'][0]->id, 'stop_new_work_at' => $startedAt + 5100];
            return ['id' => $id, 'baseline' => $baseline, 'marker' => $marker, 'episode' => $plan->evidence['episode_key'],
                'native' => new Usdo1Native($scope, $load, $save)];
        });
        ['native' => $native, 'baseline' => $baseline, 'id' => $id, 'marker' => $marker, 'episode' => $episode] = $f;
        $target = ['machine_id' => 17, 'external_id' => '11017', 'ipv4' => $baseline['Value']];
        $scope = ['run_id' => $runId, 'account_id' => $fixture['accounts'][0]->id, 'protected_ids' => ['11017', '11008'], 'rollback' => $target,
            'baseline' => $baseline, 'marker' => $marker, 'started_at' => $startedAt, 'deadline' => $startedAt + 7200,
            'probe_ipv4_targets' => $targets, 'binary_sha256' => $this->release->binary_sha256, 'bound_native_dns' => true];
        $read = function (string $name, string $recordId) use ($id): array {
            self::assertSame(Usdo1ExistingA::NAME, $name); self::assertSame($id, $recordId);
            return [$this->records[$id]];
        };
        $write = function (string $lane, array $wire) use ($id, $native, &$wires, &$events): array {
            self::assertSame($id, $wire['parameters']['RecordId']);
            self::assertSame($lane === 'rollback', $native->stoppedSnapshot() !== null);
            $wires[] = [$lane, $wire['action']];
            $events[] = $lane . ':' . $wire['action'];
            foreach (['TTL', 'Value', 'Remark'] as $field) if (array_key_exists($field, $wire['parameters']))
                $this->records[$id][$field] = $wire['parameters'][$field];
            return ['RequestId' => 'fixture-writer', 'RecordId' => $id];
        };
        $lag = null;
        $resolve = function () use ($id, &$lag): array {
            $current = [$this->records[$id]['Value']];
            return ['authority_1' => $current, 'authority_2' => $current, 'recursive_1' => $current, 'recursive_2' => $lag === null ? $current : [$lag]];
        };
        $resolversClear = function (array $row) use ($resolve): bool {
            return Json::same($resolve(), array_fill_keys(['authority_1', 'authority_2', 'recursive_1', 'recursive_2'], [$row['Value']]));
        };
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $verifier = new Evidence([Evidence::CLOSE => ['isolated-verifier' => openssl_pkey_get_details($key)['key']]]);
        $fault = null; $allowClose = false;
        $faultReceipt = function () use (&$fault): ?string { return $fault; };
        $rollback = new Usdo1Rollback($native, ['run_id' => $runId, 'baseline' => $baseline,
            'target' => $target, 'source_ipv4' => $fixture['sourceIp'], 'marker' => $marker,
            'started_at' => $startedAt, 'deadline' => $startedAt + 7200], $verifier, $read,
            fn ($wire) => $write('rollback', $wire), fn () => $load('dns'), fn ($value) => $save('dns', $value),
            fn ($pin) => Json::same($pin, $target), $resolversClear, $faultReceipt, $clock);
        $sign = function (array $closedScope) use ($key, &$now): array {
            $body = ['scope_sha256' => Evidence::hash($closedScope), 'observed_at' => $now, 'valid_until' => $now + 120,
                'fence_state' => 'fenced_until_handback', 'in_flight' => 0, 'outcomes' => array_map(fn ($row) => [
                    'operation_id' => $row['operation_id'], 'result' => $row['known_outcome'] ?? 'confirmed_applied',
                    'basis' => 'independent_exact_reconciliation', 'evidence_sha256' => str_repeat('e', 64)], $closedScope['operations'])];
            self::assertTrue(openssl_sign(Evidence::signingInput(Evidence::CLOSE, 'isolated-verifier', $body), $signature, $key, OPENSSL_ALGO_SHA256));
            return ['issuer' => 'isolated-verifier', 'body' => $body, 'signature' => base64_encode($signature)];
        };
        $boundDns = function (string $operation, ?Usdo1ExistingA $dns) use ($native, $rollback, $sign, $resolversClear, $baseline,
            &$allowClose, &$events): bool {
            if ($operation === 'publish') {
                self::assertNotNull($dns);
                $state = $native->advance($dns);
                self::assertSame('reconciling_dns', $state['phase']);
                return true;
            }
            self::assertNotNull($native->stoppedSnapshot());
            $closed = $rollback->closureScope();
            if ($operation === 'restore') {
                if (!$allowClose) {
                    $this->usdo1Refuses('handover_evidence_scope_changed',
                        fn () => $rollback->restore($sign(array_replace($closed, ['record_id' => '123']))));
                    return false;
                }
                $rollback->restore($sign($closed));
                return true;
            }
            self::assertSame('clear-for-cleanup', $operation);
            if (!$resolversClear($baseline)) return false;
            $rollback->confirmClearForCleanup($sign($closed));
            $events[] = 'clear-for-cleanup';
            return true;
        };
        $failures = 0;
        $port = function (string $op, array $args) use ($scope, $read, $write, $load, $resolve, $target, $fixture, $native, $rollback, $episode, $resolversClear,
            &$now, &$pins, &$present, &$absent, &$events, &$fault, &$failures): array {
            $role = $args['role'] ?? null;
            switch ($op) {
                case 'dispatch-provision':
                    self::assertArrayNotHasKey($role, $pins);
                    if ($role === 'candidate') {
                        self::assertCount(3, $load('round')['windows']['source']);
                        self::assertSame($episode, $args['episode_key']);
                        $first = $native->start($args['request_id'], $episode);
                        self::assertSame($first, $native->start($args['request_id'], $episode));
                        $this->usdo1Refuses('usdo1_native_intent_changed', fn () => $native->start(str_repeat('2', 32), $episode));
                        self::assertSame('waiting_initialization', $native->advance()['phase']);
                        $task = $this->task();
                        self::assertTrue(app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded'));
                        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
                        $this->report($fixture, $task, $task->public_ips[0], true, time());
                        $state = $native->advance();
                        self::assertSame('awaiting_dns', $state['phase'], $state['reason'] ?? '');
                        $native->advance();
                        self::assertCount(1, $this->posts);
                    }
                    [$external, $ipv4, $installation] = match ($role) {
                        'control' => ['15001', '192.0.1.21', null],
                        'source' => [$fixture['source']->external_id, $fixture['sourceIp'], $fixture['source']->installation_id],
                        'candidate' => [$task->external_id, $task->public_ips[0], $task->installation_id],
                    };
                    $pins[$role] = ['external_id' => $external, 'ipv4' => $ipv4, 'installation_id' => $installation, 'created_at' => $now]
                        + array_intersect_key($args, array_flip(['account_id', 'label', 'request_id']));
                    $present[$role] = true;
                    $events[] = 'create:' . $role;
                    return [];
                case 'read-provision':
                    return ['intent' => $load('round')['roles'][$role]['intent'], 'status' => 'created', 'ready' => true, 'pin' => $pins[$role]];
                case 'read-observation':
                    self::assertTrue(Json::same($pins[$role], $args['pin']));
                    $rows = array_map(fn ($ip, $index) => ['id' => substr(hash('sha256', $ip), 0, 16), 'source_ip' => $pins[$role]['ipv4'],
                        'status' => $role === 'candidate' && $index === 0 ? 'reachable' : 'unreachable'], $scope['probe_ipv4_targets'], range(0, 3));
                    return ['pin' => $pins[$role], 'binary_sha256' => $scope['binary_sha256'], 'session' => str_repeat('a', 32),
                        'agent_at' => $now, 'probe' => ['schema' => 1, 'revision' => $args['probe_revision'],
                            'state' => 'ready', 'checked_at' => $now, 'targets' => $rows],
                        'native' => $role === 'source' ? ['checked_at' => $now, 'state' => 'failed', 'context' => str_repeat('f', 64),
                            'failures' => ++$failures, 'threshold_reached' => $failures >= 3, 'episode_key' => $failures >= 3 ? $episode : null] : null];
                case 'stop-writer':
                    $native->stop();
                    $native->stop();
                    $events[] = 'stopped';
                    return [];
                case 'read-stop':
                    $stopped = $native->stoppedSnapshot() !== null;
                    return ['binding' => $args['binding'], 'write_fenced' => $stopped, 'in_flight' => $stopped ? 0 : 1, 'fault_restored' => $fault !== null];
                case 'read-dns':
                    return $read($args['name'], $args['record_id']);
                case 'update-dns':
                    $pending = $load('dns')['pending'];
                    self::assertTrue(Json::same($pending['wire'], $args['wire']));
                    self::assertContains($pending['step'], ['claim', 'publish-source', 'native-cutover']);
                    return $write($pending['step'] === 'native-cutover' ? 'native' : 'setup', $args['wire']);
                case 'read-rollback-target':
                    return ['pin' => $target, 'healthy' => true];
                case 'read-external-dns':
                    return $resolve();
                case 'read-resource':
                    self::assertTrue(Json::same($pins[$role], $args['pin']));
                    if (!$present[$role]) $absent[$role][] = $now;
                    return ['pin' => $pins[$role], 'status' => $present[$role] ? 'present' : 'absent'];
                case 'delete-resource':
                    self::assertTrue(Json::same($pins[$role], $args['pin']));
                    self::assertTrue(Json::same($scope['baseline'], $read(Usdo1ExistingA::NAME, $scope['baseline']['RecordId'])[0]));
                    self::assertTrue($resolversClear($scope['baseline']));
                    self::assertSame('clear-for-cleanup', end($events));
                    self::assertNotContains($pins[$role]['external_id'], $scope['protected_ids']);
                    self::assertTrue($present[$role]);
                    $present[$role] = false; $events[] = 'delete:' . $role;
                    return [];
            }
            throw new \LogicException('Unexpected usdo1 fixture port.');
        };
        $tick = function (int $seconds = 5) use ($scope, $port, $load, $save, $clock, $boundDns, &$now): array {
            $now += $seconds;
            return (new Usdo1Round($scope, $port, $load, $save, $clock, $boundDns))->step();
        };
        $reach = function (string $phase) use ($tick, $load): array {
            $state = $load('round') ?? [];
            for ($i = 0; $i < 24 && ($state['phase'] ?? null) !== $phase; $i++) $state = $tick();
            self::assertSame($phase, $state['phase'], Json::encode(array_intersect_key($state, ['first_error' => true, 'recovery_error' => true])));
            return $state;
        };

        $reach('qualify-candidate');
        self::assertCount(2, $wires);
        $this->usdo1Refuses('usdo1_rollback_stop_not_confirmed', fn () => $rollback->restore([]));
        $reach('confirm-cutover');
        self::assertSame($pins['candidate']['ipv4'], $this->records[$id]['Value']);
        $lag = $fixture['sourceIp'];
        self::assertFalse($tick()['cutover_verified']);
        $lag = null;
        self::assertTrue($tick()['cutover_verified']);
        self::assertSame('drain', $tick()['phase']); // Fence alone is not fault recovery.
        $this->usdo1Refuses('usdo1_native_fenced', fn () => $native->advance());
        $this->usdo1Refuses('usdo1_rollback_fault_restoration_unconfirmed', fn () => $rollback->restore([]));
        $fault = str_repeat('f', 64);
        self::assertSame('rollback', $tick()['phase']);
        self::assertSame('rollback', $tick()['phase']); // Wrong closure signature scope is not authority.
        self::assertCount(3, $wires);
        $allowClose = true;
        self::assertSame('cleanup', $tick()['phase']);
        self::assertTrue(Json::same($baseline, $this->records[$id]));
        $deletes = function () use (&$events): array {
            return array_values(array_filter($events, fn ($event) => str_starts_with($event, 'delete:')));
        };
        self::assertSame('cleanup', $tick(599)['phase']);
        self::assertSame([], $deletes());
        $lag = $pins['candidate']['ipv4'];
        self::assertSame('cleanup', $tick()['phase']);
        self::assertSame([], $deletes());
        $lag = null;
        $final = $reach('closed');
        self::assertTrue($final['passed']);
        self::assertSame([['setup', 'UpdateDomainRecordRemark'], ['setup', 'UpdateDomainRecord'], ['native', 'UpdateDomainRecord'],
            ['rollback', 'UpdateDomainRecord'], ['rollback', 'UpdateDomainRecordRemark']], $wires);
        self::assertSame(['delete:candidate', 'delete:source', 'delete:control'], $deletes());
        foreach ($absent as $reads) self::assertGreaterThanOrEqual(10, end($reads) - $reads[0]);
        self::assertCount(3, $absent);
        self::assertCount(1, $this->posts);
        self::assertSame(0, $this->dnsWrites);
        self::assertSame(0, $this->verifications);
        self::assertFalse(config('machine_failover.runner_enabled'));
        self::assertFalse(config('machine_failover.allow_rotation'));
    }
}

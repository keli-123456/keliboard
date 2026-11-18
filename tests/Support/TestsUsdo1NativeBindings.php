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

require_once dirname(__DIR__, 2) . '/tools/machine-acceptance/usdo1_rollback.php';

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
        $this->fixtures = [$this->fixture(2, usdo1: true)];
        $journal = []; $wires = [];
        $load = function (string $name) use (&$journal): ?array { return $journal[$name] ?? null; };
        $save = function (string $name, array $value) use (&$journal): void { $journal[$name] = $value; };
        $now = time(); $clock = function () use (&$now): int { return $now; };
        $this->startSets(['A'], 'aliyun', legacyDns: false, usdo1: function ($service, $view) use ($load, $save, $clock, $now, &$f, &$wires) {
            config(['machine_failover.runner_enabled' => false, 'machine_failover.allow_rotation' => false,
                'machine_acceptance.allow_verification' => false]);
            $fixture = $this->fixtures[0];
            $id = (string) array_key_first(array_filter($this->records, fn ($row) => ($row['DomainName'] ?? null) === Usdo1ExistingA::ZONE
                && ($row['RR'] ?? null) === 'usdo1'));
            $baseline = array_replace($this->records[$id], ['Value' => '192.0.1.17', 'TTL' => 60, 'Remark' => 'original-mark', 'Status' => 'Enable']);
            $marker = $this->records[$id]['Remark']; $this->records[$id] = $baseline;
            $read = function (string $name, string $recordId) use ($id): array {
                self::assertSame(Usdo1ExistingA::NAME, $name); self::assertSame($id, $recordId);
                return [$this->records[$id]];
            };
            $transport = function (string $lane, array $wire) use ($id, &$wires): array {
                self::assertSame($id, $wire['parameters']['RecordId']);
                $wires[] = [$lane, $wire['action']];
                foreach (['TTL', 'Value', 'Remark'] as $field) if (array_key_exists($field, $wire['parameters']))
                    $this->records[$id][$field] = $wire['parameters'][$field];
                return ['RequestId' => 'fixture-writer', 'RecordId' => $id];
            };
            $plan = MachineFailoverPlan::query()->where('policy_id', $fixture['policy']->id)->latest('id')->firstOrFail();
            $scope = ['run_id' => 'keli-accept-usdo1-native-fixture', 'record_id' => $id, 'service_id' => $service['id'],
                'service_revision' => $view['service_revision'], 'plan_id' => $plan->id, 'policy_id' => $fixture['policy']->id,
                'source_task_id' => $fixture['source']->id, 'release_id' => $this->release->id, 'admin_id' => $this->admin->id,
                'account_id' => $fixture['accounts'][0]->id, 'stop_new_work_at' => $now + 5000];
            $episode = $plan->evidence['episode_key'];
            $loadDns = fn () => $load('dns'); $saveDns = fn ($value) => $save('dns', $value);
            $worker = new Usdo1ExistingA($scope['run_id'], $baseline, $fixture['sourceIp'], $marker, $now, $now + 7200,
                $read, fn ($wire) => $transport('worker', $wire), fn ($op) => in_array($op, ['claim', 'publish-source', 'native-evidence', 'native-cutover'], true),
                $loadDns, $saveDns, $clock);
            $worker->adopt();
            $native = new Usdo1Native($scope, $load, $save);
            $first = $native->start(str_repeat('1', 32), $episode);
            self::assertSame($first, $native->start(str_repeat('1', 32), $episode));
            $this->usdo1Refuses('usdo1_native_intent_changed', fn () => $native->start(str_repeat('2', 32), $episode));
            $f = compact('id', 'baseline', 'read', 'transport', 'scope', 'loadDns', 'saveDns', 'worker', 'native', 'marker');
            return $first;
        });
        ['native' => $native, 'worker' => $worker, 'baseline' => $baseline, 'id' => $id] = $f;
        self::assertSame('waiting_initialization', $native->advance()['phase']);
        $task = $this->task();
        self::assertTrue(app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded'));
        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        $state = $native->advance();
        self::assertSame('awaiting_dns', $state['phase'], $state['reason'] ?? '');
        $native->advance(); $native->advance();
        self::assertCount(2, $wires, 'Only the initial source staging writes are allowed before the explicit publisher.');
        self::assertCount(1, $this->posts);
        self::assertSame(0, $this->verifications);
        $state = $native->advance($worker);
        self::assertSame('reconciling_dns', $state['phase'], $state['reason'] ?? '');
        self::assertSame($task->public_ips[0], $this->records[$id]['Value']);
        self::assertCount(3, $wires);
        self::assertSame(0, $this->dnsWrites, 'Direct native provider writes must remain unavailable.');

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $verifier = new Evidence([Evidence::CLOSE => ['isolated-verifier' => openssl_pkey_get_details($key)['key']]]);
        $fault = null;
        $faultReceipt = function () use (&$fault): ?string { return $fault; };
        $rollback = new Usdo1Rollback($native, ['run_id' => $f['scope']['run_id'], 'baseline' => $baseline,
            'target' => ['machine_id' => 17, 'external_id' => '11017', 'ipv4' => $baseline['Value']], 'source_ipv4' => $this->fixtures[0]['sourceIp'],
            'marker' => $f['marker'], 'started_at' => $now, 'deadline' => $now + 7200], $verifier, $f['read'],
            fn ($wire) => ($f['transport'])('rollback', $wire), $f['loadDns'], $f['saveDns'], fn ($pin) => $pin['machine_id'] === 17,
            fn ($record) => Json::same($record, $baseline), $faultReceipt, $clock);
        $this->usdo1Refuses('usdo1_rollback_stop_not_confirmed', $rollback->closureScope(...));
        $native->stop(); $native->stop();
        $this->usdo1Refuses('usdo1_native_fenced', fn () => $native->advance($worker));
        $this->usdo1Refuses('usdo1_rollback_fault_restoration_unconfirmed', $rollback->closureScope(...));
        $fault = str_repeat('f', 64);
        $scope = $rollback->closureScope();
        $now = max($now, $scope['settled_at']);
        $sign = function (array $scope) use ($key, &$now): array {
            $body = ['scope_sha256' => Evidence::hash($scope), 'observed_at' => $now, 'valid_until' => $now + 120,
                'fence_state' => 'fenced_until_handback', 'in_flight' => 0, 'outcomes' => array_map(fn ($row) => [
                    'operation_id' => $row['operation_id'], 'result' => $row['known_outcome'] ?? 'confirmed_applied',
                    'basis' => 'independent_exact_reconciliation', 'evidence_sha256' => str_repeat('e', 64)], $scope['operations'])];
            openssl_sign(Evidence::signingInput(Evidence::CLOSE, 'isolated-verifier', $body), $signature, $key, OPENSSL_ALGO_SHA256);
            return ['issuer' => 'isolated-verifier', 'body' => $body, 'signature' => base64_encode($signature)];
        };
        $this->usdo1Refuses('handover_evidence_scope_changed', fn () => $rollback->restore($sign(array_replace($scope, ['record_id' => '123']))));
        self::assertCount(3, $wires);
        $rollback->restore($sign($scope));
        self::assertTrue(Json::same($baseline, $this->records[$id]));
        self::assertSame([['rollback', 'UpdateDomainRecord'], ['rollback', 'UpdateDomainRecordRemark']], array_slice($wires, 3));
        $now += 600;
        $rollback->confirmClearForCleanup($sign($rollback->closureScope()));
        self::assertCount(1, $this->posts);
        self::assertFalse(config('machine_failover.runner_enabled'));
        self::assertFalse(config('machine_failover.allow_rotation'));
    }
}

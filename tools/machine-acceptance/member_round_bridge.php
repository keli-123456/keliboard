<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineProvisionTask as Task;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Services\ServerMachine\Cloud\MachineCandidateCleanupClient;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineIntentJson;
use App\Services\ServerMachine\Dns\DnsProviderClient;

/** Fixed RPCs for a preflighted, already prepared isolated controller and sources. */
final class MemberRoundBridge
{
    public function __construct(private array $scope, private MemberCliAuthority $authority,
        private \Closure $load, private \Closure $save, private \Closure $clock) {}

    public static function authorizeRequest(array $request, MemberCliAuthority $authority): void
    {
        $keys = ['schema', 'run_id', 'grant_id', 'grant_sha256', 'bundle_sha256', 'request_id', 'sequence', 'role', 'operation'];
        self::need(count($request) === count($keys) && !array_diff(array_keys($request), $keys)
            && ($request['schema'] ?? null) === 2 && is_int($request['sequence'] ?? null) && $request['sequence'] >= 0
            && is_string($request['request_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $request['request_id']), 'request_invalid');
        foreach ($authority->identity() as $key => $value) self::need(($request[$key] ?? null) === $value, 'request_binding_changed');
        $operation = $request['operation']; $role = $request['role'];
        self::need(is_string($operation) && is_string($role), 'request_invalid');
        $authority->allow($operation, $role);
    }

    public function dispatch(array $request): array
    {
        self::authorizeRequest($request, $this->authority);
        $operation = $request['operation']; $role = $request['role'];
        $mutation = in_array($operation, ['round-step', 'source-register', 'task-delete'], true);
        $key = 'rpc-' . $request['request_id'];
        $prior = ($this->load)($key);
        if ($mutation && $prior !== null) {
            self::need(($prior['request'] ?? null) === $request, 'request_reused');
            self::need(isset($prior['reply']), 'uncertain_request_readback_required');
            return $prior['reply'];
        }
        if ($mutation) {
            $latest = ($this->load)('rpc-mutation');
            // Cleanup uses its own exact-ID delete intent and remains available after stop.
            if ($operation !== 'task-delete') self::need(!$latest || ($latest['completed'] ?? false), 'uncertain_request_readback_required');
            if ($latest) self::need($request['sequence'] > $latest['sequence'], 'request_sequence_reused');
            ($this->save)('rpc-mutation', ['sequence' => $request['sequence'], 'completed' => false]);
            ($this->save)($key, ['request' => $request]);
        }
        $result = $this->authority->within($operation, $role, function () use ($operation, $role, $request) {
            if ($operation === 'round-stop') {
                ($this->save)('round-stop', ['grant_id' => $this->scope['grant_id'], 'stopped' => true]);
                $this->authority->stopCallbacks();
                return ['stopped' => true];
            }
            if ($operation === 'round-evidence') return $this->evidence();
            if (!in_array($operation, ['round-status', 'task-inspect', 'task-delete'], true)) $this->authority->assertData();
            if ($operation === 'round-ready') return ['ready' => true, 'automatic_switches_changed' => false];
            if ($operation === 'round-status') return $this->status();
            if ($operation === 'round-dns-cleanup') {
                $controller = new MemberSetupController(MemberSetupAuthority::open(), true);
                try { return $controller->nativeDnsCleanup($this->scope, $this->authority); }
                finally { $controller->close(); }
            }
            if ($operation === 'round-step') return $this->advance();
            $registry = $this->registry($operation);
            $scope = $this->transportScope();
            $bridge = new MemberControllerBridge($scope, $registry,
                function ($op, $r): void { $this->authority->allow($op, $r); }, $this->load, $this->save);
            return $bridge->dispatch(['schema' => 1, 'run_id' => $scope['run_id'], 'request_id' => $request['request_id'],
                'operation' => $operation, 'role' => $role])['result'];
        });
        $reply = $request + ['ok' => true, 'result' => $result];
        if ($mutation) {
            ($this->save)($key, ['request' => $request, 'reply' => $reply]);
            ($this->save)('rpc-mutation', ['sequence' => $request['sequence'], 'completed' => true]);
        }
        return $reply;
    }

    public static function safeDiagnostic(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $classes = ['ErrorException', 'RuntimeException', 'LogicException', 'TypeError', 'Error', 'JsonException',
            'QueryException', 'ModelNotFoundException', 'LockTimeoutException', 'CloudException', 'CloudRequestFailure', 'ConnectionException'];
        $files = ['member_retry_driver.php', 'member_source_initialization.php', 'member_setup_controller.php',
            'member_setup_dns.php', 'member_provider_bindings.php', 'member_native_dns_receipt.php',
            'member_round_bridge.php', 'member_native_controller.php', 'member_cli_authority.php',
            'member_setup_authority.php', 'member_source_configuration.php', 'member_dns_initialization.php',
            'member_application_bootstrap.php'];
        $file = ($value['site'] ?? null) === 'member_retry_driver' ? 'member_retry_driver.php' : ($value['file'] ?? null);
        $known = in_array($file, $files, true) && is_int($value['line'] ?? null) && $value['line'] > 0 && $value['line'] <= 1000000;
        return ['class' => in_array($value['class'] ?? null, $classes, true) ? $value['class'] : 'other',
            'file' => $known ? $file : null, 'line' => $known ? $value['line'] : null];
    }

    private function evidence(): array
    {
        // Keep error recovery usable even if a native binding is no longer valid.
        // Unknown lineage is flagged, never adopted or treated as cleaned.
        $resolved = true; $diagnostic = null; $pins = $this->scope['sources'];
        try { $this->authority->assertData(); $pins = $this->taskPins(); }
        catch (\Throwable $error) {
            $resolved = false;
            $diagnostic = self::safeDiagnostic(['class' => (new \ReflectionClass($error))->getShortName(),
                'file' => basename($error->getFile()), 'line' => $error->getLine()]);
        }
        $tasks = [];
        foreach ($pins as $role => $pin) {
            $task = Task::query()->find($pin['task_id']);
            $bound = $task !== null && $task->account_id === $this->scope['driver']['account_id'];
            foreach (['machine_id', 'installation_id', 'external_id', 'marker', 'account_revision', 'provider_created_at'] as $field)
                $bound = $bound && isset($pin[$field]) && $task->{$field} === $pin[$field];
            $tasks[$role] = ['task_id' => $pin['task_id'], 'binding_current' => $bound];
            if (!$bound) { $resolved = false; continue; }
            $value = $task->evidence[\App\Services\ServerMachine\Cloud\MachineProvisionDiagnostic::KEY] ?? null;
            $tasks[$role] += ['status' => in_array($task->status, [...Task::TERMINAL, 'queued', 'creating', 'polling', 'bootstrapping', 'waiting_reports'], true)
                    ? $task->status : 'other', 'first_error_present' => $value !== null,
                'first_diagnostic' => \App\Services\ServerMachine\Cloud\MachineProvisionDiagnostic::safe($value)];
        }
        $state = ($this->load)('round-driver'); $branches = [];
        foreach (['native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover', 'retry_exhausted', 'final_cleanup'] as $key)
            $branches[$key] = ($state['branches'][$key] ?? null) === true;
        $setup = ['available' => false];
        if (file_exists(MemberCliAuthority::ROOT . '/member-setup-grant.json') || is_link(MemberCliAuthority::ROOT . '/member-setup-grant.json')) {
            try {
                $controller = new MemberSetupController(MemberSetupAuthority::open(), true);
                try { $setup = $controller->evidence(); } finally { $controller->close(); }
            } catch (\Throwable $error) {
                $setup = ['available' => false, 'diagnostic' => self::safeDiagnostic([
                    'class' => (new \ReflectionClass($error))->getShortName(), 'file' => basename($error->getFile()), 'line' => $error->getLine()])];
            }
        }
        $body = ['schema' => 1] + $this->authority->identity() + [
            'scope_sha256' => hash('sha256', MachineIntentJson::encode($this->scope)), 'stopped' => true,
            'reconciled' => $resolved, 'reconcile_diagnostic' => $diagnostic, 'tasks' => $tasks,
            'driver' => ['present' => $state !== null, 'branches' => $branches,
                'first_error_present' => ($state['first_error'] ?? null) !== null,
                'first_diagnostic' => self::safeDiagnostic($state['first_diagnostic'] ?? null)],
            'setup' => $setup, 'cleanup_complete_not_asserted' => true];
        $content = MachineIntentJson::encode($body);
        self::need(strlen($content) <= 65536, 'evidence_too_large');
        $result = ['schema' => 1, 'sha256' => hash('sha256', $content), 'content' => $content];
        $key = 'evidence-' . $result['sha256'];
        ($this->save)($key, $result);
        self::need(($this->load)($key) === $result, 'evidence_readback_failed');
        return $result;
    }

    private function registry(string $operation): MemberSourceRegistry
    {
        return new MemberSourceRegistry($this->transportScope(),
            function ($_op, $role) use ($operation): void {
                $this->authority->allow($operation, $operation === 'round-step' ? 'control' : $role);
            }, $this->load, $this->save, $this->clock);
    }

    private function advance(): array
    {
        $restored = ($this->load)('round-driver');
        if ($restored && in_array($restored['phase'], ['cleanup', 'closed'], true)) return $this->projection($restored);
        $registry = $this->registry('round-step');
        // Read outside the driver's nested gate: DNS read guards must not recursively
        // re-enter its B-member comparison. Check again after every native step.
        $b = $this->controlFacts();
        $port = function (string $operation, array $input) use ($registry, $b): array {
            $this->authority->allow('round-step');
            if ($operation === 'prepare_role') {
                // Sources are already initialized in this grant. Re-registering
                // their fresh samples must not leave the pinned failure episode uncollected.
                app(MachineFailoverObserver::class)->observe($this->scope['driver']['policy_a']);
                if ($input['role'] === 'control') return ['ready' => true, 'external_id' => $this->scope['control']['external_id']];
                if (!$registry->observe($input['role'])['ready']) return ['waiting' => true];
                return ['task_id' => $registry->register($input['role'])['task_id']];
            }
            if ($operation === 'observe_sources') {
                app(MachineFailoverObserver::class)->observe($this->scope['driver']['policy_a']);
                return [];
            }
            // Native readiness reads actual inventory/callback receipts; the RPC accepts no health inputs.
            if ($operation === 'observe_candidates') return [];
            if ($operation === 'control_facts') return $b;
            // Final teardown stays on the host, so deleting the controller cannot erase its own receipt.
            throw new \RuntimeException('member_round_operation_not_allowed');
        };
        $driver = new MemberRetryDriver($this->scope['driver'], $port,
            fn ($state) => ($this->save)('round-driver', $state),
            fn ($_op) => $this->authority->allow('round-step'), $this->clock, $restored, $this->authority);
        $state = $driver->step();
        self::need(MachineIntentJson::same($b, $this->controlFacts()), 'control_member_changed');
        return $this->projection($state);
    }

    private function controlFacts(): array
    {
        $task = Task::query()->findOrFail($this->scope['sources']['source-b']['task_id']);
        $remote = app(MachineCandidateCleanupClient::class)->inspect($task);
        self::need($remote !== null, 'control_member_missing');
        $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($this->scope['driver']['service_id'], $this->scope['driver']['service_revision']);
        $bMember = array_values(array_filter($snapshot['members'], fn ($m) => $m['policy_id'] === $this->scope['driver']['policy_b']))[0]['id'];
        $records = [];
        foreach ($snapshot['dns_sets'] ?? [] as $set) if (in_array($bMember, array_column($set['slots'], 'member_id'), true))
            $records[$set['id']] = app(DnsProviderClient::class)->recordSetInventory(
                \App\Models\MachineDnsCredential::query()->findOrFail($set['credential_id']), $set);
        return ['identity' => array_intersect_key($remote, array_flip(['external_id', 'label', 'tags', 'provider_created_at', 'public_ips'])), 'dns' => $records];
    }

    private function projection(array $state): array
    {
        return ['phase' => in_array($state['phase'], ['cleanup', 'closed'], true)
                ? ($state['first_error'] === null ? 'finished' : 'failed') : 'running',
            'native_phase' => $state['phase'], 'branches' => $state['branches'],
            'first_error' => $state['first_error'], 'cleanup_owned_by_host' => true];
    }

    private function taskPins(): array
    {
        $d = $this->scope['driver']; $pins = $this->scope['sources'];
        $request = substr(hash('sha256', MachineIntentJson::encode([$d['run_id'], 'native-a'])), 0, 32);
        $key = hash('sha256', json_encode([$d['admin_id'], $request], JSON_THROW_ON_ERROR));
        $run = Execution::query()->where('request_key', $key)->first();
        if (!$run) {
            self::need(!Execution::query()->where('service_id', $d['service_id'])->exists(), 'execution_reconciliation_required');
            return $pins;
        }
        self::need($run->service_id === $d['service_id'] && $run->service_revision === $d['service_revision'], 'execution_reconciliation_required');
        $members = Member::query()->where('execution_id', $run->id)->get();
        self::need($members->count() === 1 && $members[0]->policy_id === $d['policy_a'], 'member_reconciliation_required');
        foreach (Attempt::query()->where('execution_member_id', $members[0]->id)->orderBy('ordinal')->get() as $attempt) {
            self::need(in_array($attempt->ordinal, [1, 2], true) && $attempt->account_id === $d['account_id'], 'candidate_reconciliation_required');
            if (!$attempt->task_id) continue;
            $task = Task::query()->findOrFail($attempt->task_id);
            self::need(app(\App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge::class)->bindingCurrent($attempt, $task), 'candidate_binding_changed');
            // A dispatched task without an exact external ID must be reconciled by the
            // original provisioning intent, never ignored as an empty role.
            self::need((bool) $task->external_id || !$task->dispatched_at, 'candidate_readback_required');
            if (!$task->external_id) continue;
            $pins['candidate-a' . $attempt->ordinal] = $task->only(['installation_id', 'external_id', 'account_revision',
                'provider_created_at', 'marker', 'machine_id']) + ['task_id' => $task->id];
        }
        return $pins;
    }

    private function transportScope(): array
    {
        return $this->scope['driver'] + ['sources' => $this->scope['sources'], 'tasks' => $this->taskPins(),
            'binary_sha256' => $this->scope['binary_sha256'], 'version' => $this->scope['version']];
    }

    private function status(): array
    {
        $pins = $this->taskPins(); $roles = [];
        foreach ($pins as $role => $pin) $roles[$role] = array_intersect_key($pin, array_flip(['task_id', 'external_id']));
        return ['run_id' => $this->scope['run_id'], 'resolved' => true, 'roles' => $roles,
            'stopped' => ($this->load)('round-stop') !== null,
            'driver' => ($s = ($this->load)('round-driver')) ? $this->projection($s) : null];
    }

    private static function need(bool $ok, string $reason): void
    {
        if (!$ok) throw new \RuntimeException('member_round_' . $reason);
    }
}

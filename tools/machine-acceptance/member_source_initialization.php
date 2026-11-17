<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Services\ServerMachine\Cloud\MachineIntentJson;
use App\Services\ServerMachine\Cloud\MachineIsolatedAcceptance;
use App\Services\ServerMachine\Cloud\ProvisionPlan;
use App\Services\ServerMachine\Cloud\MachineCandidateCleanupClient;
use App\Services\ServerMachine\Cloud\MachineProvisionProviders;
use App\Models\MachineCloudAccount;
use Illuminate\Support\Facades\Cache;

/** Setup service calls before a complete native grant exists. No setting or queue configuration writes. */
final class MemberSourceInitialization
{
    public function __construct(private array $scope, private \Closure $within,
        private \Closure $load, private \Closure $save)
    {
        self::need(($scope['run_id'] ?? null) === MemberProviderBindings::RUN
            && array_keys($scope['sources'] ?? []) === ['source-a', 'source-b']
            && is_int($scope['admin_id'] ?? null) && $scope['admin_id'] > 0
            && is_int($scope['release_id'] ?? null) && $scope['release_id'] > 0, 'scope_invalid');
        $a = $scope['sources']['source-a']; $b = $scope['sources']['source-b'];
        self::need(($a['plan']['machine_id'] ?? null) !== ($b['plan']['machine_id'] ?? null)
            && ($a['policy_id'] ?? null) !== ($b['policy_id'] ?? null), 'roles_collide');
        foreach ($scope['sources'] as $source) self::need(is_array($source['plan'] ?? null)
            && is_int($source['policy_id'] ?? null) && $source['policy_id'] > 0, 'scope_invalid');
    }

    private static function need(bool $value, string $code): void
    {
        if (!$value) throw new \RuntimeException('member_initialization_' . $code);
    }

    private function within(string $operation, string $role, \Closure $callback): array
    {
        $db = config('database.connections.' . config('database.default'), []);
        if (app()->runningUnitTests()) {
            self::need(($db['driver'] ?? null) === 'sqlite' && ($db['database'] ?? null) === ':memory:', 'test_database_required');
            \Illuminate\Support\Facades\Http::preventStrayRequests();
        } else {
            self::need(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux'
                && realpath(base_path()) === '/opt/keli-isolated/app' && config('app.env') === 'acceptance'
                && ($db['driver'] ?? null) === 'mysql' && in_array($db['host'] ?? null, ['127.0.0.1', 'localhost'], true)
                && empty($db['url']) && empty($db['unix_socket']) && empty($db['read']) && empty($db['write'])
                && preg_match('/^keli_acceptance_[a-z0-9_]{3,60}$/D', $db['database'] ?? '') === 1
                && config('cache.default') === 'redis' && config('cache.prefix') === $this->scope['run_id'] . ':'
                && config('cache.stores.redis.connection') === 'cache' && empty(config('database.redis.clusters'))
                && in_array(config('cache.stores.redis.lock_connection'), [null, 'default', 'cache'], true)
                && is_string($this->scope['controller_machine_id_sha256'] ?? null)
                && hash_equals($this->scope['controller_machine_id_sha256'], (string) @hash_file('sha256', '/etc/machine-id')),
                'isolated_controller_required');
            foreach (['default', 'cache'] as $name) {
                $redis = config('database.redis.' . $name, []);
                self::need(in_array($redis['host'] ?? null, ['127.0.0.1', 'localhost'], true)
                    && empty($redis['url']) && empty($redis['path']) && empty($redis['read']) && empty($redis['write']),
                    'isolated_cache_required');
            }
        }
        return ($this->within)($operation, $role, static function () use ($callback): array {
            $contract = \Illuminate\Contracts\Bus\Dispatcher::class;
            $old = app($contract);
            try {
                // Setup progresses tasks synchronously by explicit ID. No queued
                // job may outlive this isolated, process-local capability.
                app()->instance($contract, new \Illuminate\Bus\Dispatcher(app(), static fn () => new \Illuminate\Queue\NullQueue()));
                return $callback();
            } finally { app()->instance($contract, $old); }
        });
    }

    public function requestId(string $role): string
    {
        self::need(isset($this->scope['sources'][$role]), 'role_invalid');
        return substr(hash('sha256', MachineIntentJson::encode([$this->scope['run_id'], 'source-queue', $role])), 0, 32);
    }

    private function existing(string $role): ?Task
    {
        $source = $this->scope['sources'][$role];
        $request = $this->requestId($role);
        $task = Task::query()->where('request_key', hash('sha256', $this->scope['admin_id'] . ':' . $request))->first();
        if ($task) {
            self::need($task->admin_id === $this->scope['admin_id'] && $task->machine_id === $source['plan']['machine_id']
                && $task->account_id === $source['plan']['account_id']
                && $task->slot === $source['plan']['slot']
                && MachineIntentJson::same($this->plan($task->payload['plan']), $this->plan($source['plan']))
                && !isset($task->payload['coordinated_attempt'])
                && !in_array($task->external_id, $this->scope['baseline_ids'], true), 'task_changed');
        }
        return $task;
    }

    private function plan(array $snapshot): array
    {
        // Account revision is an inventory receipt, not a creation API parameter.
        // No other unknown field is discarded by this boundary.
        unset($snapshot['account_revision']);
        return ProvisionPlan::normalize($snapshot);
    }

    private function intent(string $role): ?array
    {
        $pin = ['request_id' => $this->requestId($role), 'scope_sha256' => MemberProviderBindings::digest($this->scope)];
        $prior = ($this->load)('source-queue-' . $role);
        self::need($prior === null || $prior === $pin, 'intent_changed');
        return $prior;
    }

    public function prepare(string $role): array
    {
        $request = $this->requestId($role);
        return $this->within('source-prepare', $role, function () use ($role, $request): array {
            $source = $this->scope['sources'][$role];
            $pin = ['request_id' => $request, 'scope_sha256' => MemberProviderBindings::digest($this->scope)];
            $key = 'source-queue-' . $role;
            $prior = $this->intent($role);
            $task = $this->existing($role);
            // Finding a task without our durable intent is not permission to adopt it.
            self::need($task === null || $prior !== null, 'unowned_task');
            if ($task === null) {
                self::need($prior === null, 'queue_readback_required');
                ($this->save)($key, $pin);
                $check = app(MachineIsolatedAcceptance::class)->preview($source['policy_id'], $source['policy_revision'],
                    $this->scope['release_id'], $this->scope['admin_id']);
                // This is privileged, isolated setup, not an acceptance verdict. Native
                // queueIsolated still validates the exact policy/release/node intent,
                // capacity, concurrency, ownership and current process-local permission.
                $result = app(MachineProvisionService::class)->queueIsolated($this->plan($source['plan']), $request,
                    $this->scope['admin_id'], $check['release']);
                $task = $this->existing($role);
                self::need($task !== null && $task->id === ($result['id'] ?? null), 'queue_receipt_changed');
            }
            return $this->receipt($role, $task);
        });
    }

    public function process(string $role, bool $readbackOnly = false): array
    {
        $this->requestId($role);
        return $this->within($readbackOnly ? 'source-readback' : 'source-process', $role,
            function () use ($role, $readbackOnly): array {
                self::need($this->intent($role) !== null, 'queue_intent_missing');
                $task = $this->existing($role);
                self::need($task !== null, 'queue_readback_required');
                $service = app(MachineProvisionService::class);
                // Reconciliation can only inspect the original task. It cannot enqueue,
                // manufacture callbacks, mark a bootstrap successful or dispatch a create.
                if ($readbackOnly) $service->readback($task->id);
                else $service->process($task->id);
                return $this->receipt($role, $this->existing($role));
            });
    }

    /** Read exact retained receipts, including incomplete bootstrap, without advancing tasks. */
    public function resourcePins(): array
    {
        $pins = [];
        foreach ($this->scope['sources'] as $role => $source) {
            $intent = $this->intent($role); $task = $this->existing($role);
            if ($task === null) {
                self::need($intent === null, 'queue_readback_required');
                continue;
            }
            self::need($intent !== null, 'queue_intent_missing');
            if (!$task->dispatched_at) {
                self::need(!$task->external_id && !$task->provider_created_at && empty($task->public_ips), 'task_changed');
                continue;
            }
            self::need(is_string($task->external_id) && preg_match('/^[1-9][0-9]{0,15}$/D', $task->external_id) === 1
                && $task->provider_created_at && !empty($task->public_ips), 'create_receipt_unknown');
            $pins[$role] = ['task_id' => $task->id, 'external_id' => $task->external_id];
        }
        foreach (['task_id', 'external_id'] as $field)
            self::need(count(array_unique(array_column($pins, $field))) === count($pins), 'roles_collide');
        return $pins;
    }

    public function registeredScope(array $base): array
    {
        return $this->within('sources-pin', 'control', function () use ($base): array {
            $pins = [];
            foreach ($this->scope['sources'] as $role => $source) {
                self::need($this->intent($role) !== null, 'queue_intent_missing');
                $task = $this->existing($role);
                self::need($task !== null && $task->external_id && $task->bootstrap_state === 'succeeded', 'source_not_ready');
                $pins[$role] = $task->only(['machine_id', 'installation_id', 'external_id', 'marker',
                    'account_revision', 'provider_created_at']) + ['task_id' => $task->id,
                    'policy_id' => $source['policy_id'], 'policy_revision' => $source['policy_revision'],
                    'configured_nodes' => $source['configured_nodes']];
            }
            foreach (['external_id', 'task_id', 'installation_id', 'machine_id'] as $field)
                self::need($pins['source-a'][$field] !== $pins['source-b'][$field], 'roles_collide');
            self::need(($base['run_id'] ?? null) === $this->scope['run_id'], 'scope_invalid');
            // Registration and the three actual report observations stay in MemberSourceRegistry.
            return array_replace($base, ['sources' => $pins]);
        });
    }

    public function advance(array $base): array
    {
        self::need(($base['run_id'] ?? null) === $this->scope['run_id'], 'scope_invalid');
        // A must finish identity registration before B can consume the single
        // provisioning slot. Both remain separate native tasks and installations.
        $decision = $this->within('sources-advance', 'control', function () use ($base): array {
            foreach ($this->scope['sources'] as $role => $source) {
                $stamp = ($this->load)('initialized-' . $role);
                $task = $this->existing($role);
                if ($stamp !== null) {
                    self::need($this->intent($role) !== null && $task !== null
                        && $stamp === ['task_id' => $task->id, 'scope_sha256' => MemberProviderBindings::digest($this->scope)]
                        && $task->status === 'accepted', 'registration_changed');
                    continue;
                }
                if ($task === null) {
                    $policy = \App\Models\MachineFailoverPolicy::findOrFail($source['policy_id']);
                    $latest = Task::query()->where('account_id', $source['plan']['account_id'])->max('created_at');
                    if ($latest !== null && $latest + $policy->cooldown_seconds > time())
                        return ['ready' => false, 'waiting' => true, 'role' => $role, 'reason' => 'account_cooldown',
                            'retry_at' => $latest + $policy->cooldown_seconds];
                    return ['action' => 'prepare', 'role' => $role];
                }
                self::need($this->intent($role) !== null, 'queue_intent_missing');
                self::need(!in_array($task->status, ['failed', 'cancelled', 'outcome_unknown', 'needs_review'], true), 'source_failed');
                if (!$task->external_id || $task->bootstrap_state !== 'succeeded'
                    || !in_array($task->status, ['waiting_ipv4_probe', 'awaiting_acceptance', 'accepted'], true)) {
                    if ($task->next_check_at > time())
                        return ['ready' => false, 'waiting' => true, 'role' => $role, 'reason' => 'native_wait',
                            'retry_at' => $task->next_check_at];
                    return ['action' => 'process', 'role' => $role];
                }
                $pins = [];
                foreach ($this->scope['sources'] as $peerRole => $peer) {
                    $peerTask = $this->existing($peerRole);
                    $pins[$peerRole] = ['machine_id' => $peer['plan']['machine_id'], 'policy_id' => $peer['policy_id'],
                        'policy_revision' => $peer['policy_revision'], 'configured_nodes' => $peer['configured_nodes']];
                    if ($peerTask !== null && $peerTask->external_id) {
                        self::need($this->intent($peerRole) !== null, 'queue_intent_missing');
                        $pins[$peerRole] = $peerTask->only(['machine_id', 'installation_id', 'external_id', 'marker',
                            'account_revision', 'provider_created_at']) + $pins[$peerRole] + ['task_id' => $peerTask->id];
                    }
                }
                $registry = new MemberSourceRegistry(array_replace($base, ['sources' => $pins]),
                    fn ($_operation, $r) => self::need($r === $role, 'role_invalid'), $this->load, $this->save, fn () => time(), true);
                if (!$registry->observe($role)['ready'])
                    return ['ready' => false, 'waiting' => true, 'role' => $role, 'reason' => 'fresh_reports'];
                $registry->register($role);
                ($this->save)('initialized-' . $role, ['task_id' => $task->id,
                    'scope_sha256' => MemberProviderBindings::digest($this->scope)]);
                return ['ready' => false, 'waiting' => true, 'role' => $role, 'reason' => 'registered'];
            }
            return ['ready' => true, 'health_or_forwarding_claimed' => false];
        });
        if (($decision['action'] ?? null) === 'prepare') $this->prepare($decision['role']);
        elseif (($decision['action'] ?? null) === 'process') $this->process($decision['role']);
        else return $decision;
        return ['ready' => false, 'waiting' => true, 'role' => $decision['role'], 'reason' => $decision['action']];
    }

    private function receipt(string $role, Task $task): array
    {
        return ['role' => $role, 'task_id' => $task->id, 'installation_id' => $task->installation_id,
            'external_id' => $task->external_id, 'status' => $task->status,
            'bootstrap_state' => $task->bootstrap_state, 'native_label' => $task->payload['request']['label'],
            'health_or_forwarding_claimed' => false];
    }

    /** Recovery never queues/processes a task or manufactures a provisioning receipt. */
    public function cleanup(string $role): array
    {
        $this->requestId($role);
        return $this->within('source-cleanup', $role, function () use ($role): array {
            $accountId = $this->scope['sources'][$role]['plan']['account_id'];
            return Cache::lock('machine-cloud:account:' . $accountId, 120)->block(2, function () use ($role): array {
                $intent = $this->intent($role); $task = $this->existing($role);
                if ($task === null) {
                    self::need($intent === null, 'queue_readback_required');
                    self::need(!Task::query()->where('machine_id', $this->scope['sources'][$role]['plan']['machine_id'])->exists(), 'unowned_task');
                    return ['complete' => true, 'classification' => 'never_queued'];
                }
                self::need($intent !== null, 'queue_intent_missing');
                if (!$task->dispatched_at) {
                    self::need(!$task->external_id && !$task->provider_created_at && empty($task->public_ips), 'task_changed');
                    return ['complete' => true, 'classification' => 'never_dispatched', 'task_id' => $task->id];
                }
                $key = 'cleanup-' . $role;
                $pin = ['task_id' => $task->id, 'installation_id' => $task->installation_id,
                    'account_id' => $task->account_id, 'account_revision' => $task->account_revision,
                    'dispatched_at' => $task->dispatched_at, 'marker' => $task->marker,
                    'payload_sha256' => MemberProviderBindings::digest($task->payload)];
                $state = ($this->load)($key);
                self::need($state === null || ($state['pin'] ?? null) === $pin, 'cleanup_identity_changed');
                if ($state === null) $state = ['pin' => $pin, 'receipt' => null, 'delete_intent' => false, 'absence_at' => null, 'read_pending' => false];
                try {
                    if ($state['receipt'] === null) {
                        if ($task->external_id && $task->provider_created_at && $task->public_ips) {
                            $receipt = $task->only(['external_id', 'provider_created_at', 'public_ips']);
                        } else {
                            $account = MachineCloudAccount::query()->findOrFail($task->account_id);
                            self::need($account->revision === $task->account_revision, 'account_changed');
                            $provider = app(MachineProvisionProviders::class)->client($account, $task->payload['plan']);
                            $remote = $provider->reconcile($account, $task->payload['request'], $task->dispatched_at);
                            // An empty inventory cannot prove that an uncertain POST created nothing.
                            if ($remote === null) return ['complete' => false, 'classification' => 'create_receipt_unknown'];
                            $receipt = array_intersect_key($remote, array_flip(['external_id', 'provider_created_at', 'public_ips']));
                        }
                        self::need(isset($receipt['external_id']) && !in_array($receipt['external_id'], $this->scope['baseline_ids'], true), 'cleanup_outside_scope');
                        foreach (['external_id', 'provider_created_at', 'public_ips'] as $field)
                            self::need(empty($task->{$field}) || $task->{$field} === ($receipt[$field] ?? null), 'cleanup_identity_changed');
                        $state['receipt'] = $receipt;
                        $this->cleanupSave($key, $state);
                    }
                    foreach (['external_id', 'provider_created_at', 'public_ips'] as $field)
                        self::need(empty($task->{$field}) || $task->{$field} === ($state['receipt'][$field] ?? null), 'cleanup_identity_changed');
                    // Keep recovered cloud identity in the cleanup journal, not the task:
                    // a readback is not a successful create/bootstrap/registration receipt.
                    $bound = clone $task; $bound->forceFill($state['receipt']);
                    $client = app(MachineCandidateCleanupClient::class);
                    if ($state['read_pending']) $state['absence_at'] = null;
                    $state['read_pending'] = true;
                    $this->cleanupSave($key, $state);
                    $remote = $client->inspect($bound); $now = time();
                    $state['read_pending'] = false;
                    if ($remote === null) {
                        $first = $state['absence_at'];
                        if (!is_int($first) || $now < $first) $state['absence_at'] = $now;
                        $this->cleanupSave($key, $state);
                        return ['complete' => is_int($first) && $now >= $first + 10,
                            'classification' => 'exact_absence', 'task_id' => $task->id, 'external_id' => $bound->external_id,
                            'retry_at' => $state['absence_at'] + 10];
                    }
                    $state['absence_at'] = null;
                    $this->cleanupSave($key, $state);
                    if ($state['delete_intent']) return ['complete' => false, 'classification' => 'delete_readback_required'];
                    $client->delete($bound, function () use ($key, &$state, $role, $task): void {
                        self::need($this->existing($role)?->getAttributes() === $task->getAttributes(), 'cleanup_identity_changed');
                        $state['delete_intent'] = true;
                        $this->cleanupSave($key, $state);
                    });
                    return ['complete' => false, 'classification' => 'delete_dispatched'];
                } catch (\Throwable $error) {
                    // An intervening read failure breaks the consecutive-absence proof.
                    $state['absence_at'] = null;
                    try { $this->cleanupSave($key, $state); } catch (\Throwable) { }
                    throw $error;
                }
            });
        });
    }

    private function cleanupSave(string $key, array $state): void
    {
        ($this->save)($key, $state);
        self::need(($this->load)($key) === $state, 'cleanup_journal_unconfirmed');
    }
}

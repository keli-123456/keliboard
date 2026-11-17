<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Services\ServerMachine\Cloud\MachineIntentJson;
use App\Services\ServerMachine\Cloud\MachineIsolatedAcceptance;
use App\Services\ServerMachine\Cloud\ProvisionPlan;

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
}

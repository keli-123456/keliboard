<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineIntentJson;

/** Process-local capability. No route, scheduler, settings writer or grant issuer. */
final class MemberCliAuthority
{
    public const ROOT = '/opt/keli-isolated';
    public const RUN = 'keli-accept-20261116-r35-f4940b41';
    public const RPC = ['round-ready', 'round-step', 'round-status', 'round-stop', 'round-dns-cleanup', 'round-evidence',
        'source-observe', 'source-register', 'task-inspect', 'task-delete'];
    private const RECOVERY = ['round-status', 'round-stop', 'round-dns-cleanup', 'round-evidence', 'task-inspect', 'task-delete'];
    private const NATIVE = ['start', 'advance', 'context', 'queue', 'native_task', 'candidate_cleanup', 'candidate_retry',
        'node_readiness', 'native_address_verify', 'native_address_read', 'native_dns_admission', 'native_dns_prepare',
        'native_dns_binding', 'native_dns_complete', 'dns_readback', 'dns_write', 'dns_set_read', 'dns_set_write',
        'dns_set_start', 'dns_set_advance', 'dns_set_transport', 'dns_external_read'];
    private bool $active = false;
    private array $expected = [];
    private string $operation = '';

    private function __construct(private array $scope, private string $grantHash, private \Closure $environment,
        private \Closure $clock, private \Closure $stopped, private bool $simulation)
    {
        self::need(($scope['schema'] ?? null) === 2 && ($scope['execution_authorized'] ?? null) === true
            && ($scope['isolated'] ?? null) === true && ($scope['run_id'] ?? null) === self::RUN,
            'grant_required');
        foreach (['grant_id' => 32, 'bundle_sha256' => 64, 'controller_machine_id_sha256' => 64] as $key => $size)
            self::need(is_string($scope[$key] ?? null) && preg_match('/^[a-f0-9]{' . $size . '}$/D', $scope[$key]), 'grant_identity');
        self::need(preg_match('/^[a-f0-9]{64}$/D', $grantHash) === 1, 'grant_identity');
        self::need(is_int($scope['started_at'] ?? null) && is_int($scope['deadline_at'] ?? null)
            && $scope['started_at'] > 0 && $scope['deadline_at'] > $scope['started_at'] + 1200
            && $scope['deadline_at'] - $scope['started_at'] <= 7200, 'grant_window');
        self::need(preg_match('/^keli_acceptance_[a-z0-9_]{3,60}$/D', $scope['database'] ?? '') === 1, 'database_required');
        self::need(isset($scope['driver'], $scope['sources'], $scope['control']) && is_array($scope['driver'])
            && ($scope['driver']['run_id'] ?? null) === self::RUN
            && ($scope['driver']['workflow'] ?? null) === 'agent_readiness_v1'
            && array_keys($scope['sources']) === ['source-a', 'source-b'], 'scope_required');
        foreach (['admin_id', 'account_id', 'service_id', 'service_revision', 'release_id', 'plan_id', 'policy_a', 'policy_b'] as $key)
            self::need(is_int($scope['driver'][$key] ?? null) && $scope['driver'][$key] > 0, 'scope_required');
        self::need($scope['driver']['policy_a'] !== $scope['driver']['policy_b']
            && is_array($scope['driver']['baseline_ids'] ?? null) && array_is_list($scope['driver']['baseline_ids'])
            && count($scope['driver']['baseline_ids']) <= 996, 'scope_required');
        self::need(is_string($scope['control']['external_id'] ?? null) && ctype_digit($scope['control']['external_id'])
            && !in_array($scope['control']['external_id'], $scope['driver']['baseline_ids'], true), 'controller_identity');
        $external = [$scope['control']['external_id']]; $installations = []; $tasks = []; $machines = [];
        foreach ($scope['sources'] as $pin) {
            self::need(is_array($pin) && is_string($pin['external_id'] ?? null) && ctype_digit($pin['external_id'])
                && !in_array($pin['external_id'], $scope['driver']['baseline_ids'], true)
                && is_string($pin['task_id'] ?? null) && preg_match('/^[a-f0-9-]{36}$/D', $pin['task_id'])
                && is_string($pin['installation_id'] ?? null) && $pin['installation_id'] !== ''
                && is_int($pin['machine_id'] ?? null) && $pin['machine_id'] > 0, 'source_identity');
            $external[] = $pin['external_id']; $installations[] = $pin['installation_id'];
            $tasks[] = $pin['task_id']; $machines[] = $pin['machine_id'];
        }
        self::need(count(array_unique($external)) === 3 && count(array_unique($installations)) === 2
            && count(array_unique($tasks)) === 2 && count(array_unique($machines)) === 2, 'source_identity');
        $this->assertEnvironment();
    }

    public static function readGrant(): array
    {
        self::need(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux', 'linux_cli_required');
        foreach ([self::ROOT, self::ROOT . '/private'] as $directory)
            self::need(realpath($directory) === $directory && is_dir($directory)
                && (fileperms($directory) & 0022) === 0, 'private_directory_required');
        $path = self::ROOT . '/private/member-runtime-scope.json';
        $stat = @lstat($path);
        self::need($stat && realpath($path) === $path && is_file($path) && $stat['nlink'] === 1
            && ($stat['mode'] & 0077) === 0 && $stat['uid'] === 0 && $stat['size'] <= 65536, 'grant_file_required');
        $stream = fopen($path, 'rb');
        self::need($stream !== false, 'grant_file_required');
        try {
            $opened = fstat($stream);
            self::need($opened['ino'] === $stat['ino'] && $opened['dev'] === $stat['dev'], 'grant_file_changed');
            $raw = stream_get_contents($stream, 65537);
        } finally { fclose($stream); }
        self::need(strlen($raw) <= 65536, 'grant_file_required');
        $scope = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($scope), 'grant_required');
        return [$scope, hash('sha256', $raw)];
    }

    public static function controller(array $scope, string $hash, \Closure $stopped): self
    {
        [$disk, $actual] = self::readGrant();
        self::need(hash_equals($actual, $hash) && MachineIntentJson::same($disk, $scope), 'grant_file_changed');
        return new self($scope, $hash, static function () use ($hash): array {
            [, $current] = self::readGrant();
            self::need(hash_equals($hash, $current), 'grant_file_changed');
            return self::environmentFacts();
        }, static fn () => time(), $stopped, false);
    }

    public static function fixture(array $scope, string $hash, \Closure $environment, \Closure $clock, \Closure $stopped): self
    {
        self::testOnly();
        return new self($scope, $hash, $environment, $clock, $stopped, true);
    }

    private static function testOnly(): void
    {
        self::need(app()->runningUnitTests() && app('db')->connection()->getDriverName() === 'sqlite'
            && app('db')->connection()->getDatabaseName() === ':memory:', 'fixture_environment_required');
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    public static function environmentFacts(): array
    {
        $db = config('database.connections.' . config('database.default'), []);
        $redis = config('database.redis', []);
        return ['sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY, 'env' => config('app.env'),
            'root' => realpath(base_path()), 'machine_sha256' => @hash_file('sha256', '/etc/machine-id'),
            'database_driver' => $db['driver'] ?? null, 'database' => $db['database'] ?? null,
            'database_host' => $db['host'] ?? null, 'database_url' => $db['url'] ?? null,
            'database_socket' => $db['unix_socket'] ?? null, 'database_read' => $db['read'] ?? null,
            'database_write' => $db['write'] ?? null, 'cache' => config('cache.default'),
            'cache_prefix' => config('cache.prefix'), 'redis_default' => $redis['default'] ?? [],
            'redis_cache' => $redis['cache'] ?? [], 'redis_clusters' => $redis['clusters'] ?? [],
            'cache_connection' => config('cache.stores.redis.connection'),
            'cache_lock_connection' => config('cache.stores.redis.lock_connection')];
    }

    private function assertEnvironment(): void
    {
        if ($this->simulation) self::testOnly();
        $e = ($this->environment)();
        self::need(($e['sapi'] ?? null) === 'cli' && ($e['os'] ?? null) === 'Linux'
            && ($e['root'] ?? null) === self::ROOT . '/app' && ($e['env'] ?? null) === 'acceptance'
            && ($e['machine_sha256'] ?? null) === $this->scope['controller_machine_id_sha256']
            && ($e['database_driver'] ?? null) === 'mysql' && ($e['database'] ?? null) === $this->scope['database']
            && in_array($e['database_host'] ?? null, ['127.0.0.1', 'localhost'], true)
            && empty($e['database_url']) && empty($e['database_socket']) && empty($e['database_read']) && empty($e['database_write'])
            && ($e['cache'] ?? null) === 'redis' && ($e['cache_prefix'] ?? null) === self::RUN . ':'
            && empty($e['redis_clusters']) && ($e['cache_connection'] ?? null) === 'cache'
            && in_array($e['cache_lock_connection'] ?? null, [null, 'default', 'cache'], true), 'isolated_environment_required');
        foreach (['redis_default', 'redis_cache'] as $name) {
            $r = $e[$name] ?? [];
            self::need(in_array($r['host'] ?? null, ['127.0.0.1', 'localhost'], true)
                && empty($r['url']) && empty($r['path']) && !isset($r['read']) && !isset($r['write']), 'isolated_cache_required');
        }
        foreach ($this->active ? $this->expected : self::closed() as $key => $value)
            self::need(config($key) === $value, 'persistent_gate_changed');
    }

    public static function closed(): array
    {
        return ['machine_provision.allow_live' => false, 'machine_provision.allowed_test_machine_ids' => [],
            'machine_failover.preview_enabled' => false, 'machine_failover.observe_enabled' => false,
            'machine_failover.runner_enabled' => false, 'machine_failover.admission_enabled' => false,
            'machine_failover.admission_authorizations' => [], 'machine_failover.allow_replacement' => false,
            'machine_failover.allowed_replacement_policy_ids' => [], 'machine_failover.allow_rotation' => false,
            'machine_failover.allowed_rotation_policy_ids' => [], 'machine_failover.allow_candidate_retry' => false,
            'machine_failover.allowed_candidate_retry_policy_ids' => [], 'machine_acceptance.allow_verification' => false,
            'machine_acceptance.allow_readiness' => false, 'machine_acceptance.allow_dns' => false,
            'machine_acceptance.allowed_policy_ids' => [], 'machine_failover.workflow' => 'agent_readiness_v1'];
    }

    public function identity(): array
    {
        return ['run_id' => self::RUN, 'grant_id' => $this->scope['grant_id'],
            'grant_sha256' => $this->grantHash, 'bundle_sha256' => $this->scope['bundle_sha256']];
    }

    public function stopCallbacks(): void
    {
        if ($this->simulation) return;
        $path = self::ROOT . '/member-setup-grant.json';
        if (file_exists($path) || is_link($path)) {
            foreach (['member_application_bootstrap.php', 'member_provider_bindings.php', 'member_setup_authority.php'] as $file)
                require_once __DIR__ . '/' . $file;
            MemberSetupAuthority::revoke();
        }
    }

    public function assertDriver(array $driver): void
    {
        $this->assertEnvironment();
        self::need(MachineIntentJson::same($driver, $this->scope['driver']), 'driver_scope_changed');
    }

    public function allow(string $operation, string $role = 'control'): void
    {
        $this->assertEnvironment();
        self::need(in_array($operation, self::RPC, true), 'operation_not_allowed');
        self::need(in_array($role, ['control', 'source-a', 'source-b', 'candidate-a1', 'candidate-a2'], true)
            && (str_starts_with($operation, 'round-') ? $role === 'control' : $role !== 'control')
            && (!str_starts_with($operation, 'source-') || str_starts_with($role, 'source-')), 'role_not_allowed');
        $now = ($this->clock)();
        self::need(is_int($now) && $now >= $this->scope['started_at'], 'clock_invalid');
        if (!in_array($operation, self::RECOVERY, true))
            self::need($now < $this->scope['deadline_at'] - 1200 && !($this->stopped)()
                && ($this->simulation || (!file_exists(self::ROOT . '/member-setup-stop.json')
                    && !is_link(self::ROOT . '/member-setup-stop.json'))), 'execution_expired_or_stopped');
        if (in_array($operation, ['task-delete', 'round-dns-cleanup', 'round-evidence'], true)) self::need(($this->stopped)(), 'final_cleanup_required');
    }

    public function native(string $operation): void
    {
        self::need($this->active && $this->operation === 'round-step' && in_array($operation, self::NATIVE, true), 'native_operation_denied');
        $this->allow('round-step');
        $this->assertData();
    }

    public function assertData(): void
    {
        $s = $this->scope; $d = $s['driver'];
        $account = \App\Models\MachineCloudAccount::query()->findOrFail($d['account_id']);
        self::need($account->provider === 'linode' && $account->revision === $s['sources']['source-a']['account_revision'], 'account_changed');
        $snapshot = app(\App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry::class)
            ->snapshot($d['service_id'], $d['service_revision']);
        self::need(hash_equals($s['service_sha256'] ?? '', hash('sha256', MachineIntentJson::encode($snapshot))), 'service_changed');
        foreach (['source-a' => 'policy_a', 'source-b' => 'policy_b'] as $role => $key) {
            $p = $s['sources'][$role];
            $task = \App\Models\MachineProvisionTask::query()->findOrFail($p['task_id']);
            foreach (['machine_id', 'installation_id', 'external_id', 'marker', 'account_revision', 'provider_created_at'] as $field)
                self::need(isset($p[$field]) && $task->{$field} === $p[$field], 'source_binding_changed');
            self::need($task->account_id === $d['account_id'] && $p['policy_id'] === $d[$key]
                && !in_array($task->external_id, $d['baseline_ids'], true), 'source_binding_changed');
        }
        $runs = \App\Models\MachineFailoverExecution::query()->where('service_id', $d['service_id'])->get();
        self::need($runs->count() <= 1, 'unexpected_execution');
        foreach ($runs as $run) {
            self::need($run->service_revision === $d['service_revision'] && $run->admin_id === $d['admin_id'], 'unexpected_execution');
            $members = \App\Models\MachineFailoverExecutionMember::query()->where('execution_id', $run->id)->get();
            // During start the parent can exist briefly before its selected member is inserted.
            self::need($members->count() <= 1 && $members->every(fn ($m) => $m->policy_id === $d['policy_a']), 'unexpected_member');
            $attempts = \App\Models\MachineFailoverAttempt::query()->whereIn('execution_member_id', $members->pluck('id'))->get();
            self::need($attempts->count() <= 2 && $attempts->every(fn ($a) => $a->account_id === $d['account_id']
                && in_array($a->ordinal, [1, 2], true)), 'unexpected_candidate');
        }
        self::need(\App\Models\MachineProvisionTask::query()->count() <= 4, 'task_limit');
        if (!$this->simulation) foreach ($snapshot['dns_sets'] ?? [] as $set)
            self::need($set['provider'] === 'aliyun' && in_array($set['hostname'], ['edge-a-' . self::RUN . '.dnscloudcloud.top',
                'edge-b-' . self::RUN . '.dnscloudcloud.top'], true) && $set['zone'] === 'dnscloudcloud.top'
                && $set['ttl'] === 600 && count($set['slots']) === 1
                && $set['slots'][0]['type'] === 'A' && $set['slots'][0]['line'] === 'default', 'dns_scope_changed');
    }

    public function within(string $operation, string $role, \Closure $callback): mixed
    {
        self::need(!$this->active, 'nested_authority_denied');
        $this->allow($operation, $role);
        $gateShared = app()->isShared(MachineFailoverExecutionGate::class);
        $oldGate = app(MachineFailoverExecutionGate::class);
        $busContract = \Illuminate\Contracts\Bus\Dispatcher::class;
        $busShared = app()->isShared($busContract);
        $oldBus = app($busContract);
        $before = []; foreach (self::closed() as $key => $_) $before[$key] = config($key);
        $this->expected = $before;
        if ($operation === 'round-step') {
            $a = $this->scope['driver']['policy_a'];
            $this->expected = array_replace($before, [
                'machine_provision.allow_live' => true,
                'machine_provision.allowed_test_machine_ids' => [$this->scope['sources']['source-a']['machine_id']],
                'machine_failover.observe_enabled' => true, 'machine_failover.allow_replacement' => true,
                'machine_failover.allowed_replacement_policy_ids' => [$a], 'machine_failover.allow_candidate_retry' => true,
                'machine_failover.allowed_candidate_retry_policy_ids' => [$a], 'machine_acceptance.allow_readiness' => true,
                'machine_acceptance.allow_dns' => true, 'machine_acceptance.allowed_policy_ids' => [$a]]);
        }
        $this->active = true; $this->operation = $operation;
        try {
            config($this->expected);
            // Native steps run synchronously in this CLI. Do not leave fallback jobs
            // on a queue which could outlive the process-local capability.
            app()->instance($busContract, new \Illuminate\Bus\Dispatcher(app(),
                static fn () => new \Illuminate\Queue\NullQueue()));
            app()->instance(MachineFailoverExecutionGate::class, new class($this) extends MachineFailoverExecutionGate {
                public function __construct(private MemberCliAuthority $authority) {}
                public function assertAllowed(string $operation): void { $this->authority->native($operation); }
            });
            return $callback();
        } finally {
            config($before);
            if ($busShared) app()->instance($busContract, $oldBus);
            else app()->forgetInstance($busContract);
            if ($gateShared) app()->instance(MachineFailoverExecutionGate::class, $oldGate);
            else app()->forgetInstance(MachineFailoverExecutionGate::class);
            $this->active = false; $this->operation = ''; $this->expected = [];
        }
    }

    private static function need(bool $ok, string $reason): void
    {
        if (!$ok) throw new CloudException('member_cli_' . $reason);
    }
}

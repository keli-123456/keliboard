<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineCloudAccount;
use App\Models\MachineFailoverPolicy;
use App\Models\MachineProvisionTask as Task;
use App\Models\Server;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineIntentJson;
use App\Services\ServerMachine\Cloud\MachineProvisionLimits;
use App\Services\ServerMachine\Cloud\ProvisionBootstrap;
use App\Services\ServerMachine\Cloud\ProvisionPlan;
use Illuminate\Contracts\Bus\Dispatcher as BusContract;

/** Isolated setup capability, published by root; no production route or persistent gate. */
final class MemberSetupAuthority
{
    private const GRANT = 'member-setup-grant.json';
    private const STOP = 'member-setup-stop.json';
    private const MUTATIONS = ['source-prepare', 'source-process', 'sources-advance', 'sources-pin'];
    private bool $active = false;

    private static function need(bool $ok, string $code): void
    {
        if (!$ok) throw new \RuntimeException('member_setup_' . $code);
    }

    private static function readFile(string $name): array
    {
        self::need(in_array($name, [self::GRANT, self::STOP], true), 'file_invalid');
        $path = MemberApplicationBootstrap::ROOT . '/' . $name;
        clearstatcache(true, $path); $stat = @lstat($path);
        self::need($stat && realpath($path) === $path && is_file($path) && $stat['uid'] === 0
            && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0644 && $stat['size'] <= 65536, 'file_required');
        $f = fopen($path, 'rb'); self::need($f !== false, 'file_required');
        try {
            $opened = fstat($f);
            self::need($opened['ino'] === $stat['ino'] && $opened['dev'] === $stat['dev'], 'file_changed');
            $raw = stream_get_contents($f, 65537);
        } finally { fclose($f); }
        self::need(strlen($raw) <= 65536, 'file_invalid');
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($value), 'file_invalid');
        return [$value, hash('sha256', $raw)];
    }

    private static function publish(string $path, array $value, int $mode): void
    {
        // O_EXCL keeps a partial/uncertain issuance as a barrier, never an implicit retry.
        self::need(!file_exists($path) && !is_link($path), 'issuance_exists');
        $mask = umask(0077);
        try { $f = fopen($path, 'x+b'); } finally { umask($mask); }
        self::need($f !== false, 'publication_failed');
        try {
            $raw = json_encode($value, JSON_THROW_ON_ERROR);
            self::need(strlen($raw) <= 65536 && fwrite($f, $raw) === strlen($raw)
                && fflush($f) && fsync($f) && chmod($path, $mode), 'publication_failed');
        } finally { fclose($f); }
    }

    public static function issue(MemberSourceConfiguration $configuration, int $started, int $deadline): self
    {
        $installed = MemberApplicationBootstrap::initialized();
        self::window($started, $deadline, time(), true);
        $binding = $configuration->authorizationBinding();
        self::need(Task::query()->count() === 0, 'tasks_already_exist');
        $root = MemberApplicationBootstrap::ROOT;
        self::need(!file_exists($root . '/' . self::STOP) && !is_link($root . '/' . self::STOP), 'already_stopped');
        $scope = ['schema' => 1, 'run_id' => MemberCliAuthority::RUN, 'grant_id' => bin2hex(random_bytes(16)),
            'started_at' => $started, 'deadline_at' => $deadline, 'bundle_sha256' => $installed['bundle_sha256'],
            'environment_sha256' => $installed['environment_sha256'], 'machine_id_sha256' => $installed['machine_id_sha256'],
            'binding' => $binding];
        self::publish($root . '/private/member-setup-issuance.json', ['scope_sha256' => MemberProviderBindings::digest($scope)], 0600);
        // Only the fresh isolated database receives its own public callback origin.
        foreach (['app_url', 'node_api_base_url'] as $key)
            self::need(in_array(admin_setting($key, ''), ['', config('app.url')], true), 'origin_changed');
        admin_setting(['app_url' => config('app.url'), 'node_api_base_url' => config('app.url')]);
        self::need(app(ProvisionBootstrap::class)->configuredOrigin() === config('app.url'), 'origin_changed');
        self::publish($root . '/' . self::GRANT, $scope, 0644);
        return self::open();
    }

    public static function window(int $started, int $deadline, int $now, bool $dispatch): void
    {
        self::need($started > 0 && $deadline > $started + 1200 && $deadline <= $started + 7200, 'window_invalid');
        self::need($now >= $started, 'clock_invalid');
        if ($dispatch) self::need($now < $deadline - 1200, 'expired');
    }

    public static function open(): self
    {
        $pin = MemberApplicationBootstrap::receipt();
        MemberApplicationBootstrap::assertConfiguration($pin);
        [$scope, $hash] = self::readFile(self::GRANT);
        return new self($scope, $hash);
    }

    private function __construct(private array $scope, private string $hash)
    {
        $keys = ['schema', 'run_id', 'grant_id', 'started_at', 'deadline_at', 'bundle_sha256',
            'environment_sha256', 'machine_id_sha256', 'binding'];
        self::need(count($scope) === count($keys) && !array_diff(array_keys($scope), $keys)
            && $scope['schema'] === 1 && $scope['run_id'] === MemberCliAuthority::RUN
            && is_string($scope['grant_id']) && preg_match('/^[a-f0-9]{32}$/D', $scope['grant_id'])
            && is_int($scope['started_at']) && is_int($scope['deadline_at']), 'scope_invalid');
        $pin = MemberApplicationBootstrap::receipt();
        foreach (['bundle_sha256', 'environment_sha256', 'machine_id_sha256'] as $key)
            self::need(($scope[$key] ?? null) === $pin[$key], 'installation_changed');
        $binding = $scope['binding'];
        self::need(is_array($binding) && array_keys($binding) === ['scope', 'configuration']
            && ($binding['scope']['run_id'] ?? null) === MemberCliAuthority::RUN
            && ($binding['configuration']['scope_sha256'] ?? null) === MemberProviderBindings::digest($binding['scope'])
            && array_keys($binding['configuration']['sources'] ?? []) === ['source-a', 'source-b'], 'binding_invalid');
        self::window($scope['started_at'], $scope['deadline_at'], time(), false);
        $this->check();
    }

    public function identity(): array
    {
        return ['grant_id' => $this->scope['grant_id'], 'grant_sha256' => $this->hash,
            'deadline_at' => $this->scope['deadline_at']];
    }

    public function sourceBinding(): array
    {
        $this->check();
        return $this->scope['binding'];
    }

    private function stopped(): bool
    {
        $path = MemberApplicationBootstrap::ROOT . '/' . self::STOP;
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) return false;
        [$stop] = self::readFile(self::STOP);
        self::need($stop === ['grant_sha256' => $this->hash, 'stopped' => true], 'stop_changed');
        return true;
    }

    private function check(): void
    {
        [, $current] = self::readFile(self::GRANT);
        self::need(hash_equals($current, $this->hash), 'file_changed');
        $this->stopped();
        $binding = $this->scope['binding']; $s = $binding['scope']; $c = $binding['configuration'];
        app(MachineFailoverServiceRegistry::class)->actor($s['admin_id']);
        $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($c['service_id'], $c['service_revision']);
        self::need(MemberProviderBindings::digest($snapshot) === $c['service_sha256'], 'configuration_changed');
        $account = MachineCloudAccount::query()->findOrFail($s['account_id']);
        self::need($account->provider === 'linode' && $account->revision === $s['account_revision']
            && $account->group_id === $s['group_id'] && MachineCloudAccount::query()->count() === 1, 'account_changed');
        self::need(app(MachineProvisionLimits::class)->settings($account) === [
            'max_instances' => count($s['baseline_ids']) + 4, 'max_parallel' => 1, 'daily_limit' => 5], 'limits_changed');
        $release = ServerMachineRelease::query()->findOrFail($s['release_id']);
        self::need($release->status === 'active' && $release->binary_sha256 === $s['binary_sha256']
            && $release->version === $s['version'] && $release->component === 'kelinode-rs'
            && $release->platform === 'linux-x86_64', 'release_changed');
        self::need(app(ProvisionBootstrap::class)->configuredOrigin() === config('app.url'), 'origin_changed');
        foreach ($c['sources'] as $role => $p) {
            $policy = MachineFailoverPolicy::query()->findOrFail($p['policy_id']);
            self::need($policy->revision === $p['policy_revision'] && $policy->failure_threshold === 3
                && $policy->cooldown_seconds === 300 && $policy->stale_after_seconds === 300
                && $policy->daily_limit === ($role === 'source-a' ? 3 : 1), 'policy_changed');
            self::need(MemberProviderBindings::digest(MemberSourceConfiguration::nodePin(Server::query()->findOrFail($p['node_id'])))
                === $p['node_sha256'], 'node_changed');
        }
    }

    public function allow(string $operation, string $role): void
    {
        self::need(in_array($operation, array_merge(self::MUTATIONS, ['source-readback', 'callback', 'report']), true), 'operation_denied');
        self::need(str_starts_with($operation, 'sources-') ? $role === 'control'
            : in_array($role, ['source-a', 'source-b'], true), 'role_denied');
        $this->check();
        $mutating = $operation !== 'source-readback';
        self::window($this->scope['started_at'], $this->scope['deadline_at'], time(), $mutating);
        if ($mutating) self::need(!$this->stopped(), 'stopped');
    }

    private function taskRole(Task $task): string
    {
        $s = $this->scope['binding']['scope']; $c = $this->scope['binding']['configuration'];
        foreach ($c['sources'] as $role => $p) {
            $request = substr(hash('sha256', MachineIntentJson::encode([MemberCliAuthority::RUN, 'source-queue', $role])), 0, 32);
            if ($task->request_key !== hash('sha256', $s['admin_id'] . ':' . $request)) continue;
            $plan = $task->payload['plan'] ?? [];
            unset($plan['account_revision']);
            self::need($task->admin_id === $s['admin_id'] && $task->account_id === $s['account_id']
                && $task->account_revision === $s['account_revision'] && $task->machine_id === $p['machine_id']
                && $task->slot === $p['plan']['slot'] && !isset($task->payload['coordinated_attempt'])
                && MemberProviderBindings::digest(ProvisionPlan::normalize($plan)) === MemberProviderBindings::digest($p['plan'])
                && !in_array($task->external_id, $s['baseline_ids'], true), 'task_changed');
            return $role;
        }
        throw new \RuntimeException('member_setup_task_outside_grant');
    }

    public function within(string $operation, string $role, \Closure $callback): mixed
    {
        self::need(PHP_SAPI === 'cli' && posix_geteuid() === 0, 'root_cli_required');
        $this->allow($operation, $role);
        self::need(!in_array($operation, ['callback', 'report'], true), 'operation_denied');
        $sources = $this->scope['binding']['configuration']['sources'];
        $selected = $role === 'control' ? $sources : [$role => $sources[$role]];
        return $this->temporary(['machine_provision.allow_live' => true,
            'machine_provision.allowed_test_machine_ids' => array_column($selected, 'machine_id'),
            'machine_acceptance.allow_verification' => true,
            'machine_acceptance.allowed_policy_ids' => array_column($selected, 'policy_id')], function () use ($callback) {
                self::need(Task::query()->count() <= 2, 'task_limit');
                foreach (Task::query()->get() as $task) $this->taskRole($task);
                $result = $callback();
                self::need(Task::query()->count() <= 2, 'task_limit');
                foreach (Task::query()->get() as $task) $this->taskRole($task);
                return $result;
            }, $operation === 'source-readback');
    }

    public function callback(string $id, string $installation, \Closure $callback): mixed
    {
        self::need(preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id) === 1
            && preg_match('/^[a-f0-9]{32}$/D', $installation) === 1, 'callback_invalid');
        $task = Task::query()->find($id);
        self::need($task !== null && hash_equals($task->installation_id, $installation), 'callback_invalid');
        $role = $this->taskRole($task); $this->allow('callback', $role);
        return $this->temporary(['machine_provision.allow_live' => true], $callback);
    }

    public function report(int $machine, string $installation, ?int $node, \Closure $callback): mixed
    {
        self::need($machine > 0 && preg_match('/^[a-f0-9]{32}$/D', $installation) === 1, 'report_invalid');
        $tasks = Task::query()->where('machine_id', $machine)->where('installation_id', $installation)->get();
        self::need($tasks->count() === 1, 'report_invalid');
        $task = $tasks->first(); $role = $this->taskRole($task);
        self::need($task->dispatched_at > 0 && $task->external_id !== null
            && ($node === null || $node === $this->scope['binding']['configuration']['sources'][$role]['node_id']), 'report_invalid');
        $this->allow('report', $role);
        // Native machine/node token authentication still runs in the HTTP kernel.
        // Report reception needs no create, replacement or DNS permission.
        return $this->temporary([], $callback);
    }

    private static function executionLock(bool $exclusive)
    {
        self::readFile(self::GRANT);
        $f = fopen(MemberApplicationBootstrap::ROOT . '/' . self::GRANT, 'rb');
        self::need($f !== false, 'file_required');
        if (!flock($f, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            fclose($f); throw new \RuntimeException('member_setup_revocation_pending');
        }
        return $f;
    }

    private function temporary(array $enable, \Closure $callback, bool $recovery = false): mixed
    {
        self::need(!$this->active, 'nested_denied');
        $pin = MemberApplicationBootstrap::receipt();
        MemberApplicationBootstrap::assertConfiguration($pin);
        $lock = self::executionLock(false);
        $before = []; $bus = null;
        try {
            foreach (MemberCliAuthority::closed() as $key => $_) $before[$key] = config($key);
            $bus = app(BusContract::class); $this->active = true;
            self::window($this->scope['started_at'], $this->scope['deadline_at'], time(), !$recovery);
            if (!$recovery) self::need(!$this->stopped(), 'stopped');
            config($enable);
            app()->instance(BusContract::class, new \Illuminate\Bus\Dispatcher(app(), static fn () => new \Illuminate\Queue\NullQueue()));
            return $callback();
        } finally {
            config($before);
            if ($bus !== null) app()->instance(BusContract::class, $bus);
            $this->active = false;
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function stop(): array
    {
        $result = self::revoke();
        self::need($result['grant_sha256'] === $this->hash, 'file_changed');
        return $result;
    }

    public static function revoke(): array
    {
        self::need(PHP_SAPI === 'cli' && posix_geteuid() === 0, 'root_cli_required');
        // Revocation does not need a working database or unexpired grant.
        $pin = MemberApplicationBootstrap::receipt();
        [$scope, $hash] = self::readFile(self::GRANT);
        foreach (['bundle_sha256', 'environment_sha256', 'machine_id_sha256'] as $key)
            self::need(($scope[$key] ?? null) === $pin[$key], 'installation_changed');
        $stop = ['grant_sha256' => $hash, 'stopped' => true];
        $path = MemberApplicationBootstrap::ROOT . '/' . self::STOP;
        if (!file_exists($path) && !is_link($path)) self::publish($path, $stop, 0644);
        [$observed] = self::readFile(self::STOP);
        self::need($observed === $stop, 'stop_changed');
        // New operations see the stop immediately. A stop acknowledgement waits
        // for all previously admitted callbacks/CLI operations to leave.
        $lock = self::executionLock(true);
        flock($lock, LOCK_UN); fclose($lock);
        return $stop;
    }
}

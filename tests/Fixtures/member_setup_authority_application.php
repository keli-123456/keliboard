<?php
declare(strict_types=1);

use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineIntentJson;
use Illuminate\Contracts\Bus\Dispatcher as BusContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberApplicationBootstrap;
use Keli\Acceptance\MemberCliAuthority;
use Keli\Acceptance\MemberProviderBindings;
use Keli\Acceptance\MemberSetupAuthority;
use Keli\Acceptance\MemberSetupController;
use Keli\Acceptance\MemberSourceConfiguration;
use Keli\Acceptance\MemberSourceInitialization;

// Full application and real journals/DB/cache. Only provider responses are simulated.
return static function (string $mode): array {
    if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('KELI_MEMBER_FULL_APPLICATION') !== '1'
        || PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli') throw new RuntimeException('fixture_only');
    foreach (['member_provider_bindings', 'member_source_configuration', 'member_source_registry', 'member_source_initialization',
        'member_setup_authority', 'member_setup_controller'] as $file)
        require_once '/opt/keli-isolated/runtime/' . $file . '.php';
    $need = static function (bool $ok, string $code = 'invalid'): void {
        if (!$ok) throw new RuntimeException('fixture_setup_' . $code);
    };
    $closed = static function () use ($need): void {
        foreach (MemberCliAuthority::closed() as $key => $value) $need(config($key) === $value, 'gate_not_restored');
    };
    $denied = static function (Closure $action, string $reason) use ($need): void {
        try { $action(); throw new LogicException('fixture_setup_denial_missing'); }
        catch (RuntimeException $error) { $need($error->getMessage() === $reason, 'wrong_denial'); }
    };
    if ($mode === 'setup-service-denied') {
        $need(posix_geteuid() === 65534);
        $authority = MemberSetupAuthority::open();
        $scope = json_decode(file_get_contents('/opt/keli-isolated/member-setup-grant.json'), true, 32, JSON_THROW_ON_ERROR)['binding']['scope'];
        $unexpected = static fn () => throw new LogicException('fixture_setup_service_guard_bypassed');
        $configuration = new MemberSourceConfiguration($scope, $unexpected, $unexpected, $unexpected);
        $denied(fn () => MemberSetupAuthority::issue($configuration, time(), time() + 7200), 'member_application_installer_root_required');
        $denied(fn () => $authority->within('source-prepare', 'source-a', fn () => []), 'member_setup_root_cli_required');
        $denied(fn () => MemberSetupAuthority::revoke(), 'member_setup_root_cli_required');
        $need(!is_writable('/opt/keli-isolated/member-setup-grant.json'));
        $closed();
        return ['root_operations_denied' => true, 'grant_read_only' => true];
    }
    $need(posix_geteuid() === 0, 'root_required');
    MemberApplicationBootstrap::initialized();
    if ($mode === 'setup-queues') {
        $redis = Illuminate\Support\Facades\Redis::connection('default');
        foreach (['r35_callbacks', 'default', 'risk_ai', App\Services\ServerMachine\Cloud\MachineFailoverQueue::NAME] as $name) {
            $need((int) $redis->llen('queues:' . $name) === 0, 'queue_job_escaped');
            foreach (['delayed', 'reserved'] as $suffix)
                $need((int) $redis->zcard('queues:' . $name . ':' . $suffix) === 0, 'queue_job_escaped');
        }
        $need(DB::table('failed_jobs')->count() === 0, 'failed_job_escaped'); $closed();
        return ['no_redis_queued_delayed_or_reserved_jobs' => true, 'no_failed_jobs' => true];
    }
    if ($mode === 'setup-stop' || $mode === 'setup-stop-busy') {
        // Revocation must still work when MySQL is unavailable or configuration drifted.
        DB::purge(); config(['database.connections.mysql.port' => 1]);
        if ($mode === 'setup-stop-busy') {
            $denied(fn () => MemberSetupAuthority::revoke(), 'member_setup_revocation_pending');
            return ['new_admissions_stopped' => true, 'drain_pending' => true];
        }
        $result = MemberSetupAuthority::revoke();
        return ['stopped' => $result['stopped'], 'database_not_required' => true];
    }
    $root = '/opt/keli-isolated/private';
    $journal = $root . '/setup-fixture';
    if ($mode === 'setup-issue') { $need(!file_exists($journal)); mkdir($journal, 0700); }
    $need(realpath($journal) === $journal && (fileperms($journal) & 0077) === 0);
    $read = static function (string $directory, string $key) use ($need): ?array {
        $need(preg_match('/^[a-z0-9-]{1,100}$/D', $key) === 1);
        $path = $directory . '/' . $key . '.json';
        if (!file_exists($path)) return null;
        $need(realpath($path) === $path && (fileperms($path) & 0077) === 0);
        return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    };
    $load = static fn ($key) => $read($journal, $key);
    $save = static function (string $key, array $value) use ($journal, $need): void {
        $need(preg_match('/^[a-z0-9-]{1,100}$/D', $key) === 1);
        $path = $journal . '/.' . bin2hex(random_bytes(12));
        $f = fopen($path, 'x+b'); $need($f !== false); chmod($path, 0600);
        try {
            $raw = json_encode($value, JSON_THROW_ON_ERROR);
            $need(fwrite($f, $raw) === strlen($raw) && fflush($f) && fsync($f));
        } finally { fclose($f); }
        $need(rename($path, $journal . '/' . $key . '.json'));
    };
    if ($mode === 'setup-issue') {
        $configRoot = $root . '/configuration-fixture';
        $lock = fopen($configRoot . '/lock', 'r+b'); $need($lock !== false && flock($lock, LOCK_EX | LOCK_NB));
        try {
            $scope = $read($configRoot, 'scope');
            $guard = static function ($operation) use ($read, $configRoot): void {
                if ($read($configRoot, 'stopped') !== null && $operation !== 'source-configuration-read')
                    throw new RuntimeException('fixture_configuration_stopped');
            };
            $configuration = new MemberSourceConfiguration($scope, $guard, fn ($key) => $read($configRoot, $key),
                static fn () => throw new LogicException('fixture_setup_configuration_write'));
            $authority = MemberSetupAuthority::issue($configuration, time(), time() + 7200);
            $save('initialization', $configuration->readback()['initialization_scope']);
            $save('identity', $authority->identity());
            $denied(fn () => MemberSetupAuthority::issue($configuration, time(), time() + 7200), 'member_setup_issuance_exists');
            $closed();
            return ['issued' => true, 'reissue_refused' => true, 'persistent_gates_closed' => true];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    $authority = MemberSetupAuthority::open();
    $need($authority->identity() === $load('identity'), 'grant_changed');
    if ($mode === 'setup-hold') {
        return $authority->within('source-readback', 'source-a', function () use ($save): array {
            $save('holding', ['active' => true]);
            sleep(120);
            return ['unexpected_completion' => true];
        });
    }
    if ($mode === 'setup-stopped-readback') {
        $denied(fn () => $authority->within('source-prepare', 'source-a', fn () => []), 'member_setup_stopped');
        $result = $authority->within('source-readback', 'source-a', fn () => ['count' => Task::query()->count()]);
        $need($result['count'] === 2); $closed();
        return ['mutation_denied' => true, 'readback_allowed' => true];
    }
    if ($mode === 'setup-drift') {
        $binding = $load('initialization');
        $changes = [
            ['v2_machine_failover_policy', $binding['sources']['source-a']['policy_id'], 'cooldown_seconds', 301],
            ['v2_server_machine_release', $binding['release_id'], 'platform', 'linux-aarch64'],
        ];
        foreach ($changes as [$table, $id, $field, $value]) {
            DB::beginTransaction();
            try {
                DB::table($table)->where('id', $id)->update([$field => $value]);
                try { MemberSetupAuthority::open(); throw new LogicException('fixture_setup_drift_ignored'); }
                catch (RuntimeException $error) { $need(str_starts_with($error->getMessage(), 'member_setup_')); }
            } finally { DB::rollBack(); }
        }
        $closed();
        return ['policy_and_release_drift_refused' => true];
    }
    Http::preventStrayRequests();
    $writes = 0; $reads = 0;
    Http::fake(static function ($request) use ($load, $save, &$writes, &$reads, $need) {
        $need(parse_url($request->url(), PHP_URL_HOST) === 'api.linode.com', 'foreign_provider');
        $path = parse_url($request->url(), PHP_URL_PATH);
        $rows = $load('provider-rows') ?? [];
        if ($request->method() === 'POST') {
            $task = Task::query()->get()->first(fn ($task) => $task->payload['request']['label'] === $request['label']);
            $scope = $load('initialization');
            $role = $task?->machine_id === $scope['sources']['source-a']['plan']['machine_id'] ? 'source-a' : 'source-b';
            $need($task !== null && $task->machine_id === $scope['sources'][$role]['plan']['machine_id'], 'provider_role');
            $id = $role === 'source-a' ? 100001 : 100002;
            $need($path === '/v4/linode/instances' && !isset($rows[$id]) && count($rows) < 2, 'duplicate_create'); $writes++;
            $row = ['id' => $id, 'status' => 'running', 'label' => $request['label'], 'tags' => $request['tags'],
                'region' => $request['region'], 'type' => $request['type'], 'image' => $request['image'],
                'created' => gmdate('Y-m-d\TH:i:s'), 'ipv4' => [$role === 'source-a' ? '8.8.4.4' : '8.8.8.8'], 'ipv6' => ''];
            $rows[$id] = $row; $save('provider-rows', $rows);
            return Http::response($row);
        }
        $need($request->method() === 'GET', 'mutation_outside_create'); $reads++;
        $filter = $request->header('X-Filter')[0] ?? null;
        $visible = $rows;
        if ($filter !== null) {
            $filter = json_decode($filter, true, 8, JSON_THROW_ON_ERROR);
            $need(array_keys($filter) === ['label']);
            $visible = array_filter($rows, fn ($row) => $row['label'] === $filter['label']);
        }
        $response = match ($path) {
            '/v4/account' => ['euuid' => 'fixture-account-0001'],
            '/v4/account/settings' => ['backups_enabled' => false],
            '/v4/regions/sg-sin-2' => ['id' => 'sg-sin-2', 'status' => 'ok', 'capabilities' => ['Metadata']],
            '/v4/images/linode%2Fdebian12' => ['id' => 'linode/debian12', 'status' => 'available', 'deprecated' => false, 'capabilities' => ['cloud-init']],
            '/v4/linode/types/g6-standard-4' => ['id' => 'g6-standard-4', 'class' => 'standard'],
            '/v4/linode/instances' => ['data' => array_values($visible), 'page' => 1, 'pages' => 1, 'results' => count($visible)],
            '/v4/linode/instances/100001' => $rows[100001] ?? null,
            '/v4/linode/instances/100002' => $rows[100002] ?? null,
            default => throw new RuntimeException('fixture_setup_provider_operation'),
        };
        return Http::response($response);
    });
    $controller = new MemberSetupController($authority);
    if ($mode === 'setup-controller-hold') {
        $save('controller-holding', ['active' => true]); sleep(120);
        return ['unexpected_completion' => true];
    }
    if ($mode === 'setup-controller-first-error') {
        $base = '/opt/keli-isolated/private/member-setup-journal';
        $path = $base . '/source-queue-source-a.json'; $backup = $base . '/source-queue-source-a.saved';
        $need(rename($path, $backup));
        try {
            $denied(fn () => $controller->dispatch(['operation' => 'advance']), 'member_initialization_registration_changed');
        } finally { $need(rename($backup, $path)); }
        $first = $controller->dispatch(['operation' => 'status'])['first_error'];
        $need(array_keys($first) === ['class', 'file', 'line'] && $first['class'] === 'RuntimeException');
        $denied(fn () => $controller->dispatch(['operation' => 'advance']), 'member_setup_controller_prior_failure');
        $need($controller->dispatch(['operation' => 'status'])['first_error'] === $first);
        return ['missing_intent_blocks_mutation' => true, 'first_error_safe_and_retained' => true];
    }
    if ($mode === 'setup-status') return $controller->dispatch(['operation' => 'status']);
    if ($mode === 'setup-advance') {
        $result = $controller->dispatch(['operation' => 'advance']);
        $closed();
        return $result + ['provider_writes_simulated' => $writes];
    }
    if ($mode === 'setup-prepare') {
        $bus = app(BusContract::class);
        $denied(fn () => $authority->within('source-prepare', 'source-a', static fn () => throw new RuntimeException('fixture_failure')),
            'fixture_failure');
        $need(app(BusContract::class) === $bus); $closed();
        $receipt = $controller->dispatch(['operation' => 'advance']);
        $need(Task::query()->count() === 1 && $receipt['reason'] === 'prepare' && $receipt['role'] === 'source-a');
        $status = $controller->dispatch(['operation' => 'status']);
        $need($status['sources']['source-a']['status'] === 'queued' && !$status['sources']['source-b']['queued']);
        $closed();
        return ['native_task_queued' => true, 'b_not_queued' => true,
            'exception_restores_capability_and_bus' => true, 'provider_writes' => $writes];
    }
    if ($mode === 'setup-callback-pins') {
        foreach (Task::query()->get() as $task) {
        $scope = $load('initialization');
        $role = $task->machine_id === $scope['sources']['source-a']['plan']['machine_id'] ? 'source-a' : 'source-b';
        $need($task->dispatched_at > 0 && $task->external_id !== null);
        $yaml = Symfony\Component\Yaml\Yaml::parse(base64_decode($task->payload['request']['metadata']['user_data'], true));
        $secret = null;
        foreach ($yaml['write_files'] as $file) {
            if (!str_ends_with($file['path'], '/callback.conf')) continue;
            $need(($file['encoding'] ?? 'text/plain') === 'text/plain');
            foreach (explode("\n", $file['content']) as $line)
                if (preg_match('/^header = "Authorization: Bearer ([a-f0-9]{64})"$/D', $line, $match)) $secret = $match[1];
        }
        $need(is_string($secret) && hash_equals($task->callback_hash, hash('sha256', $secret)), 'callback_secret_missing');
        $save($role . '-callback', ['task_id' => $task->id, 'installation_id' => $task->installation_id, 'secret' => $secret,
            'machine_id' => $task->machine_id, 'token' => App\Models\ServerMachine::findOrFail($task->machine_id)->token,
            'node_id' => $scope['sources'][$role]['configured_nodes'][0]['id'], 'ip' => $task->public_ips[0],
            'created_at' => $task->created_at, 'dispatched_at' => $task->dispatched_at]);
        }
        $closed();
        return ['private_fixture_material_written' => true, 'roles' => Task::query()->count()];
    }
    if ($mode === 'setup-process' || $mode === 'setup-process-repeat') {
        $controller->dispatch(['operation' => 'advance']);
        $task = Task::query()->firstOrFail();
        if ($task->reason !== null) throw new RuntimeException('fixture_setup_native_' . $task->reason);
        $need($task->external_id === '100001' && $task->status === 'waiting_initialization'
            && $task->dispatched_at > 0 && $task->bootstrap_state === null, 'dispatch_not_ready');
        $need($writes === ($mode === 'setup-process' ? 1 : 0), 'create_count');
        $need(Task::query()->count() === 1); $closed();
        return ['native_dispatch' => true, 'provider_writes_simulated' => $writes, 'provider_reads_simulated' => $reads,
            'waiting_real_callback' => true, 'b_not_queued' => true, 'persistent_gates_closed' => true];
    }
    throw new RuntimeException('fixture_mode_invalid');
};

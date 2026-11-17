<?php
declare(strict_types=1);

use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineIntentJson;
use Illuminate\Contracts\Bus\Dispatcher as BusContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberApplicationBootstrap;
use Keli\Acceptance\MemberBootstrapController;
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
        'member_setup_authority', 'member_dns_initialization', 'member_setup_dns', 'member_native_grant', 'member_setup_controller',
        'member_prerequisite_import', 'member_bootstrap_controller'] as $file)
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
        $denied(fn () => MemberSetupAuthority::issue($configuration, time(), time() + 7200, []), 'member_application_installer_root_required');
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
    if ($mode === 'setup-probe-configuration') {
        // Synthetic client reports in a namespace without external routes; no ICMP is sent.
        admin_setting(['server_entry_probe_enable' => true, 'server_entry_probe_interval' => 5,
            'server_entry_probe_targets' => ['223.5.5.5', '119.29.29.29', '114.114.114.114', '180.76.76.76']]);
        return app(App\Services\ServerMachine\MachineEntryProbe::class)->configuration();
    }
    if ($mode === 'setup-stop' || $mode === 'setup-stop-busy') {
        // Revocation must still work when MySQL is unavailable or configuration drifted.
        DB::purge(); config(['database.connections.mysql.port' => 1]);
        if ($mode === 'setup-stop-busy') {
            $denied(fn () => MemberBootstrapController::stop(), 'member_setup_revocation_pending');
            $need(file_exists('/opt/keli-isolated/private/import-stopped'));
            return ['new_admissions_stopped' => true, 'drain_pending' => true, 'bootstrap_stop' => true];
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
        $bootstrap = new MemberBootstrapController();
        try {
            $taskId = '00000000-0000-4000-8000-000000000071';
            $control = ['run_id' => MemberCliAuthority::RUN, 'external_id' => '100000', 'task_id' => $taskId,
                'label' => 'keli-' . str_replace('-', '', $taskId), 'marker' => 'keli-create-' . str_replace('-', '', $taskId),
                'region' => 'sg-sin-2', 'size' => 'g6-standard-2', 'image' => 'linode/debian12', 'provider_created_at' => gmdate('Y-m-d\TH:i:s\Z')];
            $row = ['id' => 100000, 'label' => $control['label'], 'tags' => [$control['marker']],
                'region' => $control['region'], 'type' => $control['size'], 'image' => $control['image'],
                'created' => rtrim($control['provider_created_at'], 'Z'), 'ipv4' => ['9.9.9.10']];
            $save('control-row', $row);
            Http::preventStrayRequests();
            Http::fake(static function ($request) use ($row, $need) {
                $need($request->method() === 'GET' && $request->url() === 'https://api.linode.com/v4/linode/instances/100000');
                return Http::response($row);
            });
            $request = ['operation' => 'setup-issue', 'control' => $control];
            $identity = $bootstrap->rpc($request)['setup'];
            $save('initialization', $bootstrap->rpc(['operation' => 'configuration-read'])['configuration']['initialization_scope']);
            $save('identity', $identity);
            $grant = '/opt/keli-isolated/member-setup-grant.json'; $before = file_get_contents($grant);
            $need($bootstrap->rpc($request)['setup'] === $identity && file_get_contents($grant) === $before);
            $original = json_decode(file_get_contents($root . '/bootstrap-authority'), true, 32, JSON_THROW_ON_ERROR);
            $need(MemberSetupAuthority::open()->windowBinding() === ['started_at' => $original['started_at'], 'deadline_at' => $original['deadline_at']]);
            $resultPath = $root . '/member-import/setup-issuance-result.json'; $backup = $resultPath . '.saved';
            $need(rename($resultPath, $backup));
            try {
                $need($bootstrap->rpc(['operation' => 'setup-read'])['setup'] === $identity);
                $need(file_get_contents($resultPath) === file_get_contents($backup) && file_get_contents($grant) === $before);
            } finally { unlink($backup); }
            $changed = $request; $changed['control']['external_id'] = '100099';
            $denied(fn () => $bootstrap->rpc($changed), 'member_import_setup_binding_changed');
            $closed();
            return ['issued' => true, 'reissue_refused' => true, 'persistent_gates_closed' => true,
                'actual_bootstrap_controller' => true, 'original_window_inherited' => true,
                'lost_result_reconciled_without_reissue' => true, 'changed_control_refused' => true];
        } finally { $bootstrap->close(); }
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
        foreach (['dns-control', 'dns-edges'] as $operation)
            $denied(fn () => $authority->within($operation, 'control', fn () => []), 'member_setup_stopped');
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
    Http::fake(static function ($request) use ($load, $save, &$writes, &$reads, $need, $mode) {
        if ($request->url() === 'https://alidns.aliyuncs.com/') {
            $need(is_string($request['Signature']), 'dns_signature');
            $dns = $load('setup-dns-provider') ?? ['rows' => [], 'next_id' => 6001, 'create' => 0, 'claim' => 0, 'delete' => 0];
            $action = $request['Action'];
            if ($action === 'DescribeSubDomainRecords') {
                $rows = array_values(array_filter($dns['rows'], fn ($r) => $r['RR'] . '.dnscloudcloud.top' === $request['SubDomain']));
                return Http::response(['TotalCount' => count($rows), 'PageNumber' => 1, 'PageSize' => 50, 'DomainRecords' => ['Record' => $rows]]);
            }
            if ($action === 'DescribeDomainRecordInfo') return isset($dns['rows'][$request['RecordId']])
                ? Http::response($dns['rows'][$request['RecordId']]) : Http::response(['Code' => 'InvalidRR.NoExist'], 400);
            if ($action === 'AddDomainRecord') {
                $id = (string) $dns['next_id']++; $dns['create']++;
                $dns['rows'][$id] = ['RecordId' => $id, 'DomainName' => 'dnscloudcloud.top', 'RR' => $request['RR'],
                    'Type' => $request['Type'], 'Value' => $request['Value'], 'Line' => 'default', 'TTL' => 600, 'Remark' => '', 'Status' => 'ENABLE'];
            } else {
                $id = $request['RecordId']; $need(isset($dns['rows'][$id]), 'dns_identity');
                if ($action === 'UpdateDomainRecordRemark') { $dns['rows'][$id]['Remark'] = $request['Remark']; $dns['claim']++; }
                elseif ($action === 'DeleteDomainRecord') { unset($dns['rows'][$id]); $dns['delete']++; }
                else throw new RuntimeException('fixture_setup_dns_operation');
            }
            $save('setup-dns-provider', $dns);
            if ($mode === 'setup-cleanup-crash' && $action === 'DeleteDomainRecord' && $id === '6001') {
                // The simulated provider committed; the actual RPC has not received its reply.
                $save('cleanup-crash-boundary', ['provider_delete_committed' => true, 'dns_delete_calls' => $dns['delete']]);
                sleep(60);
                throw new RuntimeException('fixture_crash_injection_not_killed');
            }
            return Http::response(['RecordId' => $id, 'RequestId' => 'fixture-request']);
        }
        $need(parse_url($request->url(), PHP_URL_HOST) === 'api.linode.com', 'foreign_provider');
        $path = parse_url($request->url(), PHP_URL_PATH);
        $rows = $load('provider-rows') ?? [];
        if ($request->method() === 'POST') {
            $task = Task::query()->get()->first(fn ($task) => $task->payload['request']['label'] === $request['label']);
            $scope = $load('initialization');
            $role = $task?->machine_id === $scope['sources']['source-a']['plan']['machine_id'] ? 'source-a' : 'source-b';
            $need($task !== null && $task->machine_id === $scope['sources'][$role]['plan']['machine_id'], 'provider_role');
            $id = $role === 'source-a' ? 100001 : 100002;
            $candidate = isset($task->payload['coordinated_attempt']);
            if ($candidate) {
                $attempt = App\Models\MachineFailoverAttempt::findOrFail($task->payload['coordinated_attempt']);
                $need($mode === 'setup-candidate-step' && $role === 'source-a' && $attempt->ordinal === 1
                    && $attempt->task_id === $task->id && $attempt->account_id === $scope['account_id'], 'candidate_create_scope');
                $id = 100003;
            }
            $need($path === '/v4/linode/instances' && !isset($rows[$id])
                && count($rows) === ($candidate ? 2 : ($role === 'source-a' ? 0 : 1)), 'duplicate_create'); $writes++;
            $row = ['id' => $id, 'status' => 'running', 'label' => $request['label'], 'tags' => $request['tags'],
                'region' => $request['region'], 'type' => $request['type'], 'image' => $request['image'],
                'created' => gmdate('Y-m-d\TH:i:s'),
                'ipv4' => [$candidate ? '9.9.9.11' : ($role === 'source-a' ? '8.8.4.4' : '8.8.8.8')], 'ipv6' => ''];
            $rows[$id] = $row; $save('provider-rows', $rows);
            return Http::response($row);
        }
        if ($request->method() === 'DELETE') {
            $need(str_starts_with($mode, 'setup-cleanup') && preg_match('~/linode/instances/(10000[12])$~D', $path, $match) === 1, 'delete_scope');
            $id = (int) $match[1]; $need(isset($rows[$id]), 'duplicate_delete');
            $calls = $load('cleanup-delete-count') ?? ['value' => 0]; $calls['value']++;
            $save('cleanup-delete-count', $calls);
            unset($rows[$id]); $save('provider-rows', $rows);
            if ($id === 100001) throw new Illuminate\Http\Client\ConnectionException('fixture reply lost after delete');
            return Http::response('', 204);
        }
        $need($request->method() === 'GET', 'mutation_outside_create'); $reads++;
        if ($path === '/v4/linode/instances/100000') return Http::response($load('control-row'));
        if ($mode === 'setup-cleanup-read-error' && $path === '/v4/linode/instances/100002')
            return Http::response(['errors' => [['reason' => 'fixture unavailable']]], 503);
        if (preg_match('~/linode/instances/(10000[12])$~D', $path, $match) && !isset($rows[(int) $match[1]]))
            return Http::response(['errors' => [['reason' => 'Not found']]], 404);
        $filter = $request->header('X-Filter')[0] ?? null;
        $catalogRows = match ($path) {
            '/v4/regions' => [['id' => 'sg-sin-2', 'label' => 'Singapore', 'status' => 'ok', 'site_type' => 'core',
                'capabilities' => ['Linodes', 'Metadata']]],
            '/v4/linode/types' => [['id' => 'g6-standard-4', 'class' => 'standard', 'vcpus' => 4,
                'memory' => 8192, 'disk' => 163840, 'price' => ['monthly' => 48]]],
            '/v4/images' => [['id' => 'linode/debian12', 'label' => 'Debian 12', 'is_public' => true,
                'deprecated' => false, 'status' => 'available', 'capabilities' => ['cloud-init'], 'min_disk_size' => 4]],
            default => null,
        };
        if ($catalogRows !== null) {
            $save('catalog-reads', ['count' => ($load('catalog-reads')['count'] ?? 0) + 1]);
            return Http::response(['data' => $catalogRows, 'page' => 1, 'pages' => 1, 'results' => count($catalogRows)]);
        }
        // Capacity includes the already-created controller, not just provisioned sources.
        $visible = [$load('control-row'), ...array_values($rows)];
        if ($filter !== null) {
            $filter = json_decode($filter, true, 8, JSON_THROW_ON_ERROR);
            $need(array_keys($filter) === ['label']);
            $visible = array_filter($visible, fn ($row) => $row['label'] === $filter['label']);
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
            '/v4/linode/instances/100003' => $rows[100003] ?? null,
            default => throw new RuntimeException('fixture_setup_provider_operation'),
        };
        return Http::response($response);
    });
    if (str_starts_with($mode, 'setup-candidate-') && $mode !== 'setup-candidate-pins') {
        [$nativeScope, $nativeHash] = MemberCliAuthority::readGrant();
        $nativeAuthority = MemberCliAuthority::controller($nativeScope, $nativeHash,
            static fn () => file_exists('/opt/keli-isolated/member-setup-stop.json'));
        $nativeAuthority->allow('round-status'); $nativeAuthority->assertData();
        $d = $nativeScope['driver'];
        $bFacts = static function () use ($nativeScope, $d, $load): array {
            $task = Task::findOrFail($nativeScope['sources']['source-b']['task_id']);
            return ['task' => $task->getAttributes(),
                'owned' => App\Models\MachineOwnedInstance::where('task_id', $task->id)->firstOrFail()->getAttributes(),
                'dns' => $load('setup-dns-provider'),
                'b_executions' => App\Models\MachineFailoverExecutionMember::where('policy_id', $d['policy_b'])->count()];
        };
        $hash = MemberProviderBindings::digest($bFacts());
        if ($mode === 'setup-candidate-start') {
            if ($load('candidate-b-baseline') === null) $save('candidate-b-baseline', ['hash' => $hash]);
            $request = substr(hash('sha256', MachineIntentJson::encode([MemberCliAuthority::RUN, 'native-a'])), 0, 32);
            $result = $nativeAuthority->within('round-step', 'control', static fn () =>
                app(App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge::class)->start(
                    $d['service_id'], $d['service_revision'], [$d['plan_id']], $d['release_id'], $request, $d['admin_id']));
            $saved = $load('candidate-execution');
            $need($saved === null || $saved['id'] === $result['id'], 'candidate_execution_replaced');
            if ($saved === null) $save('candidate-execution', ['id' => $result['id']]);
            $need(Task::count() === 2 && $writes === 0, 'candidate_start_created_task');
        } elseif ($mode === 'setup-candidate-step') {
            $execution = $load('candidate-execution'); $need(is_array($execution), 'candidate_execution_missing');
            $result = $nativeAuthority->within('round-step', 'control', static fn () =>
                app(App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge::class)->advance($execution['id'], $d['admin_id']));
        } elseif ($mode !== 'setup-candidate-inspect') throw new RuntimeException('fixture_candidate_mode');
        $need($load('candidate-b-baseline')['hash'] === MemberProviderBindings::digest($bFacts()), 'candidate_changed_b_or_dns');
        $execution = App\Models\MachineFailoverExecution::findOrFail($load('candidate-execution')['id']);
        $member = App\Models\MachineFailoverExecutionMember::where('execution_id', $execution->id)->sole();
        $attempt = $member->attempt_id ? App\Models\MachineFailoverAttempt::findOrFail($member->attempt_id) : null;
        $task = $attempt?->task_id ? Task::findOrFail($attempt->task_id) : null;
        $health = null; $at = null;
        if ($task) {
            $machine = App\Models\ServerMachine::findOrFail($task->machine_id);
            $row = app(App\Services\ServerMachine\MachineAddressInventory::class)
                ->snapshots([$machine], true)[$machine->id][$task->installation_id] ?? [];
            $at = $row['at'] ?? null;
            $health = app(App\Services\ServerMachine\Cloud\MachineCloudHealth::class)->snapshot(
                new App\Models\MachineCloudResource(['machine_id' => $task->machine_id,
                    'installation_id' => $task->installation_id, 'machine_authority' => $task->machine_authority,
                    'public_ips' => $task->public_ips]), $task->dispatched_at);
        }
        $closed();
        return ['native_execution' => $execution->status, 'member_phase' => $member->phase,
            'member_reason' => $member->reason, 'task_status' => $task?->status, 'task_reason' => $task?->reason,
            'task_count' => Task::count(), 'attempt_count' => App\Models\MachineFailoverAttempt::count(),
            'callback_state' => $task?->bootstrap_state, 'report_at' => $at, 'health' => $health,
            'callback_steps' => $task ? DB::table('v2_machine_provision_step')->where('task_id', $task->id)
                ->where('state', 'like', 'bootstrap_%')->count() : 0,
            'provider_writes_simulated' => $writes, 'provider_rows' => count($load('provider-rows') ?? []),
            'b_and_dns_unchanged' => true, 'persistent_gates_closed' => true];
    }
    $controller = new MemberSetupController($authority);
    $rpcRequest = static function (string $operation) use ($authority, $load, $save): array {
        $sequence = ($load('rpc-sequence')['value'] ?? -1) + 1;
        $save('rpc-sequence', ['value' => $sequence]);
        return ['schema' => 1, 'run_id' => MemberCliAuthority::RUN] + $authority->identity()
            + ['request_id' => bin2hex(random_bytes(16)), 'sequence' => $sequence, 'operation' => $operation];
    };
    $advance = static fn () => $controller->rpc($rpcRequest('advance'))['result'];
    if ($mode === 'setup-native-issue' || $mode === 'setup-native-read') {
        $operation = substr($mode, strlen('setup-'));
        $request = $rpcRequest($operation);
        $reply = $controller->rpc($request); $result = $reply['result'];
        $closed(); $need($writes === 0, 'native_grant_wrote_provider');
        if ($result['ready']) {
            $before = file_get_contents('/opt/keli-isolated/private/member-runtime-scope.json');
            $need($controller->rpc($request) === $reply
                && file_get_contents('/opt/keli-isolated/private/member-runtime-scope.json') === $before, 'native_rpc_reissued');
            $scope = Keli\Acceptance\MemberCliAuthority::readGrant()[0];
            $need($scope['started_at'] === $authority->windowBinding()['started_at']
                && $scope['deadline_at'] === $authority->windowBinding()['deadline_at'], 'native_window_extended');
            $need(Task::query()->count() === 2 && App\Models\MachineFailoverExecution::query()->count() === 0,
                'native_grant_created_task');
            $need(App\Models\MachineFailoverObservation::query()->where('policy_id', $scope['driver']['policy_b'])->count() === 0,
                'native_grant_observed_b');
        }
        return $result;
    }
    if (in_array($mode, ['setup-dns-control', 'setup-dns-edges', 'setup-dns-readback'], true)) {
        $operation = substr($mode, strlen('setup-'));
        $before = app(App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate::class);
        if ($operation === 'dns-edges') app()->instance(App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate::class, $before);
        $shared = app()->isShared(App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate::class);
        $request = $rpcRequest($operation); $reply = $controller->rpc($request);
        $need($controller->rpc($request) === $reply, 'dns_rpc_replayed');
        $after = app(App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate::class);
        $need(get_class($after) === get_class($before)
            && app()->isShared(App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate::class) === $shared
            && (!$shared || $after === $before), 'dns_gate_not_restored');
        if ($operation === 'dns-control') $need(Task::query()->count() === 0, 'control_dns_after_sources');
        if ($operation === 'dns-edges') {
            $need(Task::query()->where('status', 'accepted')->count() === 2, 'dns_before_registration');
            $scope = $authority->sourceBinding();
            $snapshot = app(App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry::class)
                ->snapshot($scope['configuration']['service_id'], $scope['configuration']['service_revision']);
            foreach ($reply['result']['records'] as $pin) {
                $set = collect($snapshot['dns_sets'])->firstWhere('hostname', $pin['name']);
                $need($pin['marker'] === $set['slots'][0]['marker'], 'dns_marker_not_native');
            }
        }
        $provider = $load('setup-dns-provider'); $closed();
        return ['ready' => $reply['result']['ready'], 'record_count' => count($reply['result']['records']),
            'provider_create' => $provider['create'], 'provider_claim' => $provider['claim'],
            'provider_delete' => $provider['delete'], 'gate_restored' => true];
    }
    if (str_starts_with($mode, 'setup-cleanup')) {
        $before = Task::query()->orderBy('id')->get()->toArray();
        $firstError = $controller->dispatch(['operation' => 'status'])['first_error'];
        $authority->stop();
        if ($mode === 'setup-cleanup-resume') {
            $saved = $load('cleanup-crash-request'); $need(is_array($saved), 'crash_request_missing');
            $request = $saved['request'];
            $need(MemberProviderBindings::digest($before) === $saved['tasks_sha256']
                && $firstError === $saved['first_error'], 'crash_changed_history');
        } else $request = $rpcRequest('cleanup');
        if ($mode === 'setup-cleanup-crash') $save('cleanup-crash-request', ['request' => $request,
            'tasks_sha256' => MemberProviderBindings::digest($before), 'first_error' => $firstError]);
        $reply = $controller->rpc($request);
        $need($controller->rpc($request) === $reply, 'cleanup_replay_changed');
        $need(Task::query()->orderBy('id')->get()->toArray() === $before, 'cleanup_task_history_changed');
        $need($controller->dispatch(['operation' => 'status'])['first_error'] === $firstError, 'cleanup_first_error_changed');
        $denied(fn () => $controller->rpc($rpcRequest('advance')), 'member_setup_stopped');
        $need($writes === 0); $closed();
        return $reply['result'] + ['delete_calls' => $load('cleanup-delete-count')['value'] ?? 0,
            'dns_delete_calls' => $load('setup-dns-provider')['delete'] ?? 0,
            'provider_rows_remaining' => count($load('provider-rows') ?? []), 'task_history_unchanged' => true,
            'first_error_retained' => true, 'duplicate_rpc_cached' => true, 'advance_stays_revoked' => true];
    }
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
        $result = $advance();
        $closed();
        return $result + ['provider_writes_simulated' => $writes];
    }
    if ($mode === 'setup-prepare') {
        $controller->close();
        $path = '/opt/keli-isolated/private/member-setup-journal/binding.json';
        $need(chmod($path, 0644));
        try { $denied(fn () => new MemberSetupController($authority), 'member_setup_controller_journal_file_mode'); }
        finally { $need(chmod($path, 0600)); }
        $controller = new MemberSetupController($authority);
        $bus = app(BusContract::class);
        $denied(fn () => $authority->within('source-prepare', 'source-a', static fn () => throw new RuntimeException('fixture_failure')),
            'fixture_failure');
        $need(app(BusContract::class) === $bus); $closed();
        $request = $rpcRequest('advance');
        $response = $controller->rpc($request);
        $receipt = $response['result'];
        $need($controller->rpc($request) === $response, 'rpc_replay_changed');
        $denied(fn () => $controller->rpc(array_replace($request, ['request_id' => str_repeat('f', 32)])),
            'member_setup_controller_request_sequence_reused');
        $denied(fn () => $controller->rpc(array_replace($request, ['grant_sha256' => str_repeat('0', 64)])),
            'member_setup_controller_request_binding_changed');
        $need(Task::query()->count() === 1 && $receipt['reason'] === 'prepare' && $receipt['role'] === 'source-a');
        $status = $controller->dispatch(['operation' => 'status']);
        $need($status['sources']['source-a']['status'] === 'queued' && !$status['sources']['source-b']['queued']);
        $closed();
        return ['native_task_queued' => true, 'b_not_queued' => true,
            'exception_restores_capability_and_bus' => true, 'provider_writes' => $writes,
            'rpc_replay_cached' => true, 'rpc_sequence_and_grant_checked' => true, 'existing_journal_permissions_not_repaired' => true];
    }
    if ($mode === 'setup-callback-pins' || $mode === 'setup-candidate-pins') {
        $exported = 0;
        foreach (Task::query()->get() as $task) {
        $candidate = isset($task->payload['coordinated_attempt']);
        if ($candidate !== ($mode === 'setup-candidate-pins')) continue;
        $scope = $load('initialization');
        $role = $task->machine_id === $scope['sources']['source-a']['plan']['machine_id'] ? 'source-a' : 'source-b';
        $nodeId = $scope['sources'][$role]['configured_nodes'][0]['id'];
        if ($candidate) {
            $cap = json_decode(file_get_contents('/opt/keli-isolated/member-candidate-callbacks.json'), true, 32, JSON_THROW_ON_ERROR);
            $need(Keli\Acceptance\MemberCandidateCallbacks::role($cap, $task) === 'source-a', 'candidate_lineage');
            $role = 'candidate-a1';
        }
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
            'node_id' => $nodeId, 'ip' => $task->public_ips[0],
            'created_at' => $task->created_at, 'dispatched_at' => $task->dispatched_at]);
        $exported++;
        }
        $closed();
        return ['private_fixture_material_written' => true, 'roles' => $exported];
    }
    if ($mode === 'setup-process' || $mode === 'setup-process-repeat') {
        $advance();
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

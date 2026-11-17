<?php
declare(strict_types=1);

use App\Models\MachineCloudAccount;
use App\Models\MachineFailoverPolicy;
use App\Models\Server;
use App\Models\ServerMachineRelease;
use App\Models\User;
use App\Services\ServerMachine\Cloud\MachineCloudGroupService;
use App\Services\ServerMachine\Cloud\MachineCloudService;
use App\Services\ServerMachine\Cloud\MachineFailoverCredentials;
use App\Services\ServerMachine\Dns\MachineDnsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberApplicationBootstrap;
use Keli\Acceptance\MemberCliAuthority;
use Keli\Acceptance\MemberProviderBindings;
use Keli\Acceptance\MemberSourceConfiguration;

// Prerequisite credentials/release are synthetic fixture input, not production imports.
// Machines, nodes, policies, members and DNS sets are made by the actual setup class.
return static function (string $mode): array {
    if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('KELI_MEMBER_FULL_APPLICATION') !== '1'
        || PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || posix_geteuid() !== 0)
        throw new RuntimeException('fixture_only');
    MemberApplicationBootstrap::initialized();
    require_once '/opt/keli-isolated/runtime/member_provider_bindings.php';
    require_once '/opt/keli-isolated/runtime/member_source_configuration.php';
    $need = static function (bool $ok, string $code = 'invalid'): void {
        if (!$ok) throw new RuntimeException('fixture_configuration_' . $code);
    };
    $same = static fn (array $a, array $b): bool => MemberProviderBindings::digest($a) === MemberProviderBindings::digest($b);
    $root = '/opt/keli-isolated/private/configuration-fixture';
    if ($mode === 'configuration-prepare') { $need(!file_exists($root)); mkdir($root, 0700); }
    $need(realpath($root) === $root && (fileperms($root) & 0077) === 0);
    $lock = fopen($root . '/lock', 'c+b'); $need($lock !== false && flock($lock, LOCK_EX | LOCK_NB));
    chmod($root . '/lock', 0600);
    $load = static function (string $key) use ($root, $need): ?array {
        $need(preg_match('/^[a-z0-9-]{1,100}$/D', $key) === 1);
        $path = $root . '/' . $key . '.json';
        if (!file_exists($path)) return null;
        $need(realpath($path) === $path && (fileperms($path) & 0077) === 0);
        return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    };
    $save = static function (string $key, array $value) use ($root, $need): void {
        $need(preg_match('/^[a-z0-9-]{1,100}$/D', $key) === 1);
        $tmp = $root . '/.' . bin2hex(random_bytes(12));
        $f = fopen($tmp, 'x+b'); $need($f !== false); chmod($tmp, 0600);
        try {
            $raw = json_encode($value, JSON_THROW_ON_ERROR);
            $need(fwrite($f, $raw) === strlen($raw) && fflush($f) && fsync($f));
        } finally { fclose($f); }
        $need(rename($tmp, $root . '/' . $key . '.json'));
    };
    $tables = ['v2_server_machine', 'v2_server', 'v2_machine_failover_policy', 'v2_machine_failover_service',
        'v2_machine_failover_member', 'v2_machine_failover_dns_set', 'v2_machine_failover_dns_slot'];
    $counts = static fn () => array_map(fn ($table) => DB::table($table)->count(), $tables);
    $reads = 0;
    Http::preventStrayRequests();
    Http::fake(static function ($request) use (&$reads, $need) {
        $need($request->method() === 'GET' && parse_url($request->url(), PHP_URL_HOST) === 'api.linode.com');
        $reads++;
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v4/account') return Http::response(['euuid' => 'fixture-account-0001']);
        $rows = match ($path) {
            '/v4/regions' => [['id' => 'sg-sin-2', 'label' => 'Singapore', 'status' => 'ok', 'site_type' => 'core',
                'capabilities' => ['Linodes', 'Metadata']]],
            '/v4/linode/types' => [['id' => 'g6-standard-4', 'class' => 'standard', 'vcpus' => 4,
                'memory' => 8192, 'disk' => 163840, 'price' => ['monthly' => 48]]],
            '/v4/images' => [['id' => 'linode/debian12', 'label' => 'Debian 12', 'is_public' => true,
                'deprecated' => false, 'status' => 'available', 'capabilities' => ['cloud-init'], 'min_disk_size' => 4]],
            default => throw new RuntimeException('fixture_configuration_provider_operation'),
        };
        return Http::response(['data' => $rows, 'page' => 1, 'pages' => 1, 'results' => count($rows)]);
    });
    try {
        if ($mode === 'configuration-prepare') {
            $need(!User::query()->exists() && !MachineCloudAccount::query()->exists());
            $admin = User::query()->create(['email' => 'r35-fixture@example.invalid', 'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
                'uuid' => (string) Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)), 'is_admin' => true]);
            $group = app(MachineCloudGroupService::class)->save(['name' => 'R35 fixture']);
            $account = app(MachineCloudService::class)->saveAccount(['name' => 'R35 fixture', 'provider' => 'linode',
                'api_token' => 'synthetic-no-live-token', 'group_id' => $group->id,
                'provision_limits' => ['max_instances' => 4, 'max_parallel' => 1, 'daily_limit' => 5]]);
            $dns = app(MachineDnsService::class)->saveCredential(['name' => 'R35 source fixture', 'provider' => 'aliyun',
                'access_key_id' => 'synthetic-id', 'access_key_secret' => 'synthetic-secret'], $admin->id);
            $release = ServerMachineRelease::query()->create(['component' => 'kelinode-rs', 'platform' => 'linux-x86_64',
                'version' => 'v0.1.387', 'status' => 'active', 'sha256' => str_repeat('c', 64), 'binary_sha256' => str_repeat('d', 64),
                'archive_path' => 'fixture-not-published.tar.gz', 'manifest_path' => 'fixture-not-published.json']);
            $scope = ['run_id' => MemberProviderBindings::RUN, 'admin_id' => $admin->id,
                'account_id' => $account->id, 'account_revision' => $account->revision, 'group_id' => $group->id,
                'dns_credential_id' => $dns['id'], 'dns_credential_revision' => $dns['revision'],
                'release_id' => $release->id, 'binary_sha256' => $release->binary_sha256,
                'version' => $release->version, 'baseline_ids' => []];
            $save('scope', $scope);
            return ['prepared' => true, 'prerequisites_synthetic' => true, 'configuration_rows' => $counts()];
        }
        $scope = $load('scope'); $need(is_array($scope));
        $guard = static function (string $operation) use ($load): void {
            if ($load('stopped') !== null && $operation !== 'source-configuration-read')
                throw new RuntimeException('fixture_configuration_stopped');
        };
        $setup = new MemberSourceConfiguration($scope, $guard, $load, $save);
        if ($mode === 'configuration-failures') {
            $phases = ['source-configure-commit', 'source-configure-a', 'source-configure-b',
                'source-configure-service', 'source-configure-dns-a', 'source-configure-dns-b', 'source-configure-complete'];
            foreach ($phases as $index => $phase) {
                $get = static fn ($key) => $load('failure-' . $index . '-' . $key);
                $put = static fn ($key, $value) => $save('failure-' . $index . '-' . $key, $value);
                $failureGuard = static function ($operation) use ($phase): void {
                    if ($operation === $phase) throw new RuntimeException('synthetic-private-failure-detail');
                };
                $failed = new MemberSourceConfiguration($scope, $failureGuard, $get, $put);
                try { $failed->create(); throw new LogicException('fixture_failure_missing'); }
                catch (RuntimeException $error) { $need($error->getMessage() === 'synthetic-private-failure-detail'); }
                $need($counts() === array_fill(0, 7, 0));
                $first = $get('source-configuration-first-error');
                $need(array_keys($first) === ['class', 'file', 'line'] && $first['class'] === 'RuntimeException');
                $before = $reads;
                try { $failed->create(); throw new LogicException('fixture_replay_allowed'); }
                catch (RuntimeException $error) { $need($error->getMessage() === 'member_configuration_result_unconfirmed'); }
                $need($reads === $before && $first === $get('source-configuration-first-error'));
            }
            return ['rollback_phases' => count($phases), 'first_error_safe_and_retained' => true,
                'uncertain_replay_refused' => true, 'configuration_rows' => $counts(), 'provider_gets' => $reads];
        }
        if ($mode === 'configuration-create') {
            $result = $setup->create(); $save('observed', $result);
        } elseif ($mode === 'configuration-read') {
            $result = $setup->readback(); $need($same($result, $load('observed')), 'readback_changed');
        } elseif ($mode === 'configuration-repeat') {
            $result = $setup->create(); $need($same($result, $load('observed')), 'repeat_changed');
        } elseif ($mode === 'configuration-recovery') {
            app()->instance(MachineFailoverCredentials::class, new class extends MachineFailoverCredentials {
                public function selection(MachineFailoverPolicy $policy): array {
                    throw new RuntimeException('fixture_recovery_must_not_select_again');
                }
            });
            $result = $setup->readback(); $need($same($result, $load('observed')), 'recovery_changed');
            $receipt = $root . '/source-configuration-result.json';
            $backup = $root . '/source-configuration-result.saved';
            $need(rename($receipt, $backup));
            try {
                try { $setup->create(); throw new LogicException('fixture_unconfirmed_commit_replayed'); }
                catch (RuntimeException $error) { $need($error->getMessage() === 'member_configuration_result_unconfirmed'); }
                $need($counts() === [2, 2, 2, 1, 2, 2, 2] && $reads === 0);
            } finally { $need(rename($backup, $receipt)); }
            $need($same($setup->readback(), $result), 'recovery_changed');
            $get = static fn ($key) => $load('fresh-' . $key);
            $put = static fn ($key, $value) => $save('fresh-' . $key, $value);
            try {
                (new MemberSourceConfiguration($scope, $guard, $get, $put))->create();
                throw new LogicException('fixture_existing_configuration_adopted');
            } catch (RuntimeException $error) { $need($error->getMessage() === 'member_configuration_fresh_database_required'); }
            $need($get('source-configuration-intent') === null);
        } elseif ($mode === 'configuration-stop') {
            $save('stopped', ['stop' => true]);
            try { $setup->create(); throw new LogicException('fixture_stop_ignored'); }
            catch (RuntimeException $error) { $need($error->getMessage() === 'fixture_configuration_stopped'); }
            $result = $setup->readback(); $need($same($result, $load('observed')), 'stop_readback_changed');
        } elseif ($mode === 'configuration-drift') {
            $original = $load('observed'); $a = $original['sources']['source-a'];
            $changes = [
                ['v2_server', $a['node_id'], 'port', '24444'],
                ['v2_machine_failover_policy', $a['policy_id'], 'cooldown_seconds', 301],
                ['v2_machine_cloud_account', $scope['account_id'], 'revision', 2],
            ];
            foreach ($changes as [$table, $id, $field, $value]) {
                DB::beginTransaction();
                try {
                    DB::table($table)->where('id', $id)->update([$field => $value]);
                    try { $setup->readback(); throw new LogicException('fixture_drift_ignored'); }
                    catch (RuntimeException $error) { $need(str_starts_with($error->getMessage(), 'member_configuration_')); }
                } finally { DB::rollBack(); }
            }
            $result = $setup->readback(); $need($same($result, $original), 'drift_readback_changed');
        } else throw new RuntimeException('fixture_mode_invalid');
        $need($counts() === [2, 2, 2, 1, 2, 2, 2]);
        $need($result['service_revision'] === 3 && !$result['health_or_forwarding_claimed'] && !$result['cloud_or_dns_writes']);
        $need(MachineFailoverPolicy::query()->orderBy('id')->pluck('daily_limit')->all() === [3, 1]);
        foreach (['v2_machine_provision_task', 'v2_machine_owned_instance', 'v2_machine_failover_execution'] as $table)
            $need(DB::table($table)->count() === 0);
        foreach (MemberCliAuthority::closed() as $key => $value) $need(config($key) === $value);
        $encrypted = DB::table('v2_machine_cloud_account')->where('id', $scope['account_id'])->value('credentials');
        $need(!str_contains($encrypted, 'synthetic-no-live-token'));
        return ['configuration_rows' => $counts(), 'provider_gets' => $reads, 'provider_writes' => 0,
            'tasks_or_health_fabricated' => false, 'closed_gates_unchanged' => true,
            'service_revision' => $result['service_revision'], 'configuration_sha256' => $result['service_sha256']];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
};

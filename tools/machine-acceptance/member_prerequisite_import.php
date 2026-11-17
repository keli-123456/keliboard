<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
use App\Models\ServerMachineRelease;
use App\Models\User;
use App\Services\ServerMachine\Cloud\MachineCloudGroupService;
use App\Services\ServerMachine\Cloud\MachineCloudService;
use App\Services\ServerMachine\Dns\MachineDnsService;
use App\Services\ServerMachine\MachineReleaseDistributionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Fresh isolated prerequisites. Never reads production or grants cloud execution. */
final class MemberPrerequisiteImport
{
    private const ROOT = '/opt/keli-isolated';
    private const EMPTY = ['v2_user', 'v2_machine_cloud_group', 'v2_machine_cloud_account',
        'v2_machine_dns_credential', 'v2_server_machine_release', 'v2_server_machine', 'v2_server',
        'v2_machine_provision_task', 'v2_machine_failover_service'];

    public function __construct(private \Closure $guard, private \Closure $load, private \Closure $save) {}

    private static function need(bool $ok, string $reason): void
    {
        if (!$ok) throw new \RuntimeException('member_import_' . $reason);
    }

    private static function read(string $relative, int $mode, int $limit): string
    {
        $path = self::ROOT . '/' . $relative;
        clearstatcache(true, $path); $stat = @lstat($path);
        self::need($stat && realpath($path) === $path && is_file($path) && $stat['uid'] === 0
            && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === $mode && $stat['size'] <= $limit, 'file_invalid');
        $stream = fopen($path, 'rb'); self::need($stream !== false, 'file_unreadable');
        try {
            $opened = fstat($stream);
            self::need($opened['ino'] === $stat['ino'] && $opened['dev'] === $stat['dev'], 'file_changed');
            $raw = stream_get_contents($stream, $limit + 1);
            self::need(is_string($raw) && strlen($raw) === $stat['size'], 'file_changed');
            return $raw;
        } finally { fclose($stream); }
    }

    public static function material(array $value): array
    {
        self::need(count($value) === 5 && !array_diff(array_keys($value), ['schema', 'run_id', 'linode', 'aliyun', 'baseline_ids'])
            && $value['schema'] === 1 && $value['run_id'] === MemberCliAuthority::RUN
            && is_array($value['linode']) && array_keys($value['linode']) === ['api_token']
            && is_array($value['aliyun']) && count($value['aliyun']) === 2
            && !array_diff(array_keys($value['aliyun']), ['access_key_id', 'access_key_secret']), 'material_invalid');
        foreach ([$value['linode']['api_token'], $value['aliyun']['access_key_id'], $value['aliyun']['access_key_secret']] as $secret)
            self::need(is_string($secret) && strlen($secret) >= 8 && strlen($secret) <= 4096
                && trim($secret) === $secret && !preg_match('/[\x00-\x20\x7f]/', $secret), 'material_invalid');
        $ids = $value['baseline_ids'];
        self::need(is_array($ids) && array_is_list($ids) && count($ids) <= 996
            && count(array_unique($ids, SORT_REGULAR)) === count($ids), 'baseline_invalid');
        foreach ($ids as $id) self::need(is_string($id) && preg_match('/^[1-9][0-9]{0,15}$/D', $id), 'baseline_invalid');
        $value['aliyun'] = ['access_key_id' => $value['aliyun']['access_key_id'], 'access_key_secret' => $value['aliyun']['access_key_secret']];
        return $value;
    }

    private function context(bool $mutating): array
    {
        self::need(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux' && posix_geteuid() === 0, 'root_cli_required');
        MemberApplicationBootstrap::initialized();
        $application = MemberApplicationBootstrap::receipt();
        $private = self::ROOT . '/private'; $stat = @lstat($private);
        self::need($stat && realpath($private) === $private && is_dir($private)
            && $stat['uid'] === 0 && ($stat['mode'] & 0777) === 0700, 'private_directory_invalid');
        $rawAuthority = self::read('private/bootstrap-authority', 0600, 16384);
        $authority = json_decode($rawAuthority, true, 16, JSON_THROW_ON_ERROR);
        $keys = ['schema', 'run_id', 'operation', 'authorized', 'bundle_sha256', 'machine_id_sha256',
            'started_at', 'deadline_at', 'credentials_sha256'];
        self::need(is_array($authority) && count($authority) === count($keys) && !array_diff(array_keys($authority), $keys)
            && $authority['schema'] === 1 && $authority['run_id'] === MemberCliAuthority::RUN
            && $authority['operation'] === 'private-import' && $authority['authorized'] === true
            && $authority['bundle_sha256'] === $application['bundle_sha256']
            && $authority['machine_id_sha256'] === $application['machine_id_sha256']
            && is_int($authority['started_at']) && is_int($authority['deadline_at'])
            && $authority['started_at'] > 0 && $authority['deadline_at'] > $authority['started_at'] + 1200
            && $authority['deadline_at'] <= $authority['started_at'] + 7200
            && is_string($authority['credentials_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $authority['credentials_sha256']), 'authority_invalid');
        self::need(time() >= $authority['started_at'], 'clock_invalid');
        if ($mutating) self::need(time() < $authority['deadline_at'] - 1200
            && !file_exists(self::ROOT . '/private/import-stopped') && !is_link(self::ROOT . '/private/import-stopped'), 'expired_or_stopped');
        $rawCredentials = self::read('private/credentials', 0600, 65536);
        self::need(hash_equals($authority['credentials_sha256'], hash('sha256', $rawCredentials)), 'credentials_changed');
        $credentials = self::material(json_decode($rawCredentials, true, 16, JSON_THROW_ON_ERROR));
        $publication = json_decode(self::read('private/runtime-publication.json', 0600, 16777216), true, 32, JSON_THROW_ON_ERROR);
        self::need($publication['archive_sha256'] === $application['bundle_sha256'], 'publication_changed');
        $manifest = $publication['manifest'];
        self::need($manifest['execution_authorized'] === false && $manifest['credentials_included'] === false, 'armed_package_refused');
        foreach (['node/kelinode', 'node/release.tar.gz', 'node/release.json'] as $name) {
            $pin = $manifest['files'][$name] ?? null;
            self::need(is_array($pin) && $pin['mode'] === ($name === 'node/kelinode' ? 0755 : 0644), 'release_pin_missing');
            $raw = self::read($name, $pin['mode'], 67108864);
            self::need(hash_equals($pin['sha256'], hash('sha256', $raw)) && strlen($raw) === $pin['bytes'], 'release_changed');
            if ($name === 'node/release.json') $release = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        }
        self::need($release === ['binary_sha256' => $manifest['files']['node/kelinode']['sha256'],
            'component' => 'kelinode-rs', 'platform' => 'linux-x86_64',
            'sha256' => $manifest['files']['node/release.tar.gz']['sha256'], 'version' => 'v0.1.387'], 'release_changed');
        return ['credentials' => $credentials, 'release' => $release,
            'started_at' => $authority['started_at'], 'deadline_at' => $authority['deadline_at'],
            'identity' => ['authority_sha256' => hash('sha256', $rawAuthority), 'bundle_sha256' => $application['bundle_sha256']]];
    }

    private function persist(string $key, array $value): void
    {
        ($this->save)($key, $value);
        self::need(MemberProviderBindings::digest(($this->load)($key) ?? []) === MemberProviderBindings::digest($value), 'journal_unconfirmed');
    }

    public function import(): array
    {
        $c = $this->context(true); ($this->guard)('import');
        if (($this->load)('intent') !== null) {
            self::need(($this->load)('intent') === $c['identity'], 'authority_changed');
            return $this->readback();
        }
        foreach (self::EMPTY as $table) self::need(!DB::table($table)->exists(), 'fresh_database_required');
        self::need(Storage::disk('local')->allFiles('kelinode-rs/releases') === [], 'fresh_storage_required');
        $this->persist('intent', $c['identity']);
        try {
            $scope = DB::transaction(function () use ($c): array {
                foreach (self::EMPTY as $table) self::need(!DB::table($table)->exists(), 'fresh_database_required');
                ($this->guard)('import-admin');
                $admin = User::query()->create(['email' => 'r35-isolated@example.invalid',
                    'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
                    'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)), 'is_admin' => true]);
                ($this->guard)('import-account');
                $group = app(MachineCloudGroupService::class)->save(['name' => MemberCliAuthority::RUN]);
                $account = app(MachineCloudService::class)->saveAccount(['name' => MemberCliAuthority::RUN, 'provider' => 'linode',
                    'group_id' => $group->id, 'api_token' => $c['credentials']['linode']['api_token'],
                    'provision_limits' => ['max_instances' => count($c['credentials']['baseline_ids']) + 4, 'max_parallel' => 1, 'daily_limit' => 5]]);
                ($this->guard)('import-dns');
                $dns = app(MachineDnsService::class)->saveCredential(['name' => MemberCliAuthority::RUN, 'provider' => 'aliyun']
                    + $c['credentials']['aliyun'], $admin->id);
                ($this->guard)('import-release');
                $release = app(MachineReleaseDistributionService::class)->storeLocalRelease('kelinode-rs', 'v0.1.387', 'linux-x86_64',
                    new UploadedFile(self::ROOT . '/node/release.json', 'manifest.json', 'application/json', null, true),
                    new UploadedFile(self::ROOT . '/node/release.tar.gz', 'release.tar.gz', 'application/gzip', null, true), false);
                // The normal uploader defaults the first release. This isolated import
                // binds an explicit release ID and must leave even its local default unset.
                $release->forceFill(['is_default' => false])->save();
                $this->publishedFiles($release, true);
                ($this->guard)('import-complete');
                $this->context(true);
                return ['run_id' => MemberCliAuthority::RUN, 'admin_id' => $admin->id, 'account_id' => $account->id,
                    'account_revision' => $account->revision, 'group_id' => $group->id,
                    'dns_credential_id' => $dns['id'], 'dns_credential_revision' => $dns['revision'],
                    'release_id' => $release->id, 'binary_sha256' => $release->binary_sha256,
                    'version' => $release->version, 'baseline_ids' => $c['credentials']['baseline_ids']];
            });
            $this->persist('result', ['identity' => $c['identity'], 'scope' => $scope]);
            return $this->readback();
        } catch (\Throwable $error) {
            try {
                if (($this->load)('first-error') === null) $this->persist('first-error', [
                    'class' => (new \ReflectionClass($error))->getShortName(), 'file' => basename($error->getFile()), 'line' => $error->getLine()]);
            } catch (\Throwable) { }
            throw $error;
        }
    }

    public function readback(): array
    {
        $c = $this->context(false); ($this->guard)('readback');
        $result = ($this->load)('result');
        self::need(($this->load)('intent') === $c['identity'] && is_array($result)
            && ($result['identity'] ?? null) === $c['identity'], 'result_unconfirmed');
        $s = $result['scope'];
        $account = MachineCloudAccount::query()->findOrFail($s['account_id']);
        $dns = MachineDnsCredential::query()->findOrFail($s['dns_credential_id']);
        $release = ServerMachineRelease::query()->findOrFail($s['release_id']);
        self::need($s['baseline_ids'] === $c['credentials']['baseline_ids']
            && $s['version'] === $c['release']['version'] && $s['binary_sha256'] === $c['release']['binary_sha256']
            && User::query()->whereKey($s['admin_id'])->where('is_admin', true)->exists()
            && MachineCloudAccount::query()->count() === 1 && $account->provider === 'linode'
            && $account->revision === $s['account_revision'] && $account->group_id === $s['group_id']
            && $account->credentials === $c['credentials']['linode']
            && app(\App\Services\ServerMachine\Cloud\MachineProvisionLimits::class)->settings($account)
                === ['max_instances' => count($s['baseline_ids']) + 4, 'max_parallel' => 1, 'daily_limit' => 5]
            && $dns->provider === 'aliyun' && $dns->revision === $s['dns_credential_revision']
            && $dns->credentials === $c['credentials']['aliyun']
            && $release->component === 'kelinode-rs' && $release->platform === 'linux-x86_64'
            && $release->version === $s['version'] && $release->binary_sha256 === $s['binary_sha256']
            && $release->sha256 === $c['release']['sha256'] && $release->status === 'active'
            && !$release->is_default && !ServerMachineRelease::query()->where('is_default', true)->exists(), 'data_changed');
        $this->publishedFiles($release, false);
        return $s;
    }

    public function authorization(bool $mutating): array
    {
        $c = $this->context($mutating);
        return ['scope' => $this->readback(), 'identity' => $c['identity'],
            'started_at' => $c['started_at'], 'deadline_at' => $c['deadline_at']];
    }

    private function publishedFiles(ServerMachineRelease $release, bool $prepare): void
    {
        $publisher = app(MachineReleaseDistributionService::class);
        $root = rtrim(Storage::disk('local')->path(''), '/');
        self::need($root === self::ROOT . '/app/storage/app' && realpath($root) === $root, 'storage_changed');
        foreach (['archive_path' => 'node/release.tar.gz', 'manifest_path' => 'node/release.json'] as $key => $name) {
            $expected = $key === 'archive_path' ? $publisher->archivePath('kelinode-rs', 'v0.1.387', 'linux-x86_64')
                : $publisher->manifestPath('kelinode-rs', 'v0.1.387', 'linux-x86_64');
            self::need($release->{$key} === $expected, 'published_path_changed');
            $path = Storage::disk('local')->path($release->{$key});
            for ($directory = dirname($path); $directory !== $root; $directory = dirname($directory)) {
                $stat = @lstat($directory);
                self::need(str_starts_with($directory, $root . '/') && realpath($directory) === $directory
                    && is_dir($directory) && $stat['uid'] === 0 && ($stat['mode'] & 0022) === 0, 'published_directory_changed');
                if ($prepare) self::need(chmod($directory, 0755), 'published_permissions');
                clearstatcache(true, $directory);
                self::need((fileperms($directory) & 0777) === 0755, 'published_permissions');
            }
            $stat = @lstat($path);
            self::need($stat && realpath($path) === $path && is_file($path) && $stat['uid'] === 0 && $stat['nlink'] === 1
                && hash_file('sha256', $path) === hash_file('sha256', self::ROOT . '/' . $name), 'published_release_changed');
            if ($prepare) self::need(chmod($path, 0644), 'published_permissions');
            clearstatcache(true, $path);
            self::need((fileperms($path) & 0777) === 0644, 'published_permissions');
        }
    }
}

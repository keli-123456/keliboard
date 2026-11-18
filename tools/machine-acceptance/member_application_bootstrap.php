<?php
declare(strict_types=1);

namespace Keli\Acceptance;

/** Full application bootstrap after checking isolation, before providers can connect. */
final class MemberApplicationBootstrap
{
    public const ROOT = '/opt/keli-isolated';

    private static function need(bool $value, string $reason): void
    {
        if (!$value) throw new \RuntimeException('member_application_' . $reason);
    }

    public static function validateInstallRequest(array $request, int $now): void
    {
        $keys = ['schema', 'run_id', 'bundle_sha256', 'started_at', 'deadline_at', 'request_id', 'operation'];
        self::need(count($request) === count($keys) && !array_diff(array_keys($request), $keys)
            && ($request['schema'] ?? null) === 1 && ($request['run_id'] ?? null) === MemberCliAuthority::RUN
            && in_array($request['operation'] ?? null, ['initialize', 'readback', 'service-smoke'], true)
            && is_int($request['started_at'] ?? null) && $request['started_at'] > 0
            && is_int($request['deadline_at'] ?? null)
            && $request['deadline_at'] - $request['started_at'] > 1200
            && $request['deadline_at'] - $request['started_at'] <= 7200, 'install_request_invalid');
        foreach (['bundle_sha256' => 64, 'request_id' => 32] as $key => $length)
            self::need(is_string($request[$key] ?? null)
                && preg_match('/^[a-f0-9]{' . $length . '}$/D', $request[$key]) === 1, 'install_request_invalid');
        self::need($now >= $request['started_at'], 'install_clock_invalid');
        if ($request['operation'] === 'initialize')
            self::need($now < $request['deadline_at'] - 1200, 'install_window_expired');
    }

    public static function install(array $request): array
    {
        self::need(PHP_SAPI === 'cli' && function_exists('posix_geteuid') && function_exists('posix_getegid'), 'installer_cli_required');
        self::validateInstallRequest($request, time());
        $pin = self::receipt();
        self::need(hash_equals($pin['bundle_sha256'], $request['bundle_sha256']), 'install_bundle_changed');
        $smoke = $request['operation'] === 'service-smoke';
        self::need($smoke ? posix_geteuid() === $pin['uid'] && posix_getegid() === $pin['gid']
            : posix_geteuid() === 0, 'installer_identity_changed');
        if ($request['operation'] === 'initialize') {
            foreach (['private/import-stopped', 'member-setup-stop.json', 'private/member-runtime-scope.json'] as $name)
                self::need(!file_exists(self::ROOT . '/' . $name) && !is_link(self::ROOT . '/' . $name), 'installation_stopped');
        }
        if (!$smoke) {
            $result = MemberBootstrapController::withinInstallation(static function () use ($request): array {
                $app = self::boot();
                return $request['operation'] === 'initialize' ? self::initialize($app) : self::initialized();
            }, $request['operation'] === 'initialize');
        } else {
            $app = self::boot();
            foreach (['app/artisan', 'app/bootstrap/app.php', 'app/bootstrap/cache', 'runtime'] as $name)
                self::need(!is_writable(self::ROOT . '/' . $name), 'service_executable_writable');
            foreach (['storage/logs', 'storage/app/public', 'storage/framework'] as $name)
                self::need(is_writable(self::ROOT . '/app/' . $name), 'service_state_not_writable');
            self::need(count(\Illuminate\Support\Facades\DB::select('SELECT 1 AS ready')) === 1, 'service_database_unavailable');
            foreach (['default', 'cache'] as $connection) {
                $reply = \Illuminate\Support\Facades\Redis::connection($connection)->ping();
                self::need($reply === true || strtoupper((string) $reply) === 'PONG' || (string) $reply === '+PONG', 'service_cache_unavailable');
            }
            $result = ['ready' => true, 'uid' => posix_geteuid(), 'gid' => posix_getegid(),
                'kernel' => get_class($app->make(\Illuminate\Contracts\Http\Kernel::class)),
                'database_and_cache_reachable' => true, 'executable_paths_read_only' => true];
        }
        return $request + ['ok' => true, 'result' => $result];
    }

    public static function receipt(): array
    {
        self::need(PHP_OS_FAMILY === 'Linux' && in_array(PHP_SAPI, ['cli', 'cli-server', 'fpm-fcgi'], true), 'linux_required');
        foreach (['APP_BASE_PATH', 'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE',
                  'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'LARAVEL_STORAGE_PATH'] as $key)
            self::need(getenv($key) === false && !isset($_ENV[$key]) && !isset($_SERVER[$key]), 'path_override_refused');
        self::need(getenv('APP_ENV') === false || getenv('APP_ENV') === 'acceptance', 'environment_override_refused');
        foreach ([self::ROOT, self::ROOT . '/app', self::ROOT . '/runtime'] as $directory) {
            $stat = @lstat($directory);
            self::need($stat && realpath($directory) === $directory && is_dir($directory)
                && $stat['uid'] === 0 && ($stat['mode'] & 0022) === 0, 'directory_changed');
        }
        $path = self::ROOT . '/application.json';
        $stat = @lstat($path);
        self::need($stat && realpath($path) === $path && is_file($path) && $stat['uid'] === 0
            && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0644 && $stat['size'] <= 16384, 'receipt_required');
        $pin = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        self::need(is_array($pin) && ($pin['schema'] ?? null) === 1
            && ($pin['run_id'] ?? null) === MemberCliAuthority::RUN
            && is_int($pin['uid'] ?? null) && $pin['uid'] > 0
            && is_int($pin['gid'] ?? null) && $pin['gid'] > 0, 'receipt_invalid');
        foreach (['bundle_sha256', 'environment_sha256', 'machine_id_sha256', 'app_key_sha256'] as $key)
            self::need(is_string($pin[$key] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $pin[$key]) === 1, 'receipt_invalid');
        self::need(hash_equals($pin['machine_id_sha256'], (string) @hash_file('sha256', '/etc/machine-id')), 'machine_changed');
        $path = self::ROOT . '/app/.env'; $stat = @lstat($path);
        self::need($stat && realpath($path) === $path && is_file($path) && $stat['nlink'] === 1
            && $stat['uid'] === 0 && $stat['gid'] === $pin['gid'] && ($stat['mode'] & 0777) === 0640
            && $stat['size'] <= 16384 && hash_equals($pin['environment_sha256'], (string) hash_file('sha256', $path)),
            'environment_changed');
        self::need(!file_exists(self::ROOT . '/app/bootstrap/cache/config.php'), 'cached_configuration_refused');
        $cache = self::ROOT . '/app/bootstrap/cache'; $stat = @lstat($cache);
        self::need($stat && realpath($cache) === $cache && is_dir($cache)
            && $stat['uid'] === 0 && ($stat['mode'] & 0022) === 0, 'executable_cache_permissions');
        foreach (scandir($cache) as $name) {
            if (in_array($name, ['.', '..'], true)) continue;
            self::need(in_array($name, ['.gitignore', 'packages.php', 'services.php'], true), 'unexpected_executable_cache');
            $stat = @lstat($cache . '/' . $name);
            self::need($stat && realpath($cache . '/' . $name) === $cache . '/' . $name
                && is_file($cache . '/' . $name) && $stat['uid'] === 0 && $stat['nlink'] === 1
                && ($stat['mode'] & 0022) === 0, 'executable_cache_permissions');
        }
        return $pin;
    }

    public static function assertConfiguration(array $pin): void
    {
        $facts = MemberCliAuthority::environmentFacts(); $s = $pin['settings'] ?? [];
        self::need(($facts['root'] ?? null) === self::ROOT . '/app' && ($facts['env'] ?? null) === 'acceptance'
            && ($facts['database_driver'] ?? null) === 'mysql' && ($facts['database_host'] ?? null) === '127.0.0.1'
            && preg_match('/^keli_acceptance_[a-z0-9_]{3,60}$/D', $s['database'] ?? '') === 1
            && ($facts['database'] ?? null) === $s['database']
            && empty($facts['database_url']) && empty($facts['database_socket'])
            && empty($facts['database_read']) && empty($facts['database_write'])
            && ($facts['cache'] ?? null) === 'redis' && ($facts['cache_prefix'] ?? null) === MemberCliAuthority::RUN . ':'
            && ($facts['cache_connection'] ?? null) === 'cache' && empty($facts['redis_clusters'])
            && in_array($facts['cache_lock_connection'] ?? null, [null, 'default', 'cache'], true), 'isolation_required');
        self::need(preg_match('/^keli_[a-z0-9_]{3,25}$/D', $s['username'] ?? '') === 1
            && config('database.connections.mysql.username') === $s['username']
            && is_int($s['mysql_port'] ?? null) && $s['mysql_port'] >= 1024 && $s['mysql_port'] <= 65535
            && (int) config('database.connections.mysql.port') === $s['mysql_port'], 'database_changed');
        foreach (['redis_default' => 0, 'redis_cache' => 1] as $key => $database) {
            $r = $facts[$key] ?? [];
            self::need(($r['host'] ?? null) === '127.0.0.1' && empty($r['url']) && empty($r['path'])
                && empty($r['read']) && empty($r['write']) && (int) ($r['database'] ?? -1) === $database
                && is_int($s['redis_port'] ?? null) && $s['redis_port'] >= 1024 && $s['redis_port'] <= 65535
                && (int) ($r['port'] ?? 0) === $s['redis_port'], 'cache_changed');
        }
        self::need(config('database.redis.options.prefix') === MemberCliAuthority::RUN . ':'
            && config('app.debug') === false && hash_equals($pin['app_key_sha256'], hash('sha256', (string) config('app.key')))
            && config('app.url') === 'https://control-' . MemberCliAuthority::RUN . '.dnscloudcloud.top', 'application_changed');
        foreach (MemberCliAuthority::closed() as $key => $value)
            self::need(config($key) === $value, 'automatic_gate_changed');
        self::need(config('queue.default') === 'redis'
            && config('queue.connections.redis.connection') === 'default'
            && config('queue.connections.redis.queue') === 'r35_callbacks', 'queue_changed');
    }

    public static function boot(): \Illuminate\Foundation\Application
    {
        $pin = self::receipt();
        require_once self::ROOT . '/app/vendor/autoload.php';
        $app = require self::ROOT . '/app/bootstrap/app.php';
        self::need(realpath($app->basePath()) === self::ROOT . '/app', 'base_path_changed');
        foreach ([\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
                  \Illuminate\Foundation\Bootstrap\LoadConfiguration::class] as $bootstrapper)
            $app->make($bootstrapper)->bootstrap($app);
        self::assertConfiguration($pin);
        // Root prewarms only provider/package caches; the service user cannot
        // modify PHP later loaded by a privileged CLI process.
        $mask = umask(0022);
        try { $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); }
        finally { umask($mask); }
        self::assertConfiguration($pin);
        return $app;
    }

    private static function installationIdentity(): array
    {
        self::need(PHP_SAPI === 'cli' && function_exists('posix_geteuid') && posix_geteuid() === 0,
            'installer_root_required');
        $pin = self::receipt();
        self::assertConfiguration($pin);
        $directory = self::ROOT . '/private'; $stat = @lstat($directory);
        self::need($stat && realpath($directory) === $directory && is_dir($directory)
            && $stat['uid'] === 0 && ($stat['mode'] & 0777) === 0700, 'private_directory_required');
        return ['schema' => 1, 'run_id' => $pin['run_id'], 'bundle_sha256' => $pin['bundle_sha256'],
            'environment_sha256' => $pin['environment_sha256'], 'machine_id_sha256' => $pin['machine_id_sha256']];
    }

    private static function installationFile(string $name, ?array $value = null): array
    {
        self::need(in_array($name, ['intent', 'result', 'first-error'], true), 'installation_file_invalid');
        $path = self::ROOT . '/private/application-migration-' . $name . '.json';
        if ($value !== null) {
            // O_EXCL retains an uncertain first dispatch; it cannot be replayed.
            self::need(!file_exists($path) && !is_link($path), 'initialization_intent_exists');
            $mask = umask(0077);
            try { $stream = fopen($path, 'x+b'); } finally { umask($mask); }
            self::need(is_resource($stream), 'installation_write_failed');
            try {
                self::need(chmod($path, 0600), 'installation_file_permissions');
                $raw = json_encode($value, JSON_THROW_ON_ERROR);
                self::need(strlen($raw) <= 16384 && fwrite($stream, $raw) === strlen($raw)
                    && fflush($stream) && fsync($stream), 'installation_write_failed');
            } finally { fclose($stream); }
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        self::need($stat !== false, 'installation_readback_required');
        self::need(realpath($path) === $path && is_file($path), 'installation_file_path_changed');
        self::need($stat['uid'] === 0 && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0600,
            'installation_file_permissions');
        self::need($stat['size'] <= 16384, 'installation_file_size');
        $observed = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        self::need(is_array($observed) && ($value === null || $observed === $value), 'installation_readback_changed');
        return $observed;
    }

    private static function migrationReceipt(array $identity): array
    {
        $migrations = \Illuminate\Support\Facades\DB::table('migrations')->orderBy('id')
            ->get(['migration', 'batch'])->map(static fn ($row) => (array) $row)->all();
        self::need(count($migrations) > 0, 'migrations_missing');
        return $identity + ['migrated' => true, 'migration_count' => count($migrations),
            'migrations_sha256' => hash('sha256', json_encode($migrations, JSON_THROW_ON_ERROR)),
            'tables' => count(\Illuminate\Support\Facades\DB::select('SHOW TABLES'))];
    }

    public static function initialize(\Illuminate\Foundation\Application $app): array
    {
        $identity = self::installationIdentity();
        self::installationFile('intent', $identity);
        try {
            self::need(\Illuminate\Support\Facades\DB::select('SHOW TABLES') === [], 'database_not_empty');
            $output = new \Symfony\Component\Console\Output\BufferedOutput();
            self::need($app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',
                ['--force' => true, '--no-interaction' => true], $output) === 0, 'migration_failed');
            $result = self::migrationReceipt($identity);
            self::installationFile('result', $result);
            return $result;
        } catch (\Throwable $error) {
            // Record only code locations, never SQL, connection strings or messages.
            try {
                self::installationFile('first-error', ['class' => (new \ReflectionClass($error))->getShortName(),
                    'file' => basename($error->getFile()), 'line' => $error->getLine()]);
            } catch (\Throwable) { /* Preserve the original failure even if recording fails. */ }
            throw $error;
        }
    }

    public static function initialized(): array
    {
        $identity = self::installationIdentity();
        self::need(self::installationFile('intent') === $identity, 'installation_identity_changed');
        $result = self::installationFile('result');
        self::need($result === self::migrationReceipt($identity), 'installation_schema_changed');
        return $result;
    }
}

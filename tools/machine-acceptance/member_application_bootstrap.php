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

    public static function receipt(): array
    {
        self::need(PHP_OS_FAMILY === 'Linux' && in_array(PHP_SAPI, ['cli', 'cli-server'], true), 'linux_required');
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
}

<?php

declare(strict_types=1);

const TRIAL_COMMAND = 'machines:work-operator-failover';
const TRIAL_SOCKET = '/trial-sock/redis.sock';
const TRIAL_FUNCTIONS = ['pcntl_exec', 'pcntl_async_signals', 'pcntl_signal', 'pcntl_alarm',
    'posix_geteuid', 'posix_getegid', 'posix_kill'];

final class TrialGateFailure extends RuntimeException
{
    public function __construct(string $reason, public readonly ?string $check = null) { parent::__construct($reason); }
}

function requireTrial(bool $yes, string $reason, ?string $check = null): void
{
    if (!$yes) throw new TrialGateFailure($reason, $check);
}

function trialCapabilities(callable $available): void
{
    foreach (TRIAL_FUNCTIONS as $name) requireTrial($available($name), 'required_function_unavailable', $name);
}

function trialEvent(string $kind, array $fields): void
{
    echo 'KELI_TRIAL ' . json_encode(['kind' => $kind] + $fields, JSON_THROW_ON_ERROR) . PHP_EOL;
    flush();
}

function makeTrialConfig(array $config): array
{
    $config['app']['env'] = 'testing';
    $config['app']['debug'] = false;
    $config['app']['key'] = 'base64:' . base64_encode(random_bytes(32));
    $config['app']['url'] = 'http://localhost';
    $socket = ['host' => TRIAL_SOCKET, 'port' => 0, 'database' => 0, 'username' => null, 'password' => null,
        'read_timeout' => 2, 'timeout' => 2];
    $config['database'] = ['default' => 'sqlite', 'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'journal_mode' => 'memory']],
        'redis' => ['client' => 'phpredis', 'options' => ['prefix' => 'keli-empty-trial:'], 'default' => $socket, 'operator_trial' => $socket]];
    $config['cache'] = ['default' => 'redis', 'prefix' => 'trial-cache:', 'stores' => [
        'redis' => ['driver' => 'redis', 'connection' => 'operator_trial', 'lock_connection' => 'operator_trial']]];
    $config['queue'] = ['default' => 'redis_failover', 'connections' => ['redis_failover' => [
        'driver' => 'redis', 'connection' => 'operator_trial', 'queue' => 'machine_failover_operator', 'retry_after' => 90,
        'block_for' => null, 'after_commit' => false]], 'failed' => ['driver' => 'null']];
    foreach (['runner_enabled', 'admission_enabled', 'allow_replacement', 'allow_rotation', 'allow_candidate_retry', 'observe_enabled', 'preview_enabled'] as $k)
        $config['machine_failover'][$k] = false;
    $config['machine_failover']['operator_enabled'] = true;
    $config['machine_provision']['allow_live'] = false;
    foreach (['allow_readiness', 'allow_verification', 'allow_dns'] as $k) $config['machine_acceptance'][$k] = false;
    $config['mail']['default'] = 'log';
    $config['logging']['default'] = 'stderr';
    return $config;
}

function validateTrialConfig(array $config): void
{
    $d = $config['database'] ?? [];
    requireTrial(($d['default'] ?? null) === 'sqlite' && array_keys($d['connections'] ?? []) === ['sqlite']
        && ($d['connections']['sqlite']['database'] ?? null) === ':memory:', 'isolated_database_mismatch');
    foreach (['default', 'operator_trial'] as $name) {
        $r = $d['redis'][$name] ?? [];
        requireTrial(($r['host'] ?? null) === TRIAL_SOCKET && ($r['port'] ?? null) === 0 && ($r['database'] ?? null) === 0
            && empty($r['url']) && empty($r['password']), 'isolated_redis_mismatch');
    }
    $q = $config['queue'] ?? [];
    $c = $config['cache'] ?? [];
    requireTrial(($q['default'] ?? null) === 'redis_failover' && array_keys($q['connections'] ?? []) === ['redis_failover']
        && ($q['connections']['redis_failover']['connection'] ?? null) === 'operator_trial'
        && ($q['connections']['redis_failover']['queue'] ?? null) === 'machine_failover_operator'
        && ($c['default'] ?? null) === 'redis' && ($c['stores']['redis']['connection'] ?? null) === 'operator_trial',
        'isolated_queue_mismatch');
}

function trialFailure(string $stage, Throwable $error, int $exit): array
{
    return ['stage' => $stage, 'reason' => $error instanceof TrialGateFailure ? $error->getMessage() : 'exception',
        'type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(),
        'exit' => $exit, 'check' => $error instanceof TrialGateFailure ? $error->check : null];
}

function buildTrialCache(Illuminate\Foundation\Application $app, string $path): void
{
    requireTrial(!file_exists($path) && !is_link($path), 'cache_path_exists');
    (new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
    $config = makeTrialConfig($app['config']->all());
    validateTrialConfig($config);
    $out = "<?php return " . var_export($config, true) . ";\n";
    $f = @fopen($path, 'xb');
    requireTrial($f !== false, 'cache_create_failed');
    try {
        requireTrial(fwrite($f, $out) === strlen($out) && fflush($f), 'cache_write_failed');
    } finally { fclose($f); }
    requireTrial(chmod($path, 0400), 'cache_permission_failed');
}

function bootTrialCommand(Illuminate\Foundation\Application $app): Illuminate\Contracts\Console\Kernel
{
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    validateTrialConfig($app['config']->all());
    $cmd = $kernel->all()[TRIAL_COMMAND] ?? null;
    requireTrial($cmd instanceof App\Console\Commands\WorkMachineFailoverOperator, 'worker_command_not_registered');
    $source = (new ReflectionClass($cmd))->getFileName();
    requireTrial($source === realpath($app->basePath('app/Console/Commands/WorkMachineFailoverOperator.php')),
        'worker_command_source_mismatch');
    return $kernel;
}

function runTrialBootstrap(array $args): int
{
    umask(0077);
    $stage = 'runtime';
    $first = false;
    register_shutdown_function(function () use (&$stage, &$first) {
        $last = error_get_last();
        if (!$first && $last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $first = true;
            trialEvent('first_error', ['stage' => $stage, 'reason' => 'php_fatal',
                'file' => basename($last['file']), 'line' => $last['line']]);
        }
    });
    try {
        trialEvent('stage', ['stage' => $stage]);
        trialCapabilities('function_exists');
        requireTrial(posix_geteuid() === 65082 && posix_getegid() === 65082, 'wrong_worker_identity');
        requireTrial(count($args) === 2 && in_array($args[1], ['prepare', 'consume'], true)
            && __DIR__ === '/runtime' && !file_exists('/runtime/.env'), 'wrong_worker_scope');
        requireTrial(chdir('/runtime'), 'cwd_unavailable');
        requireTrial(getenv('APP_CONFIG_CACHE') === 'bootstrap/cache/operator-config.php', 'wrong_cache_path');
        $stage = 'autoload';
        trialEvent('stage', ['stage' => $stage]);
        require '/runtime/vendor/autoload.php';
        $app = require '/runtime/bootstrap/app.php';
        $cache = $app->getCachedConfigPath();
        requireTrial($cache === '/runtime/bootstrap/cache/operator-config.php', 'wrong_cache_path');
        if ($args[1] === 'prepare') {
            $stage = 'config_cache';
            trialEvent('stage', ['stage' => $stage]);
            buildTrialCache($app, $cache);
            $stage = 'child_exec';
            trialEvent('stage', ['stage' => $stage]);
            pcntl_exec(PHP_BINARY, ['-d', 'memory_limit=160M', __FILE__, 'consume'], getenv());
            throw new TrialGateFailure('worker_exec_failed');
        }
        $stage = 'config_readback';
        trialEvent('stage', ['stage' => $stage]);
        requireTrial(is_readable($cache) && !is_link($cache) && (fileperms($cache) & 0777) === 0400, 'cached_file_unverified');
        validateTrialConfig(require $cache);
        $stage = 'artisan_bootstrap';
        trialEvent('stage', ['stage' => $stage]);
        $kernel = bootTrialCommand($app);
        $stage = 'operator_gate';
        trialEvent('stage', ['stage' => $stage]);
        App\Services\ServerMachine\Cloud\MachineFailoverOperatorPermit::requireEnabled();
        $stage = 'redis_socket';
        trialEvent('stage', ['stage' => $stage]);
        $ping = $app->make('redis')->connection('operator_trial')->ping();
        requireTrial($ping === true || strtoupper((string) $ping) === 'PONG', 'isolated_redis_ping_failed');
        $stage = 'queue_command';
        trialEvent('stage', ['stage' => $stage]);
        $result = $kernel->call(TRIAL_COMMAND, [], new Symfony\Component\Console\Output\NullOutput());
        if ($result !== 0) {
            $first = true;
            trialEvent('first_error', ['stage' => $stage, 'reason' => 'queue_command_nonzero', 'exit' => $result]);
        }
        trialEvent('command_exit', ['stage' => $stage, 'exit' => $result]);
        return $result;
    } catch (Throwable $error) {
        $first = true;
        trialEvent('first_error', trialFailure($stage, $error, 74));
        return 74;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(runTrialBootstrap($argv));
}

<?php
declare(strict_types=1);

// Installed only on a newly prepared isolated controller; never an application route.
// A frozen bundle ships no member-runtime-scope.json and therefore cannot execute.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$request = [];
try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') throw new RuntimeException('isolated_cli_required');
    $root = '/opt/keli-isolated';
    $read = static function (string $path, int $max): array {
        $stat = lstat($path);
        if (!$stat || !is_file($path) || is_link($path) || $stat['nlink'] !== 1 || ($stat['mode'] & 0022)
            || $stat['size'] > $max) throw new RuntimeException('isolated_file_rejected');
        $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('isolated_json_required');
        return $value;
    };
    $scope = $read($root . '/private/member-runtime-scope.json', 65536);
    if (($scope['execution_authorized'] ?? null) !== true || ($scope['isolated'] ?? null) !== true
        || ($scope['run_id'] ?? null) !== 'keli-accept-20261116-r35-f4940b41'
        || !is_int($scope['started_at'] ?? null) || !is_int($scope['deadline_at'] ?? null)
        || $scope['started_at'] > time() || $scope['deadline_at'] <= $scope['started_at']
        || $scope['deadline_at'] - $scope['started_at'] > 7200
        || !hash_equals($scope['controller_machine_id_sha256'] ?? '', hash_file('sha256', '/etc/machine-id')))
        throw new RuntimeException('isolated_authority_missing');
    foreach (['member_transport_entry.php', 'member_controller_bridge.php', 'member_source_registry.php'] as $name)
        if (!isset($scope['runtime_sha256'][$name]) || is_link(__DIR__ . '/' . $name)
            || !hash_equals($scope['runtime_sha256'][$name], hash_file('sha256', __DIR__ . '/' . $name)))
            throw new RuntimeException('isolated_runtime_changed');
    require $root . '/app/vendor/autoload.php';
    $app = require $root . '/app/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $guard = static function (string $operation, string $role) use ($scope): void {
        $database = app('db')->connection();
        if ($database->getDriverName() !== 'mysql' || $database->getDatabaseName() !== $scope['database']
            || !preg_match('/^keli_acceptance_[a-z0-9_]{3,60}$/D', $scope['database'])
            || !in_array($database->getConfig('host'), ['127.0.0.1', 'localhost'], true)
            || config('app.env') !== 'acceptance'
            || config('cache.default') !== 'redis' || config('cache.prefix') !== $scope['run_id'] . ':'
            || !in_array(config('database.redis.default.host'), ['127.0.0.1', 'localhost'], true)
            || !in_array(config('database.redis.cache.host'), ['127.0.0.1', 'localhost'], true)
            || config('database.redis.default.url') || config('database.redis.cache.url')
            || config('machine_failover.runner_enabled') !== false || config('machine_failover.allow_rotation') !== false)
            throw new RuntimeException('isolated_database_required');
        $cleanup = in_array($operation, ['task-inspect', 'task-delete'], true);
        if (!$cleanup && (time() < $scope['started_at'] || time() >= $scope['deadline_at'] - 1200))
            throw new RuntimeException('isolated_deadline');
        if ($operation === 'task-delete' && ($scope['final_cleanup_requested'] ?? null) !== true)
            throw new RuntimeException('isolated_final_cleanup_required');
        // The fixed endpoint never enables application execution/queue switches.
    };
    $journal = $root . '/private/member-journal';
    if (!is_dir($journal) || is_link($journal) || (fileperms($journal) & 0077)) throw new RuntimeException('isolated_journal_required');
    $load = static function (string $key) use ($journal, $read): ?array {
        if (!preg_match('/^(source-[ab]|delete-(source-[ab]|candidate-a[12]))$/D', $key)) throw new RuntimeException('isolated_journal_key');
        return file_exists($journal . '/' . $key . '.json') ? $read($journal . '/' . $key . '.json', 16384) : null;
    };
    $save = static function (string $key, array $value) use ($journal, $load): void {
        $previous = $load($key);
        if (str_starts_with($key, 'delete-') && $previous !== null) {
            if ($previous !== $value) throw new RuntimeException('isolated_intent_changed');
            return;
        }
        $path = $journal . '/' . $key . '.json';
        $temp = $journal . '/.' . bin2hex(random_bytes(12));
        $stream = fopen($temp, 'x+b');
        if (!$stream) throw new RuntimeException('isolated_journal_unavailable');
        try {
            chmod($temp, 0600);
            $data = json_encode($value, JSON_THROW_ON_ERROR);
            if (fwrite($stream, $data) !== strlen($data) || !fflush($stream) || !fsync($stream))
                throw new RuntimeException('isolated_journal_unavailable');
            fclose($stream); $stream = null;
            if (!rename($temp, $path)) throw new RuntimeException('isolated_journal_unavailable');
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (file_exists($temp)) unlink($temp);
        }
    };
    $lock = fopen($journal . '/rpc.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('isolated_rpc_busy');
    chmod($journal . '/rpc.lock', 0600);
    $raw = stream_get_contents(STDIN, 8193);
    if (strlen($raw) > 8192) throw new RuntimeException('isolated_request_too_large');
    $request = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new RuntimeException('isolated_request_invalid');
    require __DIR__ . '/member_source_registry.php';
    require __DIR__ . '/member_controller_bridge.php';
    $registry = new Keli\Acceptance\MemberSourceRegistry($scope, $guard, $load, $save, static fn (): int => time());
    $bridge = new Keli\Acceptance\MemberControllerBridge($scope, $registry, $guard, $load, $save);
    echo json_encode($bridge->dispatch($request), JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    // Only bounded structured diagnostics leave this machine, never messages, paths or traces.
    $file = basename($error->getFile());
    $allowed = ['member_transport_entry.php', 'member_controller_bridge.php', 'member_source_registry.php'];
    echo json_encode(['schema' => 1, 'ok' => false, 'reason' => 'isolated_operation_rejected',
        'diagnostic' => ['class' => (new ReflectionClass($error))->getShortName(),
            'file' => in_array($file, $allowed, true) ? $file : 'application',
            'line' => in_array($file, $allowed, true) ? $error->getLine() : null]]), "\n";
    exit(1);
}

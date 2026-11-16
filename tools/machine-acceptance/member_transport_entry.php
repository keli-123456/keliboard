<?php
declare(strict_types=1);

// Fixed isolated CLI only. An unarmed package contains no grant and cannot run.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$runtime = ['member_transport_entry.php', 'member_cli_authority.php', 'member_round_bridge.php',
    'member_retry_driver.php', 'member_controller_bridge.php', 'member_source_registry.php'];
try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') throw new RuntimeException('isolated_cli_required');
    $root = '/opt/keli-isolated';
    foreach ([$root, $root . '/private', $root . '/runtime', $root . '/app'] as $directory)
        if (realpath($directory) !== $directory || !is_dir($directory) || (fileperms($directory) & 0022))
            throw new RuntimeException('isolated_directory_required');
    $read = static function (string $path, int $max): array {
        $stat = @lstat($path);
        if (!$stat || realpath($path) !== $path || !is_file($path) || $stat['nlink'] !== 1
            || $stat['uid'] !== 0 || ($stat['mode'] & 0077) || $stat['size'] > $max)
            throw new RuntimeException('isolated_file_rejected');
        $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('isolated_json_required');
        return $value;
    };
    $scope = $read($root . '/private/member-runtime-scope.json', 65536);
    foreach ($runtime as $name)
        if (realpath(__DIR__ . '/' . $name) !== $root . '/runtime/' . $name
            || fileowner(__DIR__ . '/' . $name) !== 0 || (fileperms(__DIR__ . '/' . $name) & 0022)
            || !preg_match('/^[a-f0-9]{64}$/D', $scope['runtime_sha256'][$name] ?? '')
            || !hash_equals($scope['runtime_sha256'][$name], hash_file('sha256', __DIR__ . '/' . $name)))
            throw new RuntimeException('isolated_runtime_changed');
    $protected = ['bootstrap/app.php', 'vendor/autoload.php', '.env',
        'config/database.php', 'config/cache.php', 'config/machine_failover.php',
        'config/machine_acceptance.php', 'config/machine_provision.php',
        'app/Services/ServerMachine/Cloud/MachineFailoverExecutionGate.php',
        'app/Services/ServerMachine/Cloud/MachineFailoverNativeBridge.php',
        'app/Services/ServerMachine/Cloud/MachineCandidateRetry.php',
        'app/Services/ServerMachine/Cloud/MachineCandidateCleanupClient.php'];
    foreach ($protected as $name)
        if (realpath($root . '/app/' . $name) !== $root . '/app/' . $name
            || fileowner($root . '/app/' . $name) !== 0 || (fileperms($root . '/app/' . $name) & 0022)
            || !preg_match('/^[a-f0-9]{64}$/D', $scope['application_sha256'][$name] ?? '')
            || !hash_equals($scope['application_sha256'][$name], hash_file('sha256', $root . '/app/' . $name)))
            throw new RuntimeException('isolated_application_changed');
    if (file_exists($root . '/app/bootstrap/cache/config.php')) throw new RuntimeException('isolated_cached_config_refused');
    $journal = $root . '/private/member-journal';
    if (realpath($journal) !== $journal || !is_dir($journal) || (fileperms($journal) & 0077))
        throw new RuntimeException('isolated_journal_required');
    $load = static function (string $key) use ($journal, $read): ?array {
        if (!preg_match('/^(source-[ab]|delete-(source-[ab]|candidate-a[12])|round-driver|round-stop|rpc-mutation|rpc-[a-f0-9]{32})$/D', $key))
            throw new RuntimeException('isolated_journal_key');
        return file_exists($journal . '/' . $key . '.json') ? $read($journal . '/' . $key . '.json', 262144) : null;
    };
    $save = static function (string $key, array $value) use ($journal, $load): void {
        $previous = $load($key);
        if ((str_starts_with($key, 'delete-') || $key === 'round-stop') && $previous !== null) {
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
            if (strlen($data) > 262144 || fwrite($stream, $data) !== strlen($data) || !fflush($stream) || !fsync($stream))
                throw new RuntimeException('isolated_journal_unavailable');
            fclose($stream); $stream = null;
            if (!rename($temp, $path)) throw new RuntimeException('isolated_journal_unavailable');
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (file_exists($temp)) unlink($temp);
        }
    };
    if (is_link($journal . '/rpc.lock')) throw new RuntimeException('isolated_rpc_lock_rejected');
    $lock = fopen($journal . '/rpc.lock', 'c+b');
    if (!$lock || fstat($lock)['nlink'] !== 1 || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('isolated_rpc_busy');
    chmod($journal . '/rpc.lock', 0600);
    require __DIR__ . '/member_cli_authority.php';
    [$verifiedScope, $grantHash] = Keli\Acceptance\MemberCliAuthority::readGrant();
    if ($verifiedScope !== $scope) throw new RuntimeException('isolated_grant_changed');
    require $root . '/app/vendor/autoload.php';
    $app = require $root . '/app/bootstrap/app.php';
    // Check configuration before providers boot or any database/cache query is allowed.
    foreach ([Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        Illuminate\Foundation\Bootstrap\LoadConfiguration::class] as $bootstrapper)
        $app->make($bootstrapper)->bootstrap($app);
    $authority = Keli\Acceptance\MemberCliAuthority::controller($scope, $grantHash, static fn () => $load('round-stop') !== null);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $raw = stream_get_contents(STDIN, 8193);
    if (strlen($raw) > 8192) throw new RuntimeException('isolated_request_too_large');
    $request = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new RuntimeException('isolated_request_invalid');
    foreach (['member_source_registry.php', 'member_controller_bridge.php', 'member_retry_driver.php', 'member_round_bridge.php'] as $file)
        require __DIR__ . '/' . $file;
    $bridge = new Keli\Acceptance\MemberRoundBridge($scope, $authority, $load, $save, static fn (): int => time());
    echo json_encode($bridge->dispatch($request), JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    $file = basename($error->getFile());
    $class = (new ReflectionClass($error))->getShortName();
    echo json_encode(['schema' => 2, 'ok' => false, 'reason' => 'isolated_operation_rejected',
        'diagnostic' => ['class' => preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $class) ? $class : 'Throwable',
            'file' => in_array($file, $runtime, true) ? $file : 'application',
            'line' => in_array($file, $runtime, true) ? $error->getLine() : null]]), "\n";
    exit(1);
}

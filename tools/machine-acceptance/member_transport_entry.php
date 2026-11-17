<?php
declare(strict_types=1);

// Fixed isolated CLI only. An unarmed package contains no grant and cannot run.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$runtime = ['member_transport_entry.php', 'member_cli_authority.php', 'member_round_bridge.php',
    'member_retry_driver.php', 'member_controller_bridge.php', 'member_source_registry.php', 'member_native_controller.php'];
$controller = null;
try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') throw new RuntimeException('isolated_cli_required');
    $root = '/opt/keli-isolated';
    if (file_exists($root . '/member-setup-grant.json') || is_link($root . '/member-setup-grant.json'))
        array_push($runtime, 'member_application_bootstrap.php', 'member_provider_bindings.php',
            'member_setup_authority.php', 'member_candidate_callbacks.php', 'member_source_configuration.php',
            'member_source_initialization.php', 'member_dns_initialization.php', 'member_setup_dns.php',
            'member_setup_controller.php', 'member_native_dns_receipt.php', 'aliyun_cleanup.php');
    foreach ([$root, $root . '/private', $root . '/runtime', $root . '/app'] as $directory)
        if (realpath($directory) !== $directory || !is_dir($directory) || (fileperms($directory) & 0022))
            throw new RuntimeException('isolated_directory_required');
    $read = static function (string $path, int $max, bool $public = false): array {
        $stat = @lstat($path);
        if (!$stat || realpath($path) !== $path || !is_file($path) || $stat['nlink'] !== 1
            || $stat['uid'] !== 0 || ($public ? ($stat['mode'] & 0777) !== 0644 : ($stat['mode'] & 0077) !== 0) || $stat['size'] > $max)
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
    if (file_exists($root . '/member-setup-grant.json') || is_link($root . '/member-setup-grant.json'))
        array_push($protected, 'app/Services/ServerMachine/Cloud/MachineCloudCatalog.php',
            'app/Services/ServerMachine/Cloud/MachineFailoverPolicyService.php',
            'app/Services/ServerMachine/Cloud/MachineNativeDnsAdmission.php',
            'app/Services/ServerMachine/Dns/MachineDnsRecordSetExecution.php',
            'app/Services/ServerMachine/Dns/MachineDnsRecordSetProvider.php');
    foreach ($protected as $name)
        if (realpath($root . '/app/' . $name) !== $root . '/app/' . $name
            || fileowner($root . '/app/' . $name) !== 0 || (fileperms($root . '/app/' . $name) & 0022)
            || !preg_match('/^[a-f0-9]{64}$/D', $scope['application_sha256'][$name] ?? '')
            || !hash_equals($scope['application_sha256'][$name], hash_file('sha256', $root . '/app/' . $name)))
            throw new RuntimeException('isolated_application_changed');
    if (file_exists($root . '/app/bootstrap/cache/config.php')) throw new RuntimeException('isolated_cached_config_refused');
    require __DIR__ . '/member_cli_authority.php';
    [$verifiedScope, $grantHash] = Keli\Acceptance\MemberCliAuthority::readGrant();
    if ($verifiedScope !== $scope) throw new RuntimeException('isolated_grant_changed');
    require $root . '/app/vendor/autoload.php';
    $app = require $root . '/app/bootstrap/app.php';
    // Check configuration before providers boot or any database/cache query is allowed.
    foreach ([Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        Illuminate\Foundation\Bootstrap\LoadConfiguration::class] as $bootstrapper)
        $app->make($bootstrapper)->bootstrap($app);
    $raw = stream_get_contents(STDIN, 8193);
    if (strlen($raw) > 8192) throw new RuntimeException('isolated_request_too_large');
    $request = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new RuntimeException('isolated_request_invalid');
    foreach (['member_source_registry.php', 'member_controller_bridge.php', 'member_retry_driver.php', 'member_round_bridge.php'] as $file)
        require __DIR__ . '/' . $file;
    require __DIR__ . '/member_native_controller.php';
    $controller = new Keli\Acceptance\MemberNativeController($scope, $grantHash, $request);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($request['operation'] === 'round-dns-cleanup') {
        foreach (['member_application_bootstrap', 'member_provider_bindings', 'member_source_configuration',
            'member_source_initialization', 'member_setup_authority', 'member_dns_initialization',
            'member_setup_dns', 'member_setup_controller', 'member_native_dns_receipt'] as $file)
            require_once __DIR__ . '/' . $file . '.php';
    }
    echo json_encode($controller->dispatch($request), JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    $file = basename($error->getFile());
    $class = (new ReflectionClass($error))->getShortName();
    $callsite = null;
    foreach ($error->getTrace() as $frame) {
        if (($frame['class'] ?? null) === Keli\Acceptance\MemberNativeController::class
            && ($frame['function'] ?? null) === 'need'
            && ($frame['file'] ?? null) === __DIR__ . '/member_native_controller.php') {
            $callsite = $frame['line'] ?? null;
            break;
        }
    }
    $code = $file === 'member_native_controller.php'
        && preg_match('/^isolated_[a-z_]{1,60}$/D', $error->getMessage()) ? $error->getMessage() : null;
    echo json_encode(['schema' => 2, 'ok' => false, 'reason' => 'isolated_operation_rejected',
        'diagnostic' => ['class' => preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $class) ? $class : 'Throwable',
            'file' => in_array($file, $runtime, true) ? $file : 'application',
            'line' => in_array($file, $runtime, true) ? $error->getLine() : null,
            'code' => $code, 'callsite' => $callsite]]), "\n";
    $controller?->close();
    exit(1);
} finally {
    $controller?->close();
}

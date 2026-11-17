<?php
declare(strict_types=1);

// Root-only isolated RPC. No credential import, implicit grant or default live action.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$controller = null;
try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0)
        throw new RuntimeException('setup_cli_required');
    if (realpath(__DIR__) !== '/opt/keli-isolated/runtime') throw new RuntimeException('setup_runtime_required');
    foreach (['member_cli_authority', 'member_application_bootstrap', 'member_provider_bindings', 'member_source_configuration',
        'member_source_registry', 'member_source_initialization', 'member_setup_authority', 'member_setup_controller'] as $file) {
        $path = __DIR__ . '/' . $file . '.php';
        if (realpath($path) !== $path || fileowner($path) !== 0 || (fileperms($path) & 0022))
            throw new RuntimeException('setup_runtime_changed');
        require_once __DIR__ . '/' . $file . '.php';
    }
    $raw = stream_get_contents(STDIN, 1025);
    if (strlen($raw) > 1024) throw new RuntimeException('setup_request_invalid');
    $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request) || array_keys($request) !== ['operation']
        || !in_array($request['operation'], ['advance', 'status', 'stop'], true)) throw new RuntimeException('setup_request_invalid');
    if ($request['operation'] === 'stop') {
        // This path deliberately does not bootstrap Laravel or connect to MySQL/Redis.
        $reply = Keli\Acceptance\MemberSetupAuthority::revoke();
    } else {
        Keli\Acceptance\MemberApplicationBootstrap::boot();
        $controller = new Keli\Acceptance\MemberSetupController(Keli\Acceptance\MemberSetupAuthority::open());
        $reply = $controller->dispatch($request);
    }
    echo json_encode(['ok' => true, 'result' => $reply], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'reason' => 'isolated_setup_rejected',
        'diagnostic' => ['class' => (new ReflectionClass($error))->getShortName(),
            'file' => basename($error->getFile()), 'line' => $error->getLine()]]), "\n";
    exit(1);
} finally { $controller?->close(); }

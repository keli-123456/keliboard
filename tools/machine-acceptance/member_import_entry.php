<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$controller = null;
try {
    if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || posix_geteuid() !== 0)
        throw new RuntimeException('member_import_root_cli_required');
    if (realpath(__DIR__) !== '/opt/keli-isolated/runtime') throw new RuntimeException('member_import_runtime_required');
    foreach (['member_cli_authority', 'member_application_bootstrap', 'member_provider_bindings',
        'member_prerequisite_import', 'member_source_configuration', 'member_setup_authority', 'member_bootstrap_controller'] as $name) {
        $path = __DIR__ . '/' . $name . '.php';
        if (realpath($path) !== $path || fileowner($path) !== 0 || (fileperms($path) & 0022))
            throw new RuntimeException('member_import_runtime_changed');
        require_once $path;
    }
    $raw = stream_get_contents(STDIN, 4097);
    $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (strlen($raw) > 4096 || !is_array($request)) throw new RuntimeException('member_import_request_invalid');
    Keli\Acceptance\MemberBootstrapController::validateRequest($request);
    if ($request['operation'] === 'stop') {
        // Stops admission and drains in-flight bootstrap work without MySQL/Redis.
        $reply = Keli\Acceptance\MemberBootstrapController::stop();
    } else {
        Keli\Acceptance\MemberApplicationBootstrap::boot();
        $controller = new Keli\Acceptance\MemberBootstrapController();
        $reply = $controller->rpc($request);
    }
    echo json_encode(['ok' => true, 'operation' => $request['operation']] + $reply, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $code = preg_match('/^member_(import|application|configuration|setup)_[a-z_]{1,70}$/D', $error->getMessage())
        ? $error->getMessage() : 'member_import_failed';
    echo json_encode(['ok' => false, 'reason' => $code, 'class' => (new ReflectionClass($error))->getShortName(),
        'file' => basename($error->getFile()), 'line' => $error->getLine()]);
    exit(1);
} finally { $controller?->close(); }

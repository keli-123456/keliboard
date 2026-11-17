<?php
declare(strict_types=1);

// The fixed transport command is checked by Python. Only outgoing HTTP is faked.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$controller = null;
try {
    if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('KELI_MEMBER_FULL_APPLICATION') !== '1'
        || PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || posix_geteuid() !== 0 || count($argv) !== 3
        || !in_array($argv[1], ['bootstrap', 'setup'], true)) throw new RuntimeException('fixture_only');
    foreach (['member_cli_authority', 'member_application_bootstrap', 'member_provider_bindings',
        'member_prerequisite_import', 'member_source_configuration', 'member_setup_authority', 'member_bootstrap_controller',
        'member_source_registry', 'member_source_initialization', 'member_dns_initialization',
        'member_setup_dns', 'member_native_grant', 'member_setup_controller'] as $file)
        require_once '/opt/keli-isolated/runtime/' . $file . '.php';
    Keli\Acceptance\MemberApplicationBootstrap::boot();
    Illuminate\Support\Facades\Http::fake((require __DIR__ . '/r35-setup-provider-fixture.php')($argv[2]));
    $raw = stream_get_contents(STDIN, 4097);
    if (strlen($raw) > 4096) throw new RuntimeException('fixture_request_size');
    $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if ($argv[1] === 'bootstrap') {
        Keli\Acceptance\MemberBootstrapController::validateRequest($request);
        if (!in_array($request['operation'], ['configure', 'configuration-read', 'setup-issue', 'setup-read'], true))
            throw new RuntimeException('fixture_bootstrap_scope');
        $controller = new Keli\Acceptance\MemberBootstrapController();
        $reply = ['ok' => true, 'operation' => $request['operation']] + $controller->rpc($request);
    } else {
        Keli\Acceptance\MemberSetupController::validateRequest($request);
        if (!in_array($request['operation'], ['dns-control', 'resources'], true)) throw new RuntimeException('fixture_setup_scope');
        $controller = new Keli\Acceptance\MemberSetupController(Keli\Acceptance\MemberSetupAuthority::open(), $request['operation'] === 'resources');
        $reply = $controller->rpc($request);
    }
    echo json_encode($reply, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $path = ($argv[2] ?? '') . '/fixture-provider-first-error.json';
    $provider = is_file($path) ? json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR) : null;
    echo json_encode(['ok' => false, 'code' => 'fixture_setup_rpc_failed', 'diagnostic' => [
        'class' => (new ReflectionClass($error))->getShortName(), 'file' => basename($error->getFile()), 'line' => $error->getLine(),
        'reason' => preg_match('/^(fixture|member)_[a-z_]{1,90}$/D', $error->getMessage()) ? $error->getMessage() : 'unclassified'],
        'provider_diagnostic' => $provider]);
    exit(1);
} finally { $controller?->close(); }

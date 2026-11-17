<?php
declare(strict_types=1);

// No application bootstrap, .env, service container, production database or SSH.
$controller = null;
try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || count($argv) !== 2) throw new RuntimeException('operator_cli_required');
    $roots = [dirname(__DIR__, 2), dirname(__DIR__) . '/app'];
    $roots = array_values(array_filter($roots, static fn ($root) => is_file($root . '/vendor/autoload.php')));
    if (count($roots) !== 1) throw new RuntimeException('operator_vendor_required');
    require $roots[0] . '/vendor/autoload.php';
    $container = new Illuminate\Container\Container();
    Illuminate\Container\Container::setInstance($container);
    Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    $container->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory());
    require_once __DIR__ . '/member_provider_bindings.php';
    require_once __DIR__ . '/member_cli_authority.php';
    require_once __DIR__ . '/member_dns_initialization.php';
    require_once __DIR__ . '/member_prerequisite_import.php';
    require_once __DIR__ . '/member_operator_provider.php';
    $raw = stream_get_contents(STDIN, 16385);
    if (!is_string($raw) || strlen($raw) > 16384) throw new RuntimeException('operator_request_invalid');
    $request = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_string($request['scope_sha256'] ?? null)) throw new RuntimeException('operator_request_invalid');
    $controller = new Keli\Acceptance\MemberOperatorProvider($argv[1], $request['scope_sha256']);
    echo json_encode(['ok' => true] + $controller->dispatch($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    echo "{\"ok\":false,\"code\":\"operator_cleanup_unconfirmed\"}\n";
    exit(1);
} finally {
    if ($controller !== null) $controller->close();
}

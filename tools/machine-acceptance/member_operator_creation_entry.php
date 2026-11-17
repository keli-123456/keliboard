<?php
declare(strict_types=1);

// Separate explicit control-create permit; no application bootstrap or database.
$operator = null;
try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || count($argv) !== 2) throw new RuntimeException('operator_cli_required');
    $roots = array_values(array_filter([dirname(__DIR__, 2), dirname(__DIR__) . '/app'],
        static fn ($root) => is_file($root . '/vendor/autoload.php')));
    if (count($roots) !== 1) throw new RuntimeException('operator_vendor_required');
    require $roots[0] . '/vendor/autoload.php';
    $container = new Illuminate\Container\Container();
    Illuminate\Container\Container::setInstance($container);
    Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    $container->instance('config', new Illuminate\Config\Repository());
    $container->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory());
    foreach (['member_provider_bindings', 'member_cli_authority', 'member_prerequisite_import',
        'member_control_creation', 'member_operator_creation'] as $name) require_once __DIR__ . '/' . $name . '.php';
    $raw = stream_get_contents(STDIN, 16385);
    if (!is_string($raw) || strlen($raw) > 16384) throw new RuntimeException('operator_request_invalid');
    $request = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_string($request['scope_sha256'] ?? null)) throw new RuntimeException('operator_request_invalid');
    $operator = new Keli\Acceptance\MemberOperatorCreation($argv[1], $request['scope_sha256']);
    echo json_encode(['ok' => true] + $operator->dispatch($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable) {
    echo "{\"ok\":false,\"code\":\"operator_creation_unconfirmed\"}\n";
    exit(1);
} finally { if ($operator !== null) $operator->close(); }

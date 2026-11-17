<?php
declare(strict_types=1);
// CI-only HTTP fake; creation, request encryption, journaling and polling are real.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || count($argv) !== 2) exit(2);
$repo = dirname(__DIR__);
require $repo . '/vendor/autoload.php';
$container = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($container);
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$container->instance('config', new Illuminate\Config\Repository());
$container->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory());
foreach (['member_provider_bindings', 'member_cli_authority', 'member_prerequisite_import',
    'member_control_creation', 'member_operator_creation'] as $name)
    require_once $repo . '/tools/machine-acceptance/' . $name . '.php';
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberOperatorCreation;
$directory = $argv[1];
if (realpath($directory) !== $directory || !is_dir($directory) || (fileperms($directory) & 0777) !== 0700) exit(2);
$path = $directory . '/fixture-cloud.json';
if (!is_file($path) || is_link($path)) exit(2);
Http::preventStrayRequests();
Http::fake(function ($request) use ($path) {
    $state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (parse_url($request->url(), PHP_URL_HOST) !== 'api.linode.com') throw new RuntimeException('unexpected-provider');
    $state['methods'][] = $request->method();
    $url = parse_url($request->url(), PHP_URL_PATH);
    if ($request->method() === 'POST') {
        if ($url !== '/v4/linode/instances' || $state['present']) throw new RuntimeException('duplicate-create');
        $state['present'] = true;
        $state['row'] = ['id' => 999, 'label' => $request['label'], 'tags' => $request['tags'],
            'region' => $request['region'], 'type' => $request['type'], 'image' => $request['image'],
            'created' => gmdate('Y-m-d\TH:i:s'), 'ipv4' => ['9.9.9.10'], 'status' => 'running'];
        file_put_contents($path, json_encode($state)); chmod($path, 0600);
        return Http::response($state['row'], 201);
    }
    if ($request->method() !== 'GET') throw new RuntimeException('unexpected-method');
    file_put_contents($path, json_encode($state)); chmod($path, 0600);
    return Http::response(match ($url) {
        '/v4/regions/sg-sin-2' => ['id' => 'sg-sin-2', 'status' => 'ok', 'capabilities' => ['Metadata']],
        '/v4/images/linode%2Fdebian12' => ['id' => 'linode/debian12', 'capabilities' => ['cloud-init'], 'deprecated' => false, 'status' => 'available'],
        '/v4/linode/types/g6-standard-2' => ['id' => 'g6-standard-2', 'class' => 'standard'],
        '/v4/account/settings' => ['backups_enabled' => false],
        '/v4/linode/instances' => ['pages' => 1, 'data' => $state['present'] ? [$state['row']] : []],
        default => throw new RuntimeException('unexpected-path'),
    });
});
$operator = null;
try {
    $request = json_decode(stream_get_contents(STDIN, 16385), true, 24, JSON_THROW_ON_ERROR);
    $operator = new MemberOperatorCreation($directory, $request['scope_sha256']);
    echo json_encode(['ok' => true] + $operator->dispatch($request), JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable) {
    echo "{\"ok\":false,\"code\":\"operator_creation_unconfirmed\"}\n";
    exit(1);
} finally { if ($operator !== null) $operator->close(); }

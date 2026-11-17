<?php
declare(strict_types=1);
// CI-only provider HTTP boundary. The operator class, journal and provider adapter are real.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || count($argv) !== 2) exit(2);
$repo = dirname(__DIR__);
require $repo . '/vendor/autoload.php';
$container = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($container);
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$container->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory());
foreach (['member_provider_bindings', 'member_cli_authority', 'member_dns_initialization', 'member_prerequisite_import', 'member_operator_provider'] as $file)
    require_once $repo . '/tools/machine-acceptance/' . $file . '.php';
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberProviderBindings;
use Keli\Acceptance\MemberOperatorProvider;
$directory = $argv[1];
if (realpath($directory) !== $directory || !is_dir($directory) || (fileperms($directory) & 0777) !== 0700) exit(2);
$path = $directory . '/fixture-cloud.json';
if (!is_file($path) || is_link($path)) exit(2);
Http::preventStrayRequests();
Http::fake(function ($request) use ($path, $directory) {
    $state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $scope = json_decode(file_get_contents($directory . '/provider-scope.json'), true, 32, JSON_THROW_ON_ERROR);
    $pin = $scope['pins'][0];
    if ($request->url() !== 'https://api.linode.com/v4/linode/instances/' . $pin['external_id']
        || !in_array($request->method(), ['GET', 'DELETE'], true)) throw new RuntimeException('fixture_unexpected_provider_request');
    $state['methods'][] = $request->method();
    if ($request->method() === 'DELETE') {
        if (!$state['present']) throw new RuntimeException('fixture_duplicate_delete');
        $state['present'] = false;
        file_put_contents($path, json_encode($state)); chmod($path, 0600);
        throw new Illuminate\Http\Client\ConnectionException('fixture-lost-delete-ack');
    }
    file_put_contents($path, json_encode($state)); chmod($path, 0600);
    return $state['present'] ? Http::response(['id' => (int) $pin['external_id'], 'label' => $pin['label'],
        'tags' => [$pin['marker']], 'region' => $pin['region'], 'type' => $pin['size'], 'image' => $pin['image'],
        'created' => rtrim($pin['provider_created_at'], 'Z')]) : Http::response('', 404);
});
$controller = null;
try {
    $request = json_decode(stream_get_contents(STDIN, 16385), true, 24, JSON_THROW_ON_ERROR);
    $controller = new MemberOperatorProvider($directory, $request['scope_sha256']);
    echo json_encode(['ok' => true] + $controller->dispatch($request), JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable) {
    echo "{\"ok\":false,\"code\":\"operator_cleanup_unconfirmed\"}\n";
    exit(1);
} finally {
    if ($controller !== null) $controller->close();
}

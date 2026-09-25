<?php

declare(strict_types=1);

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Protocols\{ClashMeta, General, SingBox};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 65534
    || !isset($fixture, $root) || $fixture->nodeFixture === null
    || getenv('KELI_TRAFFIC_ECH_FIXTURE') !== '1') {
    throw new RuntimeException('Explicit isolated ECH fixture required.');
}

$data = json_decode($argv[2] ?? '', true, 16, JSON_THROW_ON_ERROR);
$server = Server::query()->findOrFail(1);
$directory = realpath((string) ($data['directory'] ?? ''));
$evidence = realpath((string) getenv('KELI_TRAFFIC_EVIDENCE_DIR'));
if ($directory === false || $evidence === false || dirname($directory) !== dirname($evidence)
    || !in_array(basename($directory), ['embedded-hy2', 'native-hy2'], true)
    || $server->type !== 'hysteria' || !is_file($directory . '/server.pem')
    || !is_file($directory . '/server.crt') || !is_file($directory . '/server.key')) {
    throw new RuntimeException('Expected private ECH node fixture directory.');
}

$settings = $server->protocol_settings;
$settings['tls'] = ['server_name' => 'ech.internal.test', 'allow_insecure' => false];
$settings['ech'] = ['enabled' => true, 'key_file' => $directory . '/server.pem', 'config' => $data['config'] ?? null];
$request = ServerSave::create('/', 'POST', [
    'name' => $server->name, 'type' => 'hysteria', 'runtime' => 'generic',
    'host' => '127.0.0.1', 'port' => (int) $server->server_port, 'server_port' => (int) $server->server_port,
    'rate' => 1.5, 'protocol_settings' => $settings,
]);
(new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
$validator = app('validator')->make($request->all(), $request->rules());
$request->withValidator($validator);
if (!$validator->passes()) {
    throw new RuntimeException(json_encode($validator->errors()->all(), JSON_THROW_ON_ERROR));
}
if (!Schema::hasColumn('v2_server', 'host')) {
    Schema::table('v2_server', function (Blueprint $table): void {
        $table->string('host')->nullable();
        $table->string('port')->nullable();
    });
}
$server->host = '127.0.0.1';
$server->port = (string) $server->server_port;
$server->protocol_settings = $validator->validated()['protocol_settings'];
$server->save();
$server = Server::query()->findOrFail(1);
$savedEch = $server->protocol_settings['ech'];
$expectedEch = $settings['ech'];
ksort($savedEch);
ksort($expectedEch);
if ($savedEch !== $expectedEch) {
    throw new RuntimeException('ECH database round trip mismatch.');
}
$sing = (new ReflectionClass(SingBox::class))->newInstanceWithoutConstructor();
$row = $server->toArray();
echo json_encode([
    'saved_public_config' => $server->protocol_settings['ech']['config'],
    'uri' => General::buildHysteria('uuid-a', $row),
    'mihomo' => ClashMeta::buildHysteria('uuid-a', $row, []),
    'sing_box' => (new ReflectionMethod(SingBox::class, 'buildHysteria'))->invoke($sing, 'uuid-a', $row),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;

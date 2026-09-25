<?php

// CLI-only fixture: uses the in-memory test container, never bootstrap/app.php.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class EchExportFixture extends \Tests\TestCase
{
    public function export(array $input): array
    {
        parent::setUp();
        $settings = ['version' => 2, 'tls' => ['server_name' => 'ech.internal.test', 'allow_insecure' => false],
            'ech' => ['enabled' => true, 'key_file' => '/private/fixture/ech.pem', 'config' => $input['config']]];
        $request = \App\Http\Requests\Admin\ServerSave::create('/', 'POST', [
            'name' => 'ECH fixture', 'type' => 'hysteria', 'runtime' => 'v2node',
            'host' => '127.0.0.1', 'port' => $input['port'], 'server_port' => $input['port'], 'rate' => 1,
            'protocol_settings' => $settings,
        ]);
        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
        $validator = app('validator')->make($request->all(), $request->rules());
        $request->withValidator($validator);
        if (!$validator->passes()) throw new \RuntimeException(json_encode($validator->errors()->all()));
        $node = (new \App\Models\Server())->forceFill($validator->validated());
        $server = $node->toArray();
        $sing = (new \ReflectionClass(\App\Protocols\SingBox::class))->newInstanceWithoutConstructor();
        return [
            'node' => (new \App\Services\Node\NodeConfigService())->buildResponse($node, true),
            'mihomo' => \App\Protocols\ClashMeta::buildHysteria('fixture-password', $server, []),
            'sing_box' => (new \ReflectionMethod(\App\Protocols\SingBox::class, 'buildHysteria'))->invoke($sing, 'fixture-password', $server),
            'uri' => \App\Protocols\General::buildHysteria('fixture-password', $server),
        ];
    }
}

$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
echo json_encode((new EchExportFixture('export'))->export($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

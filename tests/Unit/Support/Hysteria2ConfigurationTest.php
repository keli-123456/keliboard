<?php

namespace Tests\Unit\Support;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\Node\NodeConfigService;
use App\Support\ProtocolCapabilityService;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Tests\TestCase;

final class Hysteria2ConfigurationTest extends TestCase
{
    private function settings(array $overrides = []): array
    {
        return array_replace([
            'version' => 2,
            'obfs' => ['open' => true, 'type' => 'gecko', 'password' => 'secret'],
            'congestion_control' => 'bbr',
            'network_settings' => [
                'masquerade' => ['type' => 'proxy', 'url' => 'https://private.example.com/site'],
                'gecko_min_packet_size' => 600,
                'gecko_max_packet_size' => 1400,
            ],
        ], $overrides);
    }

    private function validateSettings(array $settings, string $runtime = 'generic'): \Illuminate\Validation\Validator
    {
        $request = ServerSave::create('/api/v2/admin/server/manage/save', 'POST', [
            'type' => 'hysteria', 'runtime' => $runtime, 'name' => 'HY2', 'host' => 'edge.example.com',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'protocol_settings' => $settings,
        ]);
        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($request->all(), $request->rules());
        $request->withValidator($validator);
        return $validator;
    }

    public function test_round_trip_preserves_transport_for_both_node_apis(): void
    {
        foreach (['generic', 'v2node'] as $runtime) {
            $validator = $this->validateSettings($this->settings(), $runtime);
            $this->assertTrue($validator->passes(), json_encode($validator->errors()->all()));
            $node = (new Server())->forceFill(['type' => 'hysteria', 'name' => 'HY2', 'host' => 'edge.example.com', 'port' => 443, 'server_port' => 443, 'protocol_settings' => $validator->validated()['protocol_settings']]);
            $this->assertSame($this->settings()['network_settings'], $node->protocol_settings['network_settings']);
            $response = (new NodeConfigService())->buildResponse($node, $runtime === 'v2node');
            $this->assertSame('gecko', $response['obfs']);
            $this->assertSame('secret', $response['obfs-password']);
            $this->assertSame('bbr', $response['congestion_control']);
            $this->assertSame($this->settings()['network_settings'], $response[$runtime === 'v2node' ? 'network_settings' : 'networkSettings']);
        }
    }

    public function test_invalid_settings_are_rejected(): void
    {
        $networks = [
            ['ech_key_file' => '/tmp/ech'], ['brutal_disable_loss_compensation' => true],
            ['gecko_min_packet_size' => 1201], ['gecko_max_packet_size' => 511],
            ['gecko_max_packet_size' => 2049], ['gecko_min_packet_size' => -1],
            ['gecko_min_packet_size' => '512'], ['gecko_min_packet_size' => null],
            ['masquerade' => null], ['masquerade' => []], ['masquerade' => ['type' => 'not_found', 'url' => 'https://example.com']],
        ];
        foreach ([
            ['type' => 'string', 'body' => 'x', 'status' => 233],
            ['type' => 'string', 'body' => 'x', 'status' => 204],
            ['type' => 'string', 'body' => '', 'status' => '200'],
            ['type' => 'string', 'body' => '', 'status' => null],
            ['type' => 'string', 'body' => '', 'content_type' => null],
            ['type' => 'string', 'body' => '', 'content_type' => "text/plain\r\nX: x"],
            ['type' => 'file', 'root' => ''], ['type' => 'file', 'root' => "x\0y"],
            ['type' => 'proxy', 'url' => 'https://a:b@example.com'],
            ['type' => 'proxy', 'url' => 'https://example.com?'],
            ['type' => 'proxy', 'url' => 'https://example.com/#x'],
            ['type' => 'proxy', 'url' => 'file:///tmp/x'],
            ['type' => 'proxy', 'url' => 'https://example.com\\evil'],
        ] as $masquerade) $networks[] = ['masquerade' => $masquerade];
        foreach ($networks as $network) {
            $this->assertFalse($this->validateSettings($this->settings(['network_settings' => $network]))->passes(), json_encode($network));
        }
        foreach ([['congestion_control' => 'brutal'], ['version' => 1], ['obfs' => ['open' => false]], ['obfs' => ['open' => true, 'type' => 'gecko', 'password' => 'abc']]] as $override) {
            $this->assertFalse($this->validateSettings($this->settings($override))->passes());
        }
    }

    public function test_all_masquerade_modes_and_empty_body_survive_validation(): void
    {
        foreach ([['type' => 'not_found'], ['type' => 'string', 'body' => '', 'status' => 204], ['type' => 'string', 'body' => null, 'status' => 304], ['type' => 'file', 'root' => '/var/www/html'], ['type' => 'proxy', 'url' => 'https://example.com/path']] as $masquerade) {
            $validator = $this->validateSettings($this->settings(['network_settings' => ['masquerade' => $masquerade]]));
            $this->assertTrue($validator->passes(), json_encode($validator->errors()->all()));
            if ($masquerade['type'] === 'string') $this->assertSame('', data_get($validator->validated(), 'protocol_settings.network_settings.masquerade.body'));
        }
        $body = "  leading\ntrailing  ";
        $request = \Illuminate\Http\Request::create('/', 'POST', ['protocol_settings' => ['network_settings' => ['masquerade' => ['body' => $body]]]]);
        (new \App\Http\Middleware\TrimStrings())->handle($request, fn ($request) => new \Symfony\Component\HttpFoundation\Response(''));
        $this->assertSame($body, $request->input('protocol_settings.network_settings.masquerade.body'));
    }

    public function test_exports_do_not_leak_server_only_configuration(): void
    {
        $server = ['type' => 'hysteria', 'name' => 'HY2', 'host' => 'edge.example.com', 'port' => 443, 'protocol_settings' => $this->settings()];
        $clash = ClashMeta::buildHysteria('uuid', $server, []);
        $sing = (new \ReflectionClass(SingBox::class))->newInstanceWithoutConstructor();
        $singExport = (new \ReflectionMethod(SingBox::class, 'buildHysteria'))->invoke($sing, 'uuid', $server);
        $uri = General::buildHysteria('uuid', $server);
        $this->assertSame('gecko', $clash['obfs']);
        $this->assertSame(600, $clash['obfs-min-packet-size']);
        $this->assertSame(1400, $singExport['obfs']['max_packet_size']);
        $this->assertStringContainsString('obfs=gecko', $uri);
        $encoded = json_encode([$clash, $singExport, $uri]);
        $this->assertStringNotContainsString('private.example.com', $encoded);
        $this->assertStringNotContainsString('masquerade', $encoded);
        $this->assertStringNotContainsString('congestion_control', $encoded);
    }

    public function test_gecko_requires_verified_core_and_legacy_behavior_is_unchanged(): void
    {
        $service = new ProtocolCapabilityService(require base_path('config/protocol_capabilities.php'));
        $server = ['type' => 'hysteria', 'protocol_settings' => $this->settings()];
        foreach ([['mihomo', '1.19.31', true], ['mihomo', '1.19.30', false], ['sing-box', '1.14.0', true], ['sing-box', '1.14.0-alpha.26', false], ['sing-box', '1.14.0.1', false], ['mihomo', null, false], ['clash-verge', '9.0.0', false], ['hiddify', '99.0.0', false], ['shadowrocket', '9999', false], [null, null, false]] as [$client, $version, $expected]) {
            $this->assertSame($expected, $service->supportsClient($client, $version, $server)->supported, "$client / $version");
        }
        $this->assertSame('block', $service->assessClientSupport('hiddify', $server)['status']);
        $this->assertSame('partial', $service->assessClientSupport('mihomo', $server)['status']);
        $server['protocol_settings'] = ['version' => 2, 'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'mask']];
        $this->assertTrue($service->supportsClient(null, null, $server)->supported);
        foreach ([1, 2] as $version) {
            $this->assertTrue($this->validateSettings(['version' => $version])->passes());
        }
        $node = (new Server())->forceFill(['type' => 'hysteria', 'host' => 'example.com', 'port' => 443, 'server_port' => 443, 'protocol_settings' => ['version' => 2]]);
        $response = (new NodeConfigService())->buildResponse($node, false);
        $this->assertNull($response['networkSettings']);
        $this->assertArrayNotHasKey('congestion_control', $response);
    }
}

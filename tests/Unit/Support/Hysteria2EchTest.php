<?php

namespace Tests\Unit\Support;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Protocols\{ClashMeta, General, SingBox};
use App\Services\Node\NodeConfigService;
use App\Support\{Hysteria2Ech, ProtocolCapabilityService};
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\Factory;
use Tests\TestCase;

final class Hysteria2EchTest extends TestCase
{
    public static function publicConfig(int $id = 7): string
    {
        $name = 'public.example.com';
        $contents = chr($id) . pack('nn', 32, 32) . str_repeat('x', 32) . pack('nnn', 4, 1, 1) . "\0" . chr(strlen($name)) . $name . "\0\0";
        $config = "\xfe\x0d" . pack('n', strlen($contents)) . $contents;
        return base64_encode(pack('n', strlen($config)) . $config);
    }

    private function settings(): array
    {
        return ['version' => 2, 'tls' => ['server_name' => 'real.example.com', 'allow_insecure' => false],
            'ech' => ['enabled' => true, 'key_file' => '/etc/kelinode/ech.pem', 'config' => self::publicConfig()]];
    }

    private function validate(array $settings): \Illuminate\Validation\Validator
    {
        $request = ServerSave::create('/', 'POST', ['type' => 'hysteria', 'runtime' => 'generic', 'name' => 'ECH test', 'host' => 'edge.example.com', 'port' => 443, 'server_port' => 443, 'rate' => 1, 'protocol_settings' => $settings]);
        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($request->all(), $request->rules());
        $request->withValidator($validator);
        return $validator;
    }

    public function test_public_config_parser_bounds_and_rotation(): void
    {
        $config = self::publicConfig();
        $this->assertTrue(Hysteria2Ech::validConfig($config));
        $entry = substr(base64_decode($config), 2);
        $entry2 = substr(base64_decode(self::publicConfig(8)), 2);
        $this->assertTrue(Hysteria2Ech::validConfig(base64_encode(pack('n', strlen($entry . $entry2)) . $entry . $entry2)));
        foreach (['', 'abc', " $config", Hysteria2Ech::pem($config), "-----BEGIN ECH KEYS-----\nprivate\n-----END ECH KEYS-----", base64_encode("\0\0"), base64_encode(pack('n', strlen($entry) * 2) . $entry . $entry), base64_encode(base64_decode($config) . 'tail'), str_repeat('A', 16385), [], null] as $invalid) {
            $this->assertFalse(Hysteria2Ech::validConfig($invalid));
        }
        $raw = base64_decode($config);
        for ($length = 0; $length < strlen($raw); $length++) {
            $this->assertFalse(Hysteria2Ech::validConfig(base64_encode(substr($raw, 0, $length))));
        }
    }

    public function test_ech_round_trip_node_and_subscriptions(): void
    {
        $validator = $this->validate($this->settings());
        $this->assertTrue($validator->passes(), json_encode($validator->errors()->all()));
        $node = (new Server())->forceFill(['type' => 'hysteria', 'name' => 'ECH test', 'host' => 'edge.example.com', 'port' => 443, 'server_port' => 443, 'protocol_settings' => $validator->validated()['protocol_settings']]);
        $this->assertSame($this->settings()['ech'], $node->protocol_settings['ech']);
        foreach ([false, true] as $v2) {
            $wire = (new NodeConfigService())->buildResponse($node, $v2);
            $this->assertSame('/etc/kelinode/ech.pem', data_get($wire, ($v2 ? 'network_settings' : 'networkSettings') . '.ech_key_file'));
            $this->assertStringNotContainsString(self::publicConfig(), json_encode($wire));
        }
        $server = $node->toArray();
        $clash = ClashMeta::buildHysteria('fixture', $server, []);
        $sing = (new \ReflectionClass(SingBox::class))->newInstanceWithoutConstructor();
        $sing = (new \ReflectionMethod(SingBox::class, 'buildHysteria'))->invoke($sing, 'fixture', $server);
        $uri = General::buildHysteria('fixture', $server);
        $this->assertSame(['enable' => true, 'config' => self::publicConfig()], $clash['ech-opts']);
        $this->assertSame(['enabled' => true, 'config' => [Hysteria2Ech::pem(self::publicConfig())]], $sing['tls']['ech']);
        parse_str(parse_url(trim($uri), PHP_URL_QUERY), $query);
        $this->assertSame(self::publicConfig(), $query['ech']);
        $this->assertStringNotContainsString('/etc/kelinode', json_encode([$clash, $sing, $uri]));
        $this->assertStringNotContainsString('ECH KEYS', json_encode([$clash, $sing, $uri]));
        $node->protocol_settings = ['version' => 2];
        $this->assertNull((new NodeConfigService())->buildResponse($node, false)['networkSettings']);
        $this->assertArrayNotHasKey('ech-opts', ClashMeta::buildHysteria('fixture', $node->toArray(), []));
    }

    public function test_invalid_or_insecure_settings_are_rejected(): void
    {
        foreach (['relative.pem', '/tmp/../ech.pem', "/tmp/a\0b", '/tmp/a b', '', str_repeat('a', 4097)] as $path) {
            $settings = $this->settings();
            $settings['ech']['key_file'] = $path;
            $this->assertFalse($this->validate($settings)->passes());
        }
        foreach (['ech.enabled' => 'true', 'ech.config' => 'private', 'ech.secret' => 'private', 'tls.allow_insecure' => true, 'tls.server_name' => '127.0.0.1', 'version' => 1] as $field => $value) {
            $settings = $this->settings();
            data_set($settings, $field, $value);
            $this->assertFalse($this->validate($settings)->passes(), $field);
        }
        $settings = $this->settings();
        $settings['ech']['enabled'] = false;
        $this->assertFalse($this->validate($settings)->passes());
        $settings['ech'] = ['enabled' => false];
        $this->assertTrue($this->validate($settings)->passes());
    }

    public function test_unverified_clients_are_not_silently_downgraded(): void
    {
        $service = new ProtocolCapabilityService(require base_path('config/protocol_capabilities.php'));
        $server = ['type' => 'hysteria', 'protocol_settings' => $this->settings()];
        foreach ([['mihomo', '1.19.31', true], ['sing-box', '1.14.0', true], ['sing-box', '1.14.0-alpha.1', false], ['sing-box', '1.13.0', false], ['mihomo', null, false], ['hiddify', '99.0.0', false], ['clash-verge', '99.0.0', false], [null, null, false], ['general', null, false]] as [$client, $version, $expected]) {
            $this->assertSame($expected, $service->supportsClient($client, $version, $server)->supported);
        }
        $this->assertSame('block', $service->assessClientSupport('hiddify', $server)['status']);
        $this->assertSame('partial', $service->assessClientSupport('mihomo', $server)['status']);
        $server['protocol_settings']['ech'] = ['enabled' => false];
        $this->assertTrue($service->supportsClient(null, null, $server)->supported);
    }
}

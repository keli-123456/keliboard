<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Client\ClientController;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\QuantumultX;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Support\ProtocolManager;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ClientControllerTest extends TestCase
{
    public function test_get_client_info_maps_canonical_name_and_version_from_flag_variants(): void
    {
        $this->bindProtocolManager([
            QuantumultX::class,
            ClashMeta::class,
            SingBox::class,
            General::class,
        ]);

        $controller = new ClientController();
        $method = new \ReflectionMethod(ClientController::class, 'getClientInfo');
        $method->setAccessible(true);

        $singBox = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'singbox 1.12.0']));
        $singBoxWrapper = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'sing-box/1.2.8.1103']));
        $bareSingBox = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'sing-box']));
        $karing = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'Karing/1.2.19.2209 platform/windows']));
        $karingComposite = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'Karing/1.2.19.2209 platform/windows mihomo/1.19.23 ClashMeta']));
        $clashMeta = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'ClashX Meta/1.3.5']));
        $mihomo = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'mihomo/1.19.0']));
        $verge = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'Clash Verge/v1.7.0']));
        $hiddify = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'Hiddify/1.2.8.1103']));
        $sparkle = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'Sparkle/1.2.8.1103']));

        $this->assertSame('sing-box', $singBox['name']);
        $this->assertSame('1.12.0', $singBox['version']);
        $this->assertSame('sing-box', $singBoxWrapper['name']);
        $this->assertSame('1.2.8.1103', $singBoxWrapper['version']);
        $this->assertSame('sing-box', $bareSingBox['name']);
        $this->assertSame('1.12.0', $bareSingBox['version']);
        $this->assertSame('karing', $karing['name']);
        $this->assertSame('1.13.0', $karing['version']);
        $this->assertSame('karing', $karingComposite['name']);
        $this->assertSame('1.13.0', $karingComposite['version']);

        $this->assertSame('clashx meta', $clashMeta['name']);
        $this->assertSame('1.3.5', $clashMeta['version']);
        $this->assertSame('mihomo', $mihomo['name']);
        $this->assertSame('1.19.0', $mihomo['version']);

        $this->assertSame('clash-verge', $verge['name']);
        $this->assertSame('1.7.0', $verge['version']);

        $this->assertSame('hiddify', $hiddify['name']);
        $this->assertSame('1.2.8.1103', $hiddify['version']);
        $this->assertSame('sparkle', $sparkle['name']);
        $this->assertSame('1.2.8.1103', $sparkle['version']);
    }

    public function test_get_client_info_does_not_extract_unrelated_browser_version(): void
    {
        $this->bindProtocolManager([
            QuantumultX::class,
            ClashMeta::class,
            SingBox::class,
        ]);

        $controller = new ClientController();
        $method = new \ReflectionMethod(ClientController::class, 'getClientInfo');
        $method->setAccessible(true);

        $info = $method->invoke($controller, Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 AppleWebKit/537.36 Safari/537.36',
        ]));

        $this->assertNull($info['name']);
        $this->assertNull($info['version']);
    }

    public function test_get_client_info_maps_common_clients_from_user_agent_without_flag(): void
    {
        $this->bindProtocolManager([
            QuantumultX::class,
            ClashMeta::class,
            Shadowrocket::class,
            SingBox::class,
            General::class,
        ]);

        $controller = new ClientController();
        $method = new \ReflectionMethod(ClientController::class, 'getClientInfo');
        $method->setAccessible(true);

        $cases = [
            ['sing-box/1.13.11', 'sing-box', '1.13.11'],
            ['Karing/1.2.8.1103', 'karing', '1.13.0'],
            ['Karing/1.2.19.2209 platform/ios mihomo/1.19.23 ClashMeta', 'karing', '1.13.0'],
            ['Hiddify/1.2.8.1103', 'hiddify', '1.2.8.1103'],
            ['Sparkle/1.2.8.1103', 'sparkle', '1.2.8.1103'],
            ['mihomo/1.19.0', 'mihomo', '1.19.0'],
            ['Clash Verge/v1.7.0', 'clash-verge', '1.7.0'],
            ['NekoBox/Android/1.4.1 (Prefer ClashMeta Format)', 'clash-meta', '1.4.1'],
            ['v2ray', 'v2ray', null],
            ['Shadowrocket/2698 CFNetwork/1496.0.7 Darwin/23.5.0', 'shadowrocket', '2698'],
        ];

        foreach ($cases as [$userAgent, $expectedName, $expectedVersion]) {
            $info = $method->invoke($controller, Request::create('/', 'GET', [], [], [], [
                'HTTP_USER_AGENT' => $userAgent,
            ]));

            $this->assertSame($expectedName, $info['name'], $userAgent);
            $this->assertSame($expectedVersion, $info['version'], $userAgent);
        }
    }

    public function test_wrapper_app_build_versions_still_run_capability_filter(): void
    {
        $this->bindProtocolManager([
            TestClientFilteredProtocol::class,
        ]);

        $filter = new TestClientCapabilityFilter();
        app()->instance('protocols.capabilities', $filter);

        $controller = new ClientController();
        $request = Request::create('/', 'GET', ['flag' => 'Hiddify/1.2.8.1103']);
        $user = [
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1024,
            'expired_at' => 0,
        ];

        $response = $controller->doSubscribe($request, $user, [[
            'type' => 'anytls',
            'name' => 'AnyTLS TCP',
            'protocol_settings' => [
                'tls_mode' => 1,
                'tls' => ['server_name' => 'anytls.example.com'],
            ],
        ]]);

        $this->assertTrue($filter->called);
        $this->assertSame('hiddify', $filter->clientName);
        $this->assertSame('1.2.8.1103', $filter->clientVersion);
        $this->assertSame(['AnyTLS TCP'], json_decode($response->getContent(), true)['names']);
    }

    private function bindProtocolManager(array $classes): void
    {
        $manager = new ProtocolManager(new Container());

        $reflection = new \ReflectionProperty(ProtocolManager::class, 'protocolClasses');
        $reflection->setAccessible(true);
        $reflection->setValue($manager, $classes);

        app()->instance('protocols.manager', $manager);
    }
}

final class TestClientCapabilityFilter
{
    public bool $called = false;
    public ?string $clientName = null;
    public ?string $clientVersion = null;

    public function filterServersForClient(array $servers, ?string $clientName, ?string $clientVersion): array
    {
        $this->called = true;
        $this->clientName = $clientName;
        $this->clientVersion = $clientVersion;

        return $servers;
    }
}

final class TestClientFilteredProtocol extends \App\Support\AbstractProtocol
{
    public $flags = ['hiddify'];

    public function handle()
    {
        return response()->json([
            'names' => array_column($this->servers, 'name'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Client\ClientController;
use App\Protocols\ClashMeta;
use App\Protocols\QuantumultX;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Support\ProtocolCapabilityService;
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
        ]);

        $controller = new ClientController();
        $method = new \ReflectionMethod(ClientController::class, 'getClientInfo');
        $method->setAccessible(true);

        $singBox = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'singbox 1.12.0']));
        $singBoxWrapper = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'sing-box/1.2.8.1103']));
        $clashMeta = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'ClashX Meta/1.3.5']));
        $mihomo = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'mihomo/1.19.0']));
        $verge = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'Clash Verge/v1.7.0']));
        $hiddify = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'Hiddify/1.2.8.1103']));
        $sparkle = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'Sparkle/1.2.8.1103']));

        $this->assertSame('sing-box', $singBox['name']);
        $this->assertSame('1.12.0', $singBox['version']);
        $this->assertSame('sing-box', $singBoxWrapper['name']);
        $this->assertSame('1.2.8.1103', $singBoxWrapper['version']);

        $this->assertSame('clashx meta', $clashMeta['name']);
        $this->assertSame('1.3.5', $clashMeta['version']);
        $this->assertSame('mihomo', $mihomo['name']);
        $this->assertSame('1.19.0', $mihomo['version']);

        $this->assertSame('verge', $verge['name']);
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
        ]);

        $controller = new ClientController();
        $method = new \ReflectionMethod(ClientController::class, 'getClientInfo');
        $method->setAccessible(true);

        $cases = [
            ['sing-box/1.13.11', 'sing-box', '1.13.11'],
            ['Karing/1.2.8.1103', 'karing', '1.2.8.1103'],
            ['Hiddify/1.2.8.1103', 'hiddify', '1.2.8.1103'],
            ['Sparkle/1.2.8.1103', 'sparkle', '1.2.8.1103'],
            ['mihomo/1.19.0', 'mihomo', '1.19.0'],
            ['Clash Verge/v1.7.0', 'verge', '1.7.0'],
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

    public function test_sing_box_wrapper_app_build_versions_bypass_core_semver_filter(): void
    {
        app()->instance('protocols.capabilities', new ProtocolCapabilityService(
            require dirname(__DIR__, 3) . '/config/protocol_capabilities.php'
        ));

        $controller = new ClientController();
        $method = new \ReflectionMethod(ClientController::class, 'shouldBypassClientCapabilityFilter');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($controller, [
            'name' => 'sing-box',
            'version' => '1.2.8.1103',
        ]));
        $this->assertTrue($method->invoke($controller, [
            'name' => 'karing',
            'version' => '1.2.8.1103',
        ]));
        $this->assertTrue($method->invoke($controller, [
            'name' => 'hiddify',
            'version' => '1.2.8.1103',
        ]));
        $this->assertFalse($method->invoke($controller, [
            'name' => 'sparkle',
            'version' => '1.2.8.1103',
        ]));
        $this->assertFalse($method->invoke($controller, [
            'name' => 'sing-box',
            'version' => '1.13.11',
        ]));
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

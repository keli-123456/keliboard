<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Client\ClientController;
use App\Protocols\ClashMeta;
use App\Protocols\QuantumultX;
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
        ]);

        $controller = new ClientController();
        $method = new \ReflectionMethod(ClientController::class, 'getClientInfo');
        $method->setAccessible(true);

        $singBox = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'singbox 1.12.0']));
        $clashMeta = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'ClashX Meta/1.3.5']));
        $verge = $method->invoke($controller, Request::create('/', 'GET', ['flag' => 'Clash Verge/v1.7.0']));

        $this->assertSame('sing-box', $singBox['name']);
        $this->assertSame('1.12.0', $singBox['version']);

        $this->assertSame('clashx meta', $clashMeta['name']);
        $this->assertSame('1.3.5', $clashMeta['version']);

        $this->assertSame('verge', $verge['name']);
        $this->assertSame('1.7.0', $verge['version']);
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

    private function bindProtocolManager(array $classes): void
    {
        $manager = new ProtocolManager(new Container());

        $reflection = new \ReflectionProperty(ProtocolManager::class, 'protocolClasses');
        $reflection->setAccessible(true);
        $reflection->setValue($manager, $classes);

        app()->instance('protocols.manager', $manager);
    }
}

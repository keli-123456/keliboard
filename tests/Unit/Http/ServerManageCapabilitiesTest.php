<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Support\ProtocolCapabilityService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ServerManageCapabilitiesTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindJsonResponseFactory();
    }

    public function test_default_capabilities_include_sidecar_protocol_types(): void
    {
        $controller = new ManageController();
        $capabilities = new ProtocolCapabilityService(require dirname(__DIR__, 3) . '/config/protocol_capabilities.php');

        $response = $controller->getCapabilities(
            Request::create('/api/v2/admin/server/manage/getCapabilities'),
            $capabilities
        );

        $payload = $response->getData(true);
        $types = array_keys($payload['data']['types'] ?? []);

        $this->assertContains(Server::TYPE_NAIVE, $types);
        $this->assertContains(Server::TYPE_MIERU, $types);
        $this->assertSame([0, 1], $payload['data']['types'][Server::TYPE_NAIVE]['enums']['tls'] ?? null);
        $this->assertSame(['tcp', 'udp'], $payload['data']['types'][Server::TYPE_MIERU]['enums']['transport'] ?? null);
        $this->assertSame(
            ['MULTIPLEXING_OFF', 'MULTIPLEXING_LOW', 'MULTIPLEXING_MIDDLE', 'MULTIPLEXING_HIGH'],
            $payload['data']['types'][Server::TYPE_MIERU]['enums']['multiplexing'] ?? null
        );
    }
}

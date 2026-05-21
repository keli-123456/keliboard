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
        $this->assertSame(1, $payload['data']['types'][Server::TYPE_NAIVE]['defaults']['tls'] ?? null);
        $this->assertSame('tcp', $payload['data']['types'][Server::TYPE_NAIVE]['defaults']['network'] ?? null);
        $this->assertSame(['tcp', 'udp'], $payload['data']['types'][Server::TYPE_MIERU]['enums']['transport'] ?? null);
        $this->assertSame(
            ['MULTIPLEXING_OFF', 'MULTIPLEXING_LOW', 'MULTIPLEXING_MIDDLE', 'MULTIPLEXING_HIGH'],
            $payload['data']['types'][Server::TYPE_MIERU]['enums']['multiplexing'] ?? null
        );
    }

    public function test_capabilities_include_single_protocol_presets_for_sidecar_types(): void
    {
        $controller = new ManageController();
        $capabilities = new ProtocolCapabilityService(require dirname(__DIR__, 3) . '/config/protocol_capabilities.php');

        $response = $controller->getCapabilities(
            Request::create('/api/v2/admin/server/manage/getCapabilities'),
            $capabilities
        );

        $payload = $response->getData(true);
        $types = $payload['data']['types'] ?? [];

        $expectedPresetIds = [
            Server::TYPE_SOCKS => ['socks_plain', 'socks_tls'],
            Server::TYPE_HTTP => ['http_plain', 'http_tls'],
            Server::TYPE_NAIVE => ['naive_https', 'naive_quic'],
            Server::TYPE_MIERU => ['mieru_tcp_low', 'mieru_udp_low'],
        ];

        foreach ($expectedPresetIds as $type => $presetIds) {
            $actualIds = array_column($types[$type]['presets'] ?? [], 'id');

            foreach ($presetIds as $presetId) {
                $this->assertContains($presetId, $actualIds, "{$type} preset {$presetId} should be exposed");
            }
        }

        $mieruPresets = collect($types[Server::TYPE_MIERU]['presets'] ?? [])->keyBy('id');
        $this->assertTrue((bool) data_get($mieruPresets->get('mieru_tcp_low'), 'runtime_support.v2node.supported'));
        $this->assertFalse((bool) data_get($mieruPresets->get('mieru_udp_low'), 'runtime_support.v2node.supported'));
    }
}

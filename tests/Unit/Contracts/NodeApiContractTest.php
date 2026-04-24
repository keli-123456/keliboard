<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Contracts\NodeApiContract;
use Tests\TestCase;

final class NodeApiContractTest extends TestCase
{
    public function test_contract_file_matches_php_constants(): void
    {
        $contract = $this->loadContract();

        $this->assertSame(NodeApiContract::VERSION, $contract['version']);
        $this->assertSame('/api/v1/' . NodeApiContract::v1UniProxyPath(NodeApiContract::ENDPOINT_CONFIG), $contract['paths']['v1.uniproxy.config']['path']);
        $this->assertSame('/api/v1/' . NodeApiContract::v1UniProxyPath(NodeApiContract::ENDPOINT_USER), $contract['paths']['v1.uniproxy.user']['path']);
        $this->assertSame('/api/v1/' . NodeApiContract::v1UniProxyPath(NodeApiContract::ENDPOINT_USER_DELTA), $contract['paths']['v1.uniproxy.user_delta']['path']);
        $this->assertSame('/api/v1/' . NodeApiContract::v1UniProxyPath(NodeApiContract::ENDPOINT_PUSH), $contract['paths']['v1.uniproxy.push']['path']);
        $this->assertSame('/api/v1/' . NodeApiContract::v1UniProxyPath(NodeApiContract::ENDPOINT_ALIVE), $contract['paths']['v1.uniproxy.alive']['path']);
        $this->assertSame('/api/v1/' . NodeApiContract::v1UniProxyPath(NodeApiContract::ENDPOINT_ALIVE_LIST), $contract['paths']['v1.uniproxy.alivelist']['path']);
        $this->assertSame('/api/v1/' . NodeApiContract::v1UniProxyPath(NodeApiContract::ENDPOINT_STATUS), $contract['paths']['v1.uniproxy.status']['path']);
        $this->assertSame('/api/v2/' . NodeApiContract::v2ServerPath(NodeApiContract::ENDPOINT_CONFIG), $contract['paths']['v2.server.config']['path']);
        $this->assertSame('/api/v2/' . NodeApiContract::v2ServerPath(NodeApiContract::ENDPOINT_HANDSHAKE), $contract['paths']['v2.server.handshake']['path']);
        $this->assertSame('/api/v2/' . NodeApiContract::v2ServerPath(NodeApiContract::ENDPOINT_REPORT), $contract['paths']['v2.server.report']['path']);

        $this->assertSame(NodeApiContract::HEADER_RESPONSE_FORMAT, $contract['headers']['response_format']);
        $this->assertSame(NodeApiContract::RESPONSE_FORMAT_MSGPACK, $contract['headers']['msgpack']);
        $this->assertSame(NodeApiContract::CONTENT_TYPE_MSGPACK, $contract['headers']['msgpack_content_type']);
    }

    public function test_node_user_schema_keeps_required_runtime_fields(): void
    {
        $contract = $this->loadContract();

        $this->assertSame(
            ['id', 'uuid', 'speed_limit', 'device_limit'],
            $contract['schemas']['UserInfo']['required']
        );
    }

    private function loadContract(): array
    {
        $path = dirname(__DIR__, 3) . '/contracts/node-api/node-api.json';
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\WsServer;
use ReflectionMethod;
use Tests\TestCase;

final class WsServerTargetTest extends TestCase
{
    public function test_machine_target_matches_authenticated_machine_connection(): void
    {
        $connection = (object) [
            'xboard_server_id' => 10,
            'xboard_machine_id' => 7,
            'xboard_group_ids' => [2],
        ];

        $this->assertTrue($this->shouldDeliver($connection, [
            'targets' => ['machine_ids' => [7]],
        ]));
        $this->assertFalse($this->shouldDeliver($connection, [
            'targets' => ['machine_ids' => [8]],
        ]));
    }

    public function test_existing_server_and_group_targets_still_work(): void
    {
        $connection = (object) [
            'xboard_server_id' => 10,
            'xboard_machine_id' => 7,
            'xboard_group_ids' => [2, 3],
        ];

        $this->assertTrue($this->shouldDeliver($connection, [
            'targets' => ['server_ids' => [10]],
        ]));
        $this->assertTrue($this->shouldDeliver($connection, [
            'targets' => ['group_ids' => [3]],
        ]));
        $this->assertFalse($this->shouldDeliver($connection, [
            'targets' => ['server_ids' => [11], 'group_ids' => [4], 'machine_ids' => [8]],
        ]));
    }

    public function test_empty_or_missing_targets_are_broadcast(): void
    {
        $connection = (object) [
            'xboard_server_id' => 10,
            'xboard_machine_id' => 7,
            'xboard_group_ids' => [2],
        ];

        $this->assertTrue($this->shouldDeliver($connection, null));
        $this->assertTrue($this->shouldDeliver($connection, []));
        $this->assertTrue($this->shouldDeliver($connection, ['targets' => []]));
    }

    public function test_it_prefers_trusted_proxy_headers_for_realtime_remote_ip(): void
    {
        $connection = new WsServerTestConnection('127.0.0.1');
        $request = new WsServerTestRequest([
            'cf-connecting-ip' => '203.0.113.18',
            'x-forwarded-for' => '198.51.100.20, 127.0.0.1',
        ]);

        $method = new ReflectionMethod(WsServer::class, 'resolveHandshakeRemoteIp');
        $method->setAccessible(true);

        $this->assertSame('203.0.113.18', $method->invoke(new WsServer(), $connection, $request));
    }

    public function test_it_rate_limits_duplicate_authentication_failures(): void
    {
        $method = new ReflectionMethod(WsServer::class, 'shouldLogAuthFailure');
        $method->setAccessible(true);
        $command = new WsServer();
        $context = ['remote_ip' => '203.0.113.18', 'node_id' => 37, 'node_type' => 'v2node'];

        $this->assertTrue($method->invoke($command, $context));
        $this->assertFalse($method->invoke($command, $context));
    }

    private function shouldDeliver(object $connection, ?array $message): bool
    {
        $method = new ReflectionMethod(WsServer::class, 'shouldDeliverMessage');
        $method->setAccessible(true);

        return (bool) $method->invoke(new WsServer(), $connection, $message);
    }
}

final class WsServerTestConnection
{
    public function __construct(private readonly string $remoteIp)
    {
    }

    public function getRemoteIp(): string
    {
        return $this->remoteIp;
    }
}

final class WsServerTestRequest
{
    public function __construct(private readonly array $headers)
    {
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[$name] ?? $default;
    }
}

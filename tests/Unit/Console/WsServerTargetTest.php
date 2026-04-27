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

    private function shouldDeliver(object $connection, ?array $message): bool
    {
        $method = new ReflectionMethod(WsServer::class, 'shouldDeliverMessage');
        $method->setAccessible(true);

        return (bool) $method->invoke(new WsServer(), $connection, $message);
    }
}

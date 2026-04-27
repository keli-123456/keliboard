<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use ReflectionMethod;
use Tests\TestCase;

final class NodeRealtimePublisherTest extends TestCase
{
    public function test_build_targets_includes_machine_ids_without_breaking_existing_targets(): void
    {
        $method = new ReflectionMethod(NodeRealtimePublisher::class, 'buildTargets');
        $method->setAccessible(true);

        $targets = $method->invoke(new NodeRealtimePublisher(new NodeRealtimeSettings()), [3, '2', 2], [9], [7, 'bad', 7]);

        $this->assertSame([
            'server_ids' => [2, 3],
            'group_ids' => [9],
            'machine_ids' => [7],
        ], $targets);
    }

    public function test_build_targets_returns_null_for_empty_target_lists(): void
    {
        $method = new ReflectionMethod(NodeRealtimePublisher::class, 'buildTargets');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(new NodeRealtimePublisher(new NodeRealtimeSettings())));
    }
}

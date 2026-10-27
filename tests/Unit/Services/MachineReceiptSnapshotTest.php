<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NodeRealtime\NodeRealtimeSettings;
use App\Services\NodeRealtime\NodeRealtimeStatusService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MachineReceiptSnapshotTest extends TestCase
{
    public function test_receipts_are_filtered_per_node_and_topic_without_free_text_or_database_scans(): void
    {
        File::shouldReceive('exists')->once()->andReturn(true);
        File::shouldReceive('get')->once()->andReturn(json_encode(['receipts' => [
            ['server_id' => 10, 'topic' => 'config', 'status' => 'received', 'event_id' => 'old', 'updated_at' => '2026-10-16T00:00:00+00:00'],
            ['server_id' => 10, 'topic' => 'config', 'status' => 'applied', 'event_id' => 'new', 'updated_at' => '2026-10-16T00:00:01+00:00', 'message' => 'secret', 'reason' => 'private'],
            ['server_id' => 10, 'topic' => 'config', 'status' => 'received', 'event_id' => 'older-other-zone', 'updated_at' => '2026-10-16T08:00:00+08:00'],
            ['server_id' => 10, 'topic' => 'users', 'status' => 'failed', 'event_id' => 'users', 'updated_at' => '2026-10-16T00:00:02+00:00'],
            ['server_id' => 11, 'topic' => 'config', 'status' => 'applied', 'updated_at' => '2026-10-16T00:00:02+00:00'],
            ['server_id' => 10, 'topic' => 'unsupported', 'status' => 'applied'],
            ['server_id' => 0, 'topic' => 'config', 'status' => 'applied'],
            null,
        ]]));
        $result = (new NodeRealtimeStatusService(new NodeRealtimeSettings()))->receiptsForServers([10]);
        $this->assertSame([10], array_keys($result));
        $this->assertSame('new', $result[10]['config']['event_id']);
        $this->assertSame('failed', $result[10]['users']['status']);
        $this->assertEqualsCanonicalizing(['status', 'event_id', 'event_ts', 'updated_at'], array_keys($result[10]['config']));
        $this->assertSame(0, $result[10]['config']['event_ts']);
    }

    public function test_no_nodes_does_not_read_the_snapshot(): void
    {
        File::shouldReceive('exists')->never();
        $this->assertSame([], (new NodeRealtimeStatusService(new NodeRealtimeSettings()))->receiptsForServers([]));
    }

    public function test_unavailable_snapshot_is_missing_not_success(): void
    {
        File::shouldReceive('exists')->once()->andReturn(false);
        $this->assertSame([], (new NodeRealtimeStatusService(new NodeRealtimeSettings()))->receiptsForServers([10]));
    }

    public function test_machine_insight_retains_manual_restart_required_without_free_text(): void
    {
        $method = new \ReflectionMethod(\App\Services\ServerMachine\MachineInsightService::class, 'receipt');
        $receipt = $method->invoke(new \App\Services\ServerMachine\MachineInsightService(), [
            'status' => 'restart_required', 'event_id' => 'exact-config-event', 'message' => 'private runtime text',
            'updated_at' => '2026-10-27T00:00:00Z',
        ]);
        $this->assertSame('restart_required', $receipt['status']);
        $this->assertSame('exact-config-event', $receipt['event_id']);
        $this->assertArrayNotHasKey('message', $receipt);
    }
}

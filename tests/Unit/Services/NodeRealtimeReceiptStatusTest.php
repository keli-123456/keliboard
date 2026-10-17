<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NodeRealtime\NodeRealtimeStatusService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class NodeRealtimeReceiptStatusTest extends TestCase
{
    public function test_machine_insight_retains_event_time_but_does_not_expose_free_text(): void
    {
        File::shouldReceive('exists')->once()->andReturn(true);
        File::shouldReceive('get')->once()->andReturn(json_encode(['receipts' => [
            ['server_id' => 69, 'node_id' => '69', 'topic' => 'config', 'event_id' => 'request',
                'event_ts' => 100, 'status' => 'restart_required', 'message' => 'not-public', 'updated_at' => '2026-10-17T12:00:00Z'],
            ['server_id' => 70, 'node_id' => '70', 'topic' => 'config', 'event_id' => 'other',
                'event_ts' => 101, 'status' => 'applied', 'updated_at' => '2026-10-17T12:01:00Z'],
        ]], JSON_THROW_ON_ERROR));
        $rows = app(NodeRealtimeStatusService::class)->receiptsForServers([69]);
        $this->assertSame([69], array_keys($rows));
        $this->assertSame(100, $rows[69]['config']['event_ts']);
        $this->assertSame('restart_required', $rows[69]['config']['status']);
        $this->assertArrayNotHasKey('message', $rows[69]['config']);
    }
}

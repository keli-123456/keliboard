<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NodeRealtime\NodeRealtimeReceipt;
use PHPUnit\Framework\TestCase;

final class NodeRealtimeReceiptTest extends TestCase
{
    public function test_same_event_progresses_from_received_to_a_final_outcome(): void
    {
        $received = ['event_id' => 'a', 'event_ts' => 100, 'status' => 'received'];
        foreach (['applied', 'failed', 'restart_required'] as $status) {
            $final = [...$received, 'status' => $status];
            $this->assertTrue(NodeRealtimeReceipt::shouldReplace($received, $final));
            $this->assertFalse(NodeRealtimeReceipt::shouldReplace($final, $received));
            $this->assertFalse(NodeRealtimeReceipt::shouldReplace($final, [...$received, 'status' => 'queued']));
            $this->assertFalse(NodeRealtimeReceipt::shouldReplace($final, $final));
        }
    }

    public function test_delayed_old_results_do_not_confirm_a_newer_configuration(): void
    {
        $new = ['event_id' => 'new', 'event_ts' => 101, 'status' => 'received'];
        $old = ['event_id' => 'old', 'event_ts' => 100, 'status' => 'applied'];
        $this->assertFalse(NodeRealtimeReceipt::shouldReplace($new, $old));
        $this->assertFalse(NodeRealtimeReceipt::shouldReplace($new, [...$old, 'status' => 'received']));
        $this->assertFalse(NodeRealtimeReceipt::shouldReplace($new, [...$old, 'event_ts' => 101]));
        $this->assertTrue(NodeRealtimeReceipt::shouldReplace($old, $new));
    }

    public function test_legacy_receipts_remain_accepted_without_inventing_an_apply_result(): void
    {
        $legacy = ['event_id' => 'legacy', 'status' => 'queued'];
        $this->assertTrue(NodeRealtimeReceipt::shouldReplace(null, $legacy));
        $this->assertTrue(NodeRealtimeReceipt::shouldReplace($legacy, [...$legacy, 'status' => 'applied']));
        $this->assertTrue(NodeRealtimeReceipt::shouldReplace($legacy, ['event_id' => 'new', 'status' => 'received']));
    }
}

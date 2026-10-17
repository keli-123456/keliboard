<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\WsServer;
use ReflectionMethod;
use Tests\TestCase;

final class WsServerReceiptTest extends TestCase
{
    public function test_receipts_use_authenticated_identity_and_preserve_final_state_across_snapshot_reload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'node-receipt-test-');
        $connection = (object) ['xboard_authenticated' => true, 'xboard_server_id' => 69,
            'xboard_input_node_id' => '69', 'xboard_node_type' => 'v2node'];
        $command = new WsServer();
        $record = new ReflectionMethod($command, 'recordReceipt');
        $load = new ReflectionMethod($command, 'loadReceiptState');
        $state = [];
        $received = ['topic' => 'config', 'event_id' => 'one', 'event_ts' => 100,
            'status' => 'received', 'server_id' => 999];
        try {
            $record->invokeArgs($command, [&$state, $path, $connection, $received]);
            $record->invokeArgs($command, [&$state, $path, $connection, [...$received, 'status' => 'restart_required']]);
            $state = $load->invoke($command, $path);
            $record->invokeArgs($command, [&$state, $path, $connection, [...$received, 'status' => 'queued']]);
            $this->assertSame(69, $state['69:config']['server_id']);
            $this->assertSame(100, $state['69:config']['event_ts']);
            $this->assertSame('restart_required', $state['69:config']['status']);
            $record->invokeArgs($command, [&$state, $path, $connection,
                [...$received, 'event_id' => 'two', 'event_ts' => 101, 'status' => 'applied']]);
            $this->assertSame('applied', $load->invoke($command, $path)['69:config']['status']);
        } finally {
            @unlink($path);
        }
    }

    public function test_unauthenticated_receipts_cannot_create_or_replace_status(): void
    {
        $command = new WsServer();
        $state = [];
        (new ReflectionMethod($command, 'recordReceipt'))->invokeArgs($command, [
            &$state, 'unused', (object) ['xboard_authenticated' => false, 'xboard_server_id' => 69],
            ['topic' => 'config', 'event_id' => 'fake', 'status' => 'applied'],
        ]);
        $this->assertSame([], $state);
    }
}

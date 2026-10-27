<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\ConfigHistoryController;
use App\Http\Middleware\RequestLog;
use App\Models\Server;
use App\Models\ServerConfigRevision;
use App\Services\Node\ServerConfigHistoryService;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\NodeRealtime\NodeRealtimeStatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ServerConfigHistoryTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private ServerConfigHistoryService $service;
    private array $published = [];
    private array $receipts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        app()->instance('encrypter', new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id();
            foreach (['type', 'runtime', 'code', 'name', 'host', 'port'] as $field) $table->string($field)->nullable();
            foreach (['group_ids', 'route_ids', 'tags', 'protocol_settings', 'rate_time_ranges'] as $field) $table->json($field)->nullable();
            foreach (['server_port', 'parent_id', 'machine_id'] as $field) $table->integer($field)->nullable();
            $table->float('rate')->default(1);
            $table->boolean('show')->default(true);
            $table->boolean('enabled')->default(true);
            $table->boolean('rate_time_enable')->default(false);
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_27_000001_create_server_config_revisions.php'))->up();
        $publisher = $this->createMock(NodeRealtimePublisher::class);
        $publisher->method('invalidateConfigForServers')->willReturnCallback(function ($ids, $reason, $payload): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->published[] = [$ids, $reason, $payload];
        });
        app()->instance(NodeRealtimePublisher::class, $publisher);
        $status = $this->createMock(NodeRealtimeStatusService::class);
        $status->method('receiptsForServers')->willReturnCallback(fn () => $this->receipts);
        app()->instance(NodeRealtimeStatusService::class, $status);
        $this->service = new ServerConfigHistoryService();
    }

    private function node(): Server
    {
        return Server::create(['type' => 'hysteria', 'runtime' => 'v2node', 'name' => 'HY2 Test',
            'host' => 'test.example.com', 'port' => '443', 'server_port' => 443, 'group_ids' => [1], 'route_ids' => [],
            'tags' => [], 'rate' => 1, 'protocol_settings' => ['version' => 2,
                'tls' => ['server_name' => 'test.example.com', 'allow_insecure' => false],
                'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'private-password'],
                'congestion_control' => 'bbr']])->fresh();
    }

    public function test_preview_is_read_only_redacted_and_uses_model_casts(): void
    {
        $server = $this->node();
        $settings = $server->protocol_settings;
        $settings['obfs']['password'] = 'new-private-password';
        $settings['congestion_control'] = 'brutal';
        $preview = $this->service->preview($server, ['port' => 443, 'rate' => '1.00', 'protocol_settings' => $settings]);
        $this->assertCount(2, $preview['changes']);
        $this->assertSame('restart_possible', $preview['impact']);
        $this->assertStringNotContainsString('private-password', json_encode($preview));
        $this->assertSame(0, ServerConfigRevision::count());
        $this->assertSame('bbr', $server->fresh()->protocol_settings['congestion_control']);
        $this->assertSame([], $this->published);
    }

    public function test_save_records_baseline_and_encrypted_snapshot_and_publishes_exact_event_after_commit(): void
    {
        $server = $this->node();
        $preview = $this->service->preview($server, ['name' => 'Renamed']);
        $this->service->persist($server->id, ['name' => 'Renamed', 'expected_config_hash' => $preview['base_hash'],
            'expected_draft_hash' => $preview['draft_hash']], 9);
        $this->assertSame('Renamed', $server->fresh()->name);
        $rows = ServerConfigRevision::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('baseline', $rows[0]->action);
        $this->assertSame(9, $rows[1]->actor_id);
        $raw = DB::table('v2_server_config_revision')->latest('id')->first();
        $this->assertStringNotContainsString('private-password', json_encode($raw));
        $this->assertStringContainsString('private-password', Crypt::decryptString($raw->snapshot));
        $this->assertSame($raw->event_id, $this->published[0][2]['event_id']);
        $this->assertSame('unconfirmed', $this->service->history($server->fresh(), 1)['items'][0]['receipt_status']);
    }

    public function test_stale_or_changed_review_cannot_overwrite_saved_configuration(): void
    {
        foreach (['base', 'draft'] as $conflict) {
            $server = $this->node();
            $preview = $this->service->preview($server, ['name' => 'Reviewed']);
            if ($conflict === 'base') $server->update(['name' => 'Other admin']);
            try {
                $this->service->persist($server->id, ['name' => $conflict === 'draft' ? 'Unreviewed' : 'Reviewed',
                    'expected_config_hash' => $preview['base_hash'], 'expected_draft_hash' => $preview['draft_hash']], 1);
                $this->fail('Expected a conflict');
            } catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
            $this->assertNotSame('Unreviewed', $server->fresh()->name);
        }
        $this->assertSame(0, ServerConfigRevision::count());
        $this->assertSame([], $this->published);
    }

    public function test_no_op_does_not_create_history_or_notify_node(): void
    {
        $server = $this->node();
        $this->service->persist($server->id, $this->service->snapshot($server), 1);
        $this->assertSame(0, ServerConfigRevision::count());
        $this->assertSame([], $this->published);
    }

    public function test_only_exact_matching_receipt_confirms_a_revision_and_keeps_restart_required(): void
    {
        $server = $this->node();
        $this->service->persist($server->id, ['name' => 'Next'], 1);
        foreach (['wrong' => 'unconfirmed', $this->published[0][2]['event_id'] => 'restart_required'] as $event => $expected) {
            $this->receipts = [$server->id => ['config' => ['event_id' => $event, 'status' => 'restart_required', 'updated_at' => '2026-10-27T00:00:00Z']]];
            $history = $this->service->history($server->fresh(), 1);
            $this->assertSame($expected, $history['items'][0]['receipt_status']);
            $this->assertArrayNotHasKey('snapshot', $history['items'][0]);
        }
    }

    public function test_restore_creates_new_revision_preserves_history_and_revalidates_snapshot(): void
    {
        $server = $this->node();
        $this->service->persist($server->id, ['name' => 'Next'], 2);
        $baseline = ServerConfigRevision::oldest('id')->first();
        $payload = $this->service->restorePayload($server, $baseline->id);
        $server->refresh();
        $preview = $this->service->preview($server, $payload);
        $this->service->persist($server->id, [...$payload, 'expected_config_hash' => $preview['base_hash'],
            'expected_draft_hash' => $preview['draft_hash']], 3, $baseline->id);
        $this->assertSame('HY2 Test', $server->fresh()->name);
        $this->assertSame(3, ServerConfigRevision::count());
        $last = ServerConfigRevision::latest('id')->first();
        $this->assertSame('restore', $last->action);
        $this->assertSame($baseline->id, $last->source_revision_id);
    }

    public function test_history_cannot_be_restored_to_another_node(): void
    {
        $server = $this->node();
        $this->service->persist($server->id, ['name' => 'Next'], 1);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->restorePayload($this->node(), ServerConfigRevision::oldest('id')->value('id'));
    }

    public function test_corrupt_ciphertext_is_rejected_without_exposing_content(): void
    {
        $server = $this->node();
        $this->service->persist($server->id, ['name' => 'Next'], 1);
        $row = ServerConfigRevision::first();
        DB::table('v2_server_config_revision')->where('id', $row->id)->update(['snapshot' => 'invalid-private-material']);
        try { $this->service->restorePayload($server, $row->id); $this->fail('Expected rejection'); }
        catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringNotContainsString('invalid-private-material', $e->getMessage());
        }
        $this->assertSame('Next', $server->fresh()->name);
    }

    public function test_restoring_a_now_invalid_protocol_configuration_is_rejected(): void
    {
        $server = $this->node();
        $this->service->persist($server->id, ['name' => 'Next'], 1);
        $row = ServerConfigRevision::first();
        $payload = $this->service->snapshot($server);
        $payload['protocol_settings']['version'] = 1;
        DB::table('v2_server_config_revision')->where('id', $row->id)->update([
            'snapshot' => Crypt::encryptString(json_encode($payload)),
        ]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->restorePayload($server, $row->id);
    }

    public function test_unavailable_history_storage_blocks_save_instead_of_silently_losing_audit(): void
    {
        $server = $this->node();
        Schema::drop('v2_server_config_revision');
        try { $this->service->persist($server->id, ['name' => 'Not saved'], 1); $this->fail('Expected migration requirement'); }
        catch (HttpException $e) { $this->assertSame(503, $e->getStatusCode()); }
        $this->assertSame('HY2 Test', $server->fresh()->name);
    }

    public function test_history_write_failure_rolls_back_node_change_and_does_not_publish(): void
    {
        $server = $this->node();
        Crypt::shouldReceive('encryptString')->andThrow(new \RuntimeException('Encryption unavailable'));
        try { $this->service->persist($server->id, ['name' => 'Must rollback'], 1); $this->fail('Expected failure'); }
        catch (\RuntimeException) { $this->assertSame('HY2 Test', $server->fresh()->name); }
        $this->assertSame(0, ServerConfigRevision::count());
        $this->assertSame([], $this->published);
    }

    public function test_automatic_ech_runtime_identity_is_not_replayed_or_diffed(): void
    {
        $server = $this->node();
        $settings = $server->protocol_settings;
        $settings['ech'] = ['enabled' => true, 'mode' => 'automatic', 'request_id' => str_repeat('a', 32),
            'state' => 'ready', 'config' => 'public-generated-config', 'key_file' => '/etc/node/key.pem'];
        $server->update(['protocol_settings' => $settings]);
        $snapshot = $this->service->snapshot($server);
        $this->assertSame(['enabled' => true, 'mode' => 'automatic'], $snapshot['protocol_settings']['ech']);
        $hash = $this->service->hash($snapshot);
        $settings['ech']['state'] = 'prepared';
        $server->update(['protocol_settings' => $settings]);
        $this->assertSame($hash, $this->service->hash($this->service->snapshot($server)));
    }

    public function test_history_paginates_and_does_not_serialize_secrets(): void
    {
        $server = $this->node();
        for ($i = 1; $i <= 17; $i++) $this->service->persist($server->id, ['name' => "Revision $i"], 1);
        $history = $this->service->history($server->fresh(), 1);
        $this->assertCount(15, $history['items']);
        $this->assertSame(18, $history['total']);
        $this->assertCount(3, $this->service->history($server->fresh(), 2)['items']);
        $this->assertStringNotContainsString('private-password', json_encode($history));
    }

    public function test_preview_and_save_audit_payloads_do_not_include_protocol_material(): void
    {
        Schema::create('v2_admin_audit_log', function (Blueprint $table): void {
            $table->id(); $table->integer('admin_id');
            foreach (['action', 'method', 'uri', 'request_data', 'ip'] as $field) $table->text($field)->nullable();
            $table->integer('created_at'); $table->integer('updated_at');
        });
        foreach (['save', 'config-preview'] as $action) {
            $request = Request::create("/api/v2/admin/server/manage/$action", 'POST', ['protocol_settings' => ['tls_settings' => ['dns_env' => ['DNS_AUTH' => 'do-not-log']]]]);
            $request->setUserResolver(fn () => (object) ['id' => 1, 'is_admin' => true]);
            (new RequestLog())->handle($request, fn () => response()->json(['status' => 'success']));
        }
        $rows = DB::table('v2_admin_audit_log')->get();
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString('do-not-log', $rows->toJson());
        $this->assertStringContainsString('REDACTED', $rows->toJson());
    }
}

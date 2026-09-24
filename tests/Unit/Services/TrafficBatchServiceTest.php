<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Server\TrafficBatchController;
use App\Jobs\TrafficBatchApplyJob;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TrafficBatchAccounting;
use App\Services\TrafficBatchPayload;
use App\Services\TrafficBatchService;
use App\Services\UserSyncService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TrafficBatchServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings();
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->string('type');
            $table->decimal('rate', 8, 2);
            $table->boolean('rate_time_enable')->default(false);
            $table->text('rate_time_ranges')->nullable();
        });
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->integer('t')->nullable();
        });
        foreach (['v2_stat_user', 'v2_stat_server', 'v2_stat_user_node_day'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->bigIncrements('id');
                $key = [];
                if ($name !== 'v2_stat_server') {
                    $table->integer('user_id');
                    $key[] = 'user_id';
                }
                if ($name !== 'v2_stat_user') {
                    $table->integer('server_id');
                    $key[] = 'server_id';
                    $table->string('server_type');
                    if ($name === 'v2_stat_server') {
                        $key[] = 'server_type';
                    } else {
                        $table->string('server_name');
                    }
                }
                if ($name !== 'v2_stat_server') {
                    $table->decimal('server_rate', 10, 2);
                    $key[] = 'server_rate';
                }
                $table->bigInteger('u');
                $table->bigInteger('d');
                $table->integer('record_at');
                $table->string('record_type');
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique([...$key, 'record_at', 'record_type']);
            });
        }
        (require base_path('database/migrations/2026_09_20_000001_create_traffic_batch_receipts.php'))->up();
        DB::table('v2_server')->insert([
            ['id' => 1, 'name' => 'test-one', 'type' => 'hysteria', 'rate' => 1.5],
            ['id' => 2, 'name' => 'test-two', 'type' => 'vless', 'rate' => 1.5],
        ]);
        DB::table('v2_user')->insert([['id' => 7], ['id' => 9]]);
    }

    public function test_duplicate_request_and_serialized_queue_retry_apply_all_four_views_once(): void
    {
        $service = new TrafficBatchService();
        $first = $this->accept();
        $retry = $this->accept();
        $this->assertSame($first->id, $retry->id);
        $this->assertNull($first->processed_at);
        $this->assertSame(0, (int) DB::table('v2_user')->sum('u'));
        $job = unserialize(serialize(new TrafficBatchApplyJob((int) $first->id)));
        $job->handle();
        $job->handle();
        $this->assertTotals(15, 30, 10, 20);
        $receipt = $this->accept();
        $this->assertNotNull($receipt->processed_at);
        $this->assertNotNull($receipt->sync_finished_at);
        $this->assertNull($receipt->payload);
        $this->assertSame(1, DB::table('v2_traffic_batch')->count());
    }

    public function test_equal_new_delta_and_same_id_on_another_node_are_not_deduplicated(): void
    {
        foreach ([[$this->id(), 1], [str_repeat('b', 32), 1], [$this->id(), 2]] as [$id, $node]) {
            $receipt = $this->accept($id, [7 => [10, 20]], $node);
            (new TrafficBatchService())->process((int) $receipt->id);
        }
        $this->assertTotals(45, 90, 30, 60);
        $this->assertSame(3, DB::table('v2_traffic_batch')->count());
    }

    public function test_completion_serialization_failure_retries_only_the_marker(): void
    {
        $receipt = $this->accept();
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$attempts): void {
            if (str_starts_with($sql, 'update "v2_traffic_batch" set "sync_finished_at"')) {
                $this->assertSame(1, $connection->transactionLevel());
                if (++$attempts === 1) {
                    throw new \PDOException('could not serialize access due to concurrent update', 40001);
                }
            }
        });
        (new TrafficBatchService())->process((int) $receipt->id);
        $this->assertSame(2, $attempts);
        $this->assertTotals(15, 30, 10, 20);
        $this->assertNotNull($this->accept()->sync_finished_at);
        $this->assertNull($this->accept()->payload);
    }

    public function test_failed_completion_keeps_payload_and_later_retry_does_not_rebill(): void
    {
        $receipt = $this->accept();
        DB::unprepared("CREATE TRIGGER fail_completion BEFORE UPDATE OF sync_finished_at ON v2_traffic_batch BEGIN SELECT RAISE(ABORT, 'injected completion failure'); END");
        try {
            (new TrafficBatchService())->process((int) $receipt->id);
            $this->fail('Expected completion failure.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('injected completion failure', $error->getMessage());
        }
        $this->assertTotals(15, 30, 10, 20);
        $this->assertNotNull($this->accept()->processed_at);
        $this->assertNull($this->accept()->sync_finished_at);
        $this->assertNotNull($this->accept()->payload);
        DB::unprepared('DROP TRIGGER fail_completion');
        (new TrafficBatchService())->process((int) $receipt->id);
        $this->assertTotals(15, 30, 10, 20);
        $this->assertNotNull($this->accept()->sync_finished_at);
        $this->assertNull($this->accept()->payload);
    }

    public function test_stale_completion_does_not_overwrite_another_workers_marker(): void
    {
        $receipt = $this->accept();
        $interleaved = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$interleaved, $receipt): void {
            if (!$interleaved && str_starts_with($sql, 'update "v2_traffic_batch" set "sync_finished_at"')) {
                $interleaved = true;
                DB::table('v2_traffic_batch')->where('id', $receipt->id)
                    ->update(['sync_finished_at' => 123, 'payload' => null]);
            }
        });
        (new TrafficBatchService())->process((int) $receipt->id);
        $this->assertTrue($interleaved);
        $this->assertSame(123, (int) $this->accept()->sync_finished_at);
        $this->assertTotals(15, 30, 10, 20);
    }

    public function test_id_conflict_preserves_original_payload_before_and_after_processing(): void
    {
        $receipt = $this->accept();
        foreach ([false, true] as $processed) {
            if ($processed) {
                (new TrafficBatchService())->process((int) $receipt->id);
            }
            try {
                $this->accept(null, [7 => [11, 20]]);
                $this->fail('Changed payload must conflict.');
            } catch (ConflictHttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->assertTotals(15, 30, 10, 20);
    }

    public function test_rate_day_and_filters_are_frozen_at_first_acceptance(): void
    {
        $calls = 0;
        HookManager::registerFilter('traffic.process.before', function (array $data) use (&$calls): array {
            $calls++;
            $data[2][7][0] = 12;
            return $data;
        });
        $receipt = $this->accept();
        $prepared = json_decode($receipt->payload, true, 512, JSON_THROW_ON_ERROR);
        DB::table('v2_server')->where('id', 1)->update(['rate' => 9, 'name' => 'changed']);
        $this->accept();
        (new TrafficBatchService())->process((int) $receipt->id);
        $this->assertSame(1, $calls);
        $this->assertTotals(18, 30, 12, 20);
        $stat = DB::table('v2_stat_user_node_day')->first();
        $this->assertSame('test-one', $stat->server_name);
        $this->assertEquals(1.5, $stat->server_rate);
        $this->assertSame($prepared['record_at'], (int) $stat->record_at);
    }

    public function test_late_statistics_failure_rolls_back_quota_and_all_earlier_statistics(): void
    {
        $receipt = $this->accept();
        DB::unprepared("CREATE TRIGGER fail_server_stat BEFORE INSERT ON v2_stat_server BEGIN SELECT RAISE(ABORT, 'injected statistics failure'); END");
        try {
            (new TrafficBatchService())->process((int) $receipt->id);
            $this->fail('Expected the last statistics write to fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('injected statistics failure', $error->getMessage());
        }
        $this->assertTotals(0, 0, 0, 0);
        $this->assertNull($this->accept()->processed_at);
        DB::unprepared('DROP TRIGGER fail_server_stat');
        (new TrafficBatchService())->process((int) $receipt->id);
        $this->assertTotals(15, 30, 10, 20);
    }

    public function test_receipt_commit_failure_rolls_back_every_increment(): void
    {
        $receipt = $this->accept();
        DB::unprepared("CREATE TRIGGER fail_receipt BEFORE UPDATE OF processed_at ON v2_traffic_batch BEGIN SELECT RAISE(ABORT, 'injected receipt failure'); END");
        try {
            (new TrafficBatchService())->process((int) $receipt->id);
            $this->fail('Expected the receipt write to fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('injected receipt failure', $error->getMessage());
        }
        $this->assertTotals(0, 0, 0, 0);
        DB::unprepared('DROP TRIGGER fail_receipt');
        (new TrafficBatchService())->process((int) $receipt->id);
        $this->assertTotals(15, 30, 10, 20);
    }

    public function test_post_commit_sync_failure_retries_without_repeating_billing(): void
    {
        Schema::create('user_sync_states', fn (Blueprint $table) => $table->integer('user_id')->primary());
        $sync = new class extends UserSyncService {
            public int $calls = 0;
            public function syncUser(User $user, string $reason = 'user_sync'): void
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('injected quota sync failure');
                }
            }
        };
        app()->instance(UserSyncService::class, $sync);
        $receipt = $this->accept();
        try {
            (new TrafficBatchService())->process((int) $receipt->id);
            $this->fail('Expected post-commit sync failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected quota sync failure', $error->getMessage());
        }
        $this->assertTotals(15, 30, 10, 20);
        $this->assertNotNull($this->accept()->processed_at);
        $this->assertNull($this->accept()->sync_finished_at);
        (new TrafficBatchService())->process((int) $receipt->id);
        $this->assertTotals(15, 30, 10, 20);
        $this->assertSame(2, $sync->calls);
        $this->assertNotNull($this->accept()->sync_finished_at);
    }

    public function test_lost_queue_dispatch_is_recovered_from_database(): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('queue unavailable'));
        app()->instance(Dispatcher::class, $bus);
        $service = new TrafficBatchService();
        $service->dispatch($this->accept());
        $this->assertTotals(0, 0, 0, 0);
        $this->assertNotNull($this->accept()->payload);
        $this->assertGreaterThan(time(), $this->accept()->retry_at);
        // Advance the persisted dispatch lease to simulate the later scheduler tick.
        DB::table('v2_traffic_batch')->update(['retry_at' => 0]);
        $this->bindSynchronousBusDispatcher();
        $this->assertSame(1, $service->recover(200));
        $this->assertSame(0, $service->recover(200));
        $this->assertTotals(15, 30, 10, 20);
    }

    public function test_real_quota_snapshot_and_delta_event_commit_once_after_accounting(): void
    {
        Schema::table('v2_user', function (Blueprint $table): void {
            $table->string('uuid')->default('fixture-user');
            $table->integer('group_id')->default(10);
            $table->integer('plan_id')->default(20);
            $table->bigInteger('transfer_enable')->default(40);
            $table->integer('expired_at')->nullable();
            $table->boolean('banned')->default(false);
            $table->integer('speed_limit')->default(0);
            $table->integer('device_limit')->default(0);
        });
        (require base_path('database/migrations/2025_12_30_000001_create_user_sync_tables.php'))->up();
        DB::table('user_sync_states')->insert([
            'user_id' => 7, 'group_id' => 10, 'uuid' => 'fixture-user', 'available' => 1,
        ]);
        DB::connection()->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
        $publisher = $this->createMock(\App\Services\NodeRealtime\NodeRealtimePublisher::class);
        $publisher->expects($this->once())->method('invalidateUsersForGroups')->with(
            [10], 'user.delta', $this->callback(fn (array $payload): bool => $payload['revision'] > 0)
        );
        app()->instance(\App\Services\NodeRealtime\NodeRealtimePublisher::class, $publisher);
        $receipt = $this->accept();
        (new TrafficBatchService())->process((int) $receipt->id);
        (new TrafficBatchService())->process((int) $receipt->id);
        $this->assertSame(0, (int) DB::table('user_sync_states')->where('user_id', 7)->value('available'));
        $this->assertSame(1, DB::table('user_sync_events')->count());
        $this->assertTotals(15, 30, 10, 20);
    }

    public function test_boundary_batch_of_one_thousand_users_has_exact_totals(): void
    {
        $traffic = [];
        foreach (array_chunk(range(100, 1099), 200) as $ids) {
            DB::table('v2_user')->insert(array_map(fn (int $id): array => ['id' => $id], $ids));
            foreach ($ids as $id) { $traffic[$id] = [7, 11]; }
        }
        $started = hrtime(true);
        $receipt = $this->accept(null, $traffic);
        (new TrafficBatchService())->process((int) $receipt->id);
        $elapsed = (hrtime(true) - $started) / 1000000;
        $this->assertTotals(11000, 17000, 7000, 11000);
        $this->assertSame(1000, DB::table('v2_stat_user')->count());
        fwrite(STDOUT, 'TRAFFIC_BATCH_1000_USERS ' . json_encode(['elapsed_ms' => round($elapsed, 2), 'users' => 1000]) . PHP_EOL);
        $traffic[1100] = [7, 11];
        $this->expectException(\InvalidArgumentException::class);
        $this->accept(str_repeat('b', 32), $traffic);
    }

    public function test_canonical_order_and_byte_validation_do_not_silently_drop_data(): void
    {
        $this->assertSame(
            TrafficBatchPayload::contentHash(TrafficBatchPayload::normalize([9 => [1, 2], 7 => [3, 4]])),
            TrafficBatchPayload::contentHash(TrafficBatchPayload::normalize([7 => [3, 4], 9 => [1, 2]]))
        );
        foreach ([[], [0 => [1, 2]], ['07' => [1, 2]], [7 => ['1', 2]], [7 => [true, 2]],
            [7 => [0, 0]], [7 => [1, 2, 3]], [7 => [PHP_INT_MAX, 0], 9 => [1, 0]]] as $invalid) {
            try {
                TrafficBatchPayload::normalize($invalid);
                $this->fail('Invalid traffic must not be silently filtered.');
            } catch (\InvalidArgumentException | \OverflowException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
    }

    public function test_corrupt_snapshot_is_not_marked_applied_or_deleted(): void
    {
        $receipt = $this->accept();
        DB::table('v2_traffic_batch')->where('id', $receipt->id)->update(['payload' => '{}']);
        try {
            (new TrafficBatchService())->process((int) $receipt->id);
            $this->fail('Corrupt payload must fail closed.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('checksum', $error->getMessage());
        }
        $this->assertTotals(0, 0, 0, 0);
        $this->assertNull($this->accept()->processed_at);
        $this->assertSame('{}', $this->accept()->payload);
    }

    public function test_poison_receipt_does_not_starve_later_pending_work(): void
    {
        $poison = $this->accept();
        $good = $this->accept(str_repeat('b', 32));
        DB::table('v2_traffic_batch')->where('id', $poison->id)->update(['payload' => '{}']);
        $this->bindSynchronousBusDispatcher();
        $service = new TrafficBatchService();
        $this->assertSame(0, $service->recover(1));
        $this->assertSame(1, $service->recover(1));
        $this->assertNotNull(DB::table('v2_traffic_batch')->where('id', $good->id)->value('processed_at'));
        $this->assertNull(DB::table('v2_traffic_batch')->where('id', $poison->id)->value('processed_at'));
        $this->assertTotals(15, 30, 10, 20);
    }

    public function test_existing_counter_overflow_does_not_wrap_or_partially_apply(): void
    {
        $receipt = $this->accept(null, [7 => [10, 20], 9 => [10, 20]]);
        DB::table('v2_user')->where('id', 9)->update(['u' => PHP_INT_MAX]);
        try {
            (new TrafficBatchService())->process((int) $receipt->id);
            $this->fail('Overflow must preserve the batch.');
        } catch (\OverflowException $error) {
            $this->assertStringContainsString('64-bit', $error->getMessage());
        }
        $this->assertSame(0, (int) DB::table('v2_user')->where('id', 7)->value('u'));
        $this->assertSame(0, DB::table('v2_stat_user')->count());
        $this->assertNull(DB::table('v2_traffic_batch')->value('processed_at'));
    }

    public function test_http_receipt_distinguishes_pending_and_applied_even_with_middleware_fields(): void
    {
        $this->bindSynchronousBusDispatcher();
        $controller = new TrafficBatchController();
        $service = new TrafficBatchService();
        $request = $this->request(['version' => 1, 'report_id' => $this->id(), 'traffic' => [7 => [10, 20]]]);
        $request->merge(['node_type' => 'hysteria']);
        $response = $controller->report($request, $service);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('pending', $response->getData(true)['status']);
        $response = $controller->report($request, $service);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('applied', $response->getData(true)['status']);
        $this->assertSame(TrafficBatchPayload::contentHash([7 => [10, 20]]), $response->getData(true)['content_hash']);
        $this->assertSame(1, $response->getData(true)['node_id']);
        $this->assertTotals(15, 30, 10, 20);
    }

    public function test_capability_has_exact_authenticated_identity_and_is_not_cacheable(): void
    {
        $response = (new TrafficBatchController())->capability($this->request([]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'version' => 1, 'node_id' => 1, 'node_type' => 'hysteria',
            'max_users' => 1000, 'max_body_bytes' => 262144, 'durable_receipts' => true,
        ], $response->getData(true));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame(0, DB::table('v2_traffic_batch')->count());
    }

    public function test_invalid_http_batches_are_rejected_without_a_receipt(): void
    {
        foreach ([
            ['version' => 2, 'report_id' => $this->id(), 'traffic' => [7 => [1, 2]]],
            ['version' => 1, 'report_id' => $this->id(), 'traffic' => [7 => [-1, 2]]],
            ['version' => 1, 'report_id' => $this->id(), 'traffic' => [7 => [1.5, 2]]],
            ['version' => 1, 'report_id' => 'bad', 'traffic' => [7 => [1, 2]]],
            ['version' => 1, 'report_id' => $this->id(), 'traffic' => [7 => [1, 2]], 'rate' => 0],
        ] as $body) {
            $response = (new TrafficBatchController())->report($this->request($body), new TrafficBatchService());
            $this->assertSame(422, $response->getStatusCode());
        }
        $this->assertSame(0, DB::table('v2_traffic_batch')->count());
    }

    public function test_integer_scaling_is_exact_above_float_precision_and_rounds_positive_halves_up(): void
    {
        $this->assertSame(PHP_INT_MAX, TrafficBatchPayload::scale(PHP_INT_MAX, 100));
        $this->assertSame(9007199254740993, TrafficBatchPayload::scale(9007199254740993, 100));
        $this->assertSame(2, TrafficBatchPayload::scale(1, 150));
        $this->assertSame(1, TrafficBatchPayload::scale(1, 50));
        $this->assertSame(0, TrafficBatchPayload::scale(PHP_INT_MAX, 0));
        $this->expectException(\OverflowException::class);
        TrafficBatchPayload::scale(PHP_INT_MAX, 101);
    }

    public function test_rate_validation_does_not_silently_round_tiny_or_fractional_rates_to_zero(): void
    {
        foreach ([0 => '0.00', 115 => '1.15', 99999999 => '999999.99'] as $cents => $value) {
            $this->assertSame($cents, TrafficBatchPayload::rate($value));
        }
        foreach ([0.000000001, 1.234, -1, INF, NAN, 'invalid'] as $value) {
            try {
                TrafficBatchPayload::rate($value);
                $this->fail('Unsupported rate must fail instead of changing billing.');
            } catch (\InvalidArgumentException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
    }

    public function test_migration_down_refuses_to_discard_dedup_tombstones(): void
    {
        $this->accept();
        try {
            (require base_path('database/migrations/2026_09_20_000001_create_traffic_batch_receipts.php'))->down();
            $this->fail('Unsafe rollback should be refused.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('settled-data', $error->getMessage());
        }
        $this->assertSame(1, DB::table('v2_traffic_batch')->count());
    }

    public function test_real_process_death_before_and_after_commit_has_correct_replay(): void
    {
        $this->accept();
        $directory = $this->fileDatabase();
        try {
            foreach (['before_commit', 'after_commit'] as $action) {
                $marker = $directory . '/' . $action;
                [$process, $pipes] = $this->worker($directory, $action, $marker);
                try {
                    $this->waitForMarker($process, $pipes, $marker);
                    $this->assertTrue(proc_get_status($process)['running']);
                    $this->assertTrue(proc_terminate($process, 9));
                } finally {
                    foreach ($pipes as $pipe) { fclose($pipe); }
                    $exit = proc_close($process);
                }
                $this->assertNotSame(0, $exit, 'Fixture must die before its normal exit.');
                DB::purge('default');
                $row = DB::table('v2_traffic_batch')->first();
                if ($action === 'before_commit') {
                    $this->assertNull($row->processed_at);
                    $this->assertTotals(0, 0, 0, 0);
                } else {
                    $this->assertNotNull($row->processed_at);
                    $this->assertTotals(15, 30, 10, 20);
                }
            }
            (new TrafficBatchService())->process(1);
            $this->assertTotals(15, 30, 10, 20);
        } finally {
            $this->removeFileDatabase($directory);
        }
    }

    public function test_real_concurrent_accept_and_accounting_workers_keep_one_receipt_and_one_increment(): void
    {
        $directory = $this->fileDatabase();
        try {
            foreach (['accept', 'process'] as $action) {
                $workers = [];
                try {
                    foreach ([1, 2] as $index) {
                        $marker = $directory . '/' . $action . '-' . $index;
                        $workers[] = [...$this->worker($directory, $action, $marker), $marker];
                    }
                    foreach ($workers as [$process, $pipes, $marker]) {
                        $this->waitForMarker($process, $pipes, $marker);
                    }
                    file_put_contents($directory . '/go', 'start', LOCK_EX);
                    foreach ($workers as [$process, $pipes, $marker]) {
                        $until = microtime(true) + 15;
                        do {
                            $status = proc_get_status($process);
                            if (!$status['running']) { break; }
                            usleep(10000);
                        } while (microtime(true) < $until);
                        $detail = (file_get_contents($marker . '.trace') ?: '') . (file_get_contents($marker . '.stderr') ?: '');
                        $this->assertFalse($status['running'], 'Worker exceeded deadline: ' . $detail);
                        $this->assertSame(0, $status['exitcode'], $detail);
                    }
                } finally {
                    foreach ($workers as [$process, $pipes]) {
                        if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
                        foreach ($pipes as $pipe) { fclose($pipe); }
                        proc_close($process);
                    }
                    if (file_exists($directory . '/go')) { unlink($directory . '/go'); }
                }
                DB::purge('default');
                $this->assertSame(1, DB::table('v2_traffic_batch')->count());
            }
            $this->assertTotals(15, 30, 10, 20);
        } finally {
            $this->removeFileDatabase($directory);
        }
    }

    private function fileDatabase(): string
    {
        $directory = sys_get_temp_dir() . '/keli-traffic-batch-proc-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/fixture.sqlite';
        DB::statement('VACUUM INTO ?', [$path]);
        config(['database.connections.default.database' => $path]);
        DB::purge('default');
        DB::statement('PRAGMA busy_timeout = 5000');
        DB::statement('PRAGMA synchronous = FULL');
        return $directory;
    }

    private function worker(string $directory, string $action, string $marker): array
    {
        $process = proc_open([
            PHP_BINARY, base_path('tests/Fixtures/traffic_batch_worker.php'),
            $directory . '/fixture.sqlite', $action, $marker, $directory . '/go',
        ], [0 => ['pipe', 'r'], 1 => ['file', $marker . '.stdout', 'w'], 2 => ['file', $marker . '.stderr', 'w']], $pipes);
        $this->assertIsResource($process);
        foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
        return [$process, $pipes];
    }

    private function waitForMarker($process, array $pipes, string $marker): void
    {
        $until = microtime(true) + 10;
        do {
            clearstatcache();
            if (file_exists($marker)) { return; }
            // Windows anonymous pipes can block despite stream_set_blocking(false).
            if (!proc_get_status($process)['running']) {
                $this->fail('Worker exited before barrier: ' . file_get_contents($marker . '.stderr'));
            }
            usleep(10000);
        } while (microtime(true) < $until);
        $this->fail('Worker did not reach its barrier before the deadline.');
    }

    private function removeFileDatabase(string $directory): void
    {
        DB::purge('default');
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }

    private function request(array $body): Request
    {
        $request = Request::create('/api/v2/server/traffic/batch', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $request->attributes->set('node_info', Server::findOrFail(1));
        return $request;
    }

    private function id(): string
    {
        return str_repeat('a', 32);
    }

    private function accept(?string $id = null, array $traffic = [7 => [10, 20]], int $node = 1): object
    {
        return (new TrafficBatchService())->accept(Server::findOrFail($node), $id ?? $this->id(), $traffic);
    }

    private function assertTotals(int $u, int $d, int $rawU, int $rawD): void
    {
        foreach (['v2_user', 'v2_stat_user', 'v2_stat_user_node_day'] as $table) {
            $this->assertSame($u, (int) DB::table($table)->sum('u'), $table . '.u');
            $this->assertSame($d, (int) DB::table($table)->sum('d'), $table . '.d');
        }
        $this->assertSame($rawU, (int) DB::table('v2_stat_server')->sum('u'));
        $this->assertSame($rawD, (int) DB::table('v2_stat_server')->sum('d'));
    }
}

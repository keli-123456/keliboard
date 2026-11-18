<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\StatUserNodeDayJob;
use App\Services\StatBatchWriter;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class StatBatchJobsTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private const SERVER = ['id' => 30, 'name' => 'test-node', 'type' => 'vless', 'rate' => 1.5];
    private const RECORD = 1783840261;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();

        foreach (['v2_stat_user', 'v2_stat_user_node_day', 'v2_stat_server'] as $name) {
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
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unsignedInteger('record_at');
                $table->char('record_type', 1);
                $table->unique([...$key, 'record_at', 'record_type']);
            });
        }

        $this->migration()->up();
    }

    public static function jobTypes(): array
    {
        return [
            'user' => [StatUserJob::class, 'v2_stat_user', true],
            'node day' => [StatUserNodeDayJob::class, 'v2_stat_user_node_day', true],
            'server' => [StatServerJob::class, 'v2_stat_server', false],
        ];
    }

    public static function multiRowJobTypes(): array
    {
        return [
            'user' => [StatUserJob::class, 'v2_stat_user'],
            'node day' => [StatUserNodeDayJob::class, 'v2_stat_user_node_day'],
        ];
    }

    #[DataProvider('jobTypes')]
    public function test_redelivery_after_commit_is_a_noop_but_different_equal_reports_count(
        string $type,
        string $table,
        bool $weighted
    ): void {
        $data = [9 => [12, 20], 3 => [10, 6]];
        $job = new $type(self::SERVER, $data, 'vless', 'd', self::RECORD);
        $serialized = serialize($job);

        unserialize($serialized)->handle();
        // A second worker receives the original serialized job after the first commit.
        unserialize($serialized)->handle();
        $this->assertTotals($table, $weighted ? 2 : 1, $weighted ? 33 : 22, $weighted ? 39 : 26);
        $this->assertSame(1, DB::table(StatBatchWriter::RECEIPTS)->count());

        (new $type(self::SERVER, $data, 'vless', 'd', self::RECORD))->handle();
        $this->assertTotals($table, $weighted ? 2 : 1, $weighted ? 66 : 44, $weighted ? 78 : 52);
        $this->assertSame(2, DB::table(StatBatchWriter::RECEIPTS)->count());
    }

    #[DataProvider('multiRowJobTypes')]
    public function test_second_statement_deadlock_rolls_back_and_replays_every_increment_once(string $type, string $table): void
    {
        $starts = [];
        $this->beforeStatInsert($table, function (int $first) use (&$starts, $table): void {
            $starts[] = $first;
            if ($starts === [1, 101]) {
                $this->assertSame(100, DB::table($table)->count(), 'The first chunk already ran in the current transaction.');
                throw $this->deadlock();
            }
        });

        $job = new $type(self::SERVER, $this->reverseBatch(), 'vless', 'd', self::RECORD);
        $job->handle();

        $this->assertSame([1, 101, 1, 101, 201], $starts);
        $this->assertTotals($table, 201, 201 * 15, 201 * 30);
        $this->assertSame(1, DB::table(StatBatchWriter::RECEIPTS)->count());
        unserialize(serialize($job))->handle();
        $this->assertTotals($table, 201, 201 * 15, 201 * 30);
        $this->assertSame([1, 101, 1, 101, 201], $starts);
    }

    #[DataProvider('multiRowJobTypes')]
    public function test_nonretryable_later_failure_leaves_no_partial_write_or_receipt(string $type, string $table): void
    {
        (new $type(self::SERVER, [1 => [2, 4]], 'vless', 'd', self::RECORD))->handle();
        $starts = [];
        $this->beforeStatInsert($table, function (int $first) use (&$starts): void {
            $starts[] = $first;
            if ($starts === [1, 101]) {
                throw new PDOException('SQL error with fixture-private binding', 42000);
            }
        });

        $job = new $type(self::SERVER, $this->reverseBatch(), 'vless', 'd', self::RECORD);
        try {
            $job->handle();
            $this->fail('Expected the nonretryable database error.');
        } catch (RuntimeException $error) {
            $this->assertSame('Statistics batch write failed (SQLSTATE 42000).', $error->getMessage());
        }

        $this->assertSame([1, 101], $starts, 'A database error must not fall back to individual writes.');
        $this->assertTotals($table, 1, 3, 6);
        $this->assertSame(1, DB::table(StatBatchWriter::RECEIPTS)->count());
        $this->assertSame(0, DB::transactionLevel());

        $job = unserialize(serialize($job));
        $job->handle();
        $job->handle();
        $this->assertTotals($table, 201, 201 * 15 + 3, 201 * 30 + 6);
        $this->assertSame(2, DB::table(StatBatchWriter::RECEIPTS)->count());
    }

    public function test_local_retry_exhaustion_can_be_recovered_by_a_later_queue_delivery(): void
    {
        $failures = 0;
        $this->beforeStatInsert('v2_stat_user_node_day', function (int $first) use (&$failures): void {
            if ($first === 101 && $failures < 3) {
                $failures++;
                throw $this->deadlock();
            }
        });
        $job = new StatUserNodeDayJob(self::SERVER, $this->reverseBatch(), 'vless', 'd', self::RECORD);

        try {
            $job->handle();
            $this->fail('Expected the bounded local retries to be exhausted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Statistics batch write failed (SQLSTATE 40001).', $error->getMessage());
        }
        $this->assertSame(3, $failures);
        $this->assertTotals('v2_stat_user_node_day', 0, 0, 0);
        $this->assertSame(0, DB::table(StatBatchWriter::RECEIPTS)->count());

        unserialize(serialize($job))->handle();
        $this->assertTotals('v2_stat_user_node_day', 201, 201 * 15, 201 * 30);
    }

    public function test_pre_upgrade_payload_uses_queue_uuid_and_original_enqueue_date(): void
    {
        $legacy = new StatUserJob(self::SERVER, [11 => [10, 20]], 'vless');
        (new ReflectionProperty($legacy, 'statDeliveryId'))->setValue($legacy, null);
        (new ReflectionProperty($legacy, 'recordAt'))->setValue($legacy, null);
        $queued = serialize($legacy);

        for ($i = 0; $i < 2; $i++) {
            $job = unserialize($queued);
            $job->setJob($this->wireJob('caa1903a-6d35-44c2-8b8a-92db046bed63'));
            $job->handle();
        }
        $this->assertTotals('v2_stat_user', 1, 15, 30);
        $this->assertSame((int) strtotime(date('Y-m-d', self::RECORD)), (int) DB::table('v2_stat_user')->value('record_at'));
    }

    public function test_reused_receipt_with_different_content_fails_closed(): void
    {
        $id = 'caa1903a-6d35-44c2-8b8a-92db046bed63';
        $job = new StatUserJob(self::SERVER, [11 => [10, 20]], 'vless');
        (new ReflectionProperty($job, 'statDeliveryId'))->setValue($job, $id);
        $job->handle();

        $changed = new StatUserJob(self::SERVER, [11 => [20, 40]], 'vless');
        (new ReflectionProperty($changed, 'statDeliveryId'))->setValue($changed, $id);
        try {
            $changed->handle();
            $this->fail('Expected conflicting contents for the same receipt to be rejected.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('conflicts', $error->getMessage());
        }

        $this->assertTotals('v2_stat_user', 1, 15, 30);
        $this->assertSame(1, DB::table(StatBatchWriter::RECEIPTS)->count());
    }

    public function test_duplicate_normalized_keys_are_combined_in_one_upsert(): void
    {
        $job = new StatUserJob(self::SERVER, [1 => [10, 20], '01' => [2, 4]], 'vless', 'd', self::RECORD);
        $job->handle();
        $this->assertTotals('v2_stat_user', 1, 18, 36);
    }

    public function test_same_receipt_tolerates_new_write_timestamps_and_reordered_rows(): void
    {
        $id = 'caa1903a-6d35-44c2-8b8a-92db046bed63';
        $row = ['server_rate' => 1.5, 'record_at' => 1783814400, 'record_type' => 'd',
            'u' => 15, 'd' => 30, 'created_at' => 10, 'updated_at' => 10];
        $writer = new StatBatchWriter();
        $writer->write('v2_stat_user', $id, [['user_id' => 1] + $row, ['user_id' => 2] + $row]);
        $row['created_at'] = $row['updated_at'] = 20;
        $writer->write('v2_stat_user', $id, [['user_id' => 2] + $row, ['user_id' => 1] + $row]);

        $this->assertTotals('v2_stat_user', 2, 30, 60);
        $this->assertSame(10, (int) DB::table('v2_stat_user')->max('updated_at'));
        $this->assertSame(1, DB::table(StatBatchWriter::RECEIPTS)->count());
    }

    public function test_migration_refuses_a_legacy_statistics_key_without_record_type(): void
    {
        $this->migration()->down();
        Schema::table('v2_stat_user', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'server_rate', 'record_at', 'record_type']);
            $table->unique(['user_id', 'server_rate', 'record_at']);
        });
        try {
            $this->migration()->up();
            $this->fail('Expected the legacy unique index to block the receipt migration.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('current statistics unique indexes', $error->getMessage());
        }
        $this->assertFalse(Schema::hasTable(StatBatchWriter::RECEIPTS));
    }

    public function test_nonempty_receipt_table_cannot_be_dropped_by_migration_rollback(): void
    {
        (new StatServerJob(self::SERVER, [11 => [10, 20]], 'vless', 'd', self::RECORD))->handle();
        $this->expectExceptionMessage('Statistics receipts must be retained');
        $this->migration()->down();
    }

    public function test_grammar_keeps_additive_updates_and_current_keys_across_databases(): void
    {
        $noPdo = fn () => throw new RuntimeException('SQL compilation must not connect to a database.');
        $connections = [
            [new MySqlConnection($noPdo, '', '', ['driver' => 'mysql']), 'values'],
            [new MySqlConnection($noPdo, '', '', ['driver' => 'mysql', 'use_upsert_alias' => true]), 'alias'],
            [new MariaDbConnection($noPdo, '', '', ['driver' => 'mariadb']), 'values'],
            [new PostgresConnection($noPdo, '', '', ['driver' => 'pgsql']), 'excluded'],
        ];
        $updates = new ReflectionMethod(StatBatchWriter::class, 'additiveUpdates');
        $writer = new StatBatchWriter();
        $table = 'v2_stat_user';
        $key = ['user_id', 'server_rate', 'record_at', 'record_type'];
        foreach ($connections as [$db, $dialect]) {
            $sql = $db->getQueryGrammar()->compileUpsert($db->table($table), [
                ['user_id' => 1, 'server_rate' => 1, 'record_at' => 1, 'record_type' => 'd', 'u' => 2, 'd' => 3, 'updated_at' => 4],
            ], $key, $updates->invoke($writer, $db, $table));

            if ($dialect === 'excluded') {
                $this->assertStringContainsString('on conflict ("user_id", "server_rate", "record_at", "record_type")', $sql);
                $this->assertStringContainsString('"u" = "v2_stat_user"."u" + excluded."u"', $sql);
            } elseif ($dialect === 'alias') {
                $this->assertStringContainsString('as laravel_upsert_alias on duplicate key update', $sql);
                $this->assertStringContainsString('`u` = `v2_stat_user`.`u` + `laravel_upsert_alias`.`u`', $sql);
            } else {
                $this->assertStringContainsString('`u` = `v2_stat_user`.`u` + values(`u`)', $sql);
            }
        }
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_19_000001_create_stat_job_receipts.php');
    }

    private function reverseBatch(): array
    {
        return array_fill_keys(range(201, 1), [10, 20]);
    }

    private function deadlock(): PDOException
    {
        return new PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001);
    }

    private function wireJob(string $id): SyncJob
    {
        $wire = $this->createMock(SyncJob::class);
        $wire->method('uuid')->willReturn($id);
        $wire->method('payload')->willReturn(['createdAt' => self::RECORD]);

        return $wire;
    }

    private function beforeStatInsert(string $table, callable $check): void
    {
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($table, $check): void {
            if (preg_match('/^insert into "' . $table . '" \(([^)]+)\) values /i', $sql, $match) !== 1) {
                return;
            }
            $columns = array_map(fn (string $part) => trim($part, '" '), explode(',', $match[1]));
            $check((int) $bindings[array_search('user_id', $columns, true)]);
        });
    }

    private function assertTotals(string $table, int $count, int $u, int $d): void
    {
        $this->assertSame($count, DB::table($table)->count());
        $this->assertSame($u, (int) DB::table($table)->sum('u'));
        $this->assertSame($d, (int) DB::table($table)->sum('d'));
    }
}

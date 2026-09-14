<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\StatUserNodeDayJob;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class StatUserNodeDayJobTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'mysql');
        config(['database.default' => 'mysql']);
        DB::connection()->getSchemaBuilder()->create('retry_stat_totals', function (Blueprint $table): void {
            $table->integer('user_id')->primary();
            $table->bigInteger('u');
            $table->bigInteger('d');
        });

        $app = Container::getInstance();
        $app->instance('log', new NullLogger());
        Facade::setFacadeApplication($app);
    }

    public function test_it_retries_a_mysql_deadlock_before_marking_daily_statistics_as_failed(): void
    {
        config(['database.default' => 'mysql']);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning')->with(
            'StatUserNodeDayJob retrying MySQL deadlock', $this->isType('array')
        );
        $logger->expects($this->once())->method('info')->with(
            'StatUserNodeDayJob recovered after MySQL deadlock',
            ['server_id' => 10, 'attempt' => 3, 'row_count' => 1]
        );
        $logger->expects($this->never())->method('error');
        app()->instance('log', $logger);
        $job = new DeadlockRetryStatUserNodeDayJob();

        $exception = null;
        try {
            $job->handle();
        } catch (RuntimeException $error) {
            $exception = $error;
        }

        $this->assertNull($exception);
        $this->assertSame(3, $job->attempts);
    }

    public function test_it_does_not_retry_non_deadlock_database_errors(): void
    {
        config(['database.default' => 'mysql']);
        $job = new NonRetryableStatUserNodeDayJob();

        try {
            $job->handle();
            $this->fail('Expected the non-deadlock database error to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('syntax error', $exception->getMessage());
        }

        $this->assertSame(1, $job->attempts);
    }

    public function test_it_removes_the_sql_statement_from_database_error_logs(): void
    {
        $job = new DeadlockRetryStatUserNodeDayJob();
        $summary = $job->summarize(new RuntimeException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found (Connection: mysql, SQL: INSERT INTO secret_table VALUES (1))'
        ));

        $this->assertSame(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found',
            $summary
        );
        $this->assertStringNotContainsString('secret_table', $summary);
    }

    public function test_later_batch_failure_rolls_back_earlier_increments_before_queue_retry(): void
    {
        DB::table('retry_stat_totals')->insert(['user_id' => 1, 'u' => 7, 'd' => 9]);
        $job = new BatchFailureStatUserNodeDayJob(1, 'syntax error');
        try {
            $job->handle();
            $this->fail('Expected the second batch to fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('syntax error', $error->getMessage());
        }
        $this->assertSame(1, DB::table('retry_stat_totals')->count());
        $this->assertEquals(7, DB::table('retry_stat_totals')->sum('u'));
        $this->assertEquals(9, DB::table('retry_stat_totals')->sum('d'));

        $job->handle();
        $this->assertSame(201, DB::table('retry_stat_totals')->count());
        $this->assertEquals(2010 + 7, DB::table('retry_stat_totals')->sum('u'));
        $this->assertEquals(4020 + 9, DB::table('retry_stat_totals')->sum('d'));
    }

    public function test_later_batch_deadlock_replays_the_whole_transaction_once(): void
    {
        $job = new BatchFailureStatUserNodeDayJob(1, 'SQLSTATE[40001]: 1213 Deadlock found');
        $job->handle();

        $this->assertSame([1, 101, 1, 101, 201], $job->batchStarts);
        $this->assertSame(201, DB::table('retry_stat_totals')->count());
        $this->assertEquals(2010, DB::table('retry_stat_totals')->sum('u'));
        $this->assertEquals(4020, DB::table('retry_stat_totals')->sum('d'));
    }

    public function test_exhausted_deadlock_retries_leave_no_partial_statistics(): void
    {
        $job = new BatchFailureStatUserNodeDayJob(5, 'SQLSTATE[40001]: 1213 Deadlock found');
        try {
            $job->handle();
            $this->fail('Expected all five transaction attempts to fail.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Deadlock', $error->getMessage());
        }
        $this->assertSame(0, DB::table('retry_stat_totals')->count());
        $this->assertSame(0, DB::transactionLevel());
        $job->handle();
        $this->assertEquals(2010, DB::table('retry_stat_totals')->sum('u'));
    }
}

final class BatchFailureStatUserNodeDayJob extends StatUserNodeDayJob
{
    public array $batchStarts = [];

    public function __construct(private int $failuresRemaining, private string $failureMessage)
    {
        $data = [];
        foreach (range(201, 1) as $id) {
            $data[$id] = [10, 20];
        }
        parent::__construct(['id' => 10, 'rate' => 1], $data, 'vless', 'd', 1783840261);
    }

    protected function upsertRowsForMySqlLike(array $rows): void
    {
        // Use real SQLite transactions while exercising the MySQL retry/batch path.
        $firstId = $rows[0]['user_id'];
        $this->batchStarts[] = $firstId;
        if ($firstId === 101 && $this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new RuntimeException($this->failureMessage);
        }
        foreach ($rows as $row) {
            DB::statement('INSERT INTO retry_stat_totals (user_id, u, d) VALUES (?, ?, ?)
                ON CONFLICT (user_id) DO UPDATE SET u = u + excluded.u, d = d + excluded.d',
                [$row['user_id'], $row['u'], $row['d']]);
        }
    }
}

final class DeadlockRetryStatUserNodeDayJob extends StatUserNodeDayJob
{
    public int $attempts = 0;

    public function __construct()
    {
        parent::__construct(
            ['id' => 10, 'name' => 'HY2-35', 'rate' => 1],
            [10001 => [1024, 2048]],
            'hysteria',
            'd',
            1783840261
        );
    }

    protected function upsertRowsForMySqlLike(array $rows): void
    {
        $this->attempts++;
        if ($this->attempts < 3) {
            throw new RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
        }
    }

    public function summarize(\Throwable $error): string
    {
        return $this->summarizeDatabaseError($error);
    }
}

final class NonRetryableStatUserNodeDayJob extends StatUserNodeDayJob
{
    public int $attempts = 0;

    public function __construct()
    {
        parent::__construct(
            ['id' => 10, 'name' => 'HY2-35', 'rate' => 1],
            [10001 => [1024, 2048]],
            'hysteria',
            'd',
            1783840261
        );
    }

    protected function upsertRowsForMySqlLike(array $rows): void
    {
        $this->attempts++;
        throw new RuntimeException('syntax error');
    }
}

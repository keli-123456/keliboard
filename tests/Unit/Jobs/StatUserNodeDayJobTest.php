<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\StatUserNodeDayJob;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

final class StatUserNodeDayJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = Container::getInstance();
        $app->instance('log', new NullLogger());
        Facade::setFacadeApplication($app);
    }

    public function test_it_retries_a_mysql_deadlock_before_marking_daily_statistics_as_failed(): void
    {
        config(['database.default' => 'mysql']);
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

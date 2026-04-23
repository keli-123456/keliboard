<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\StatUserJob;
use ReflectionMethod;
use Tests\TestCase;

final class StatUserJobTest extends TestCase
{
    public function test_build_rows_filters_invalid_values_and_applies_rate(): void
    {
        $job = new StatUserJob(
            ['rate' => 2.0],
            [
                10 => [100, 50],
                'x' => [1, 1],
                11 => [0, 0],
                12 => ['3', '2'],
                13 => [100],
            ],
            'vless',
            'd'
        );

        $method = new ReflectionMethod(StatUserJob::class, 'buildRows');
        $method->setAccessible(true);

        $rows = $method->invoke($job, 1700000000);

        $this->assertCount(3, $rows);

        $this->assertSame(10, $rows[0]['user_id']);
        $this->assertSame(2.0, $rows[0]['server_rate']);
        $this->assertSame(200.0, $rows[0]['u']);
        $this->assertSame(100.0, $rows[0]['d']);
        $this->assertSame('d', $rows[0]['record_type']);
        $this->assertSame(1700000000, $rows[0]['record_at']);

        $this->assertSame(11, $rows[1]['user_id']);
        $this->assertSame(0.0, $rows[1]['u']);
        $this->assertSame(0.0, $rows[1]['d']);

        $this->assertSame(12, $rows[2]['user_id']);
        $this->assertSame(6.0, $rows[2]['u']);
        $this->assertSame(4.0, $rows[2]['d']);

        $this->assertIsInt($rows[0]['created_at']);
        $this->assertIsInt($rows[0]['updated_at']);
    }

    public function test_build_mysql_batch_upsert_statement_returns_expected_sql_and_bindings(): void
    {
        $job = new StatUserJob(
            ['rate' => 1],
            [],
            'vless',
            'd'
        );

        $method = new ReflectionMethod(StatUserJob::class, 'buildMySqlBatchUpsertStatement');
        $method->setAccessible(true);

        [$sql, $bindings] = $method->invoke($job, [
            [
                'user_id' => 10,
                'server_rate' => 1.0,
                'record_at' => 1700000000,
                'record_type' => 'd',
                'u' => 100.0,
                'd' => 50.0,
                'created_at' => 1700000001,
                'updated_at' => 1700000001,
            ],
            [
                'user_id' => 11,
                'server_rate' => 1.0,
                'record_at' => 1700000000,
                'record_type' => 'd',
                'u' => 20.0,
                'd' => 30.0,
                'created_at' => 1700000001,
                'updated_at' => 1700000001,
            ],
        ]);

        $this->assertStringContainsString('INSERT INTO v2_stat_user', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('u = v2_stat_user.u + VALUES(u)', $sql);
        $this->assertStringContainsString('d = v2_stat_user.d + VALUES(d)', $sql);

        $this->assertCount(16, $bindings);
        $this->assertSame([10, 1.0, 1700000000, 'd', 100.0, 50.0, 1700000001, 1700000001], array_slice($bindings, 0, 8));
    }

    public function test_build_postgres_batch_upsert_statement_returns_expected_sql_and_bindings(): void
    {
        $job = new StatUserJob(
            ['rate' => 1],
            [],
            'vless',
            'd'
        );

        $method = new ReflectionMethod(StatUserJob::class, 'buildPostgresBatchUpsertStatement');
        $method->setAccessible(true);

        [$sql, $bindings] = $method->invoke($job, [
            [
                'user_id' => 10,
                'server_rate' => 1.0,
                'record_at' => 1700000000,
                'record_type' => 'd',
                'u' => 100.0,
                'd' => 50.0,
                'created_at' => 1700000001,
                'updated_at' => 1700000001,
            ],
        ]);

        $this->assertStringContainsString('INSERT INTO v2_stat_user', $sql);
        $this->assertStringContainsString('ON CONFLICT (user_id, server_rate, record_at)', $sql);
        $this->assertStringContainsString('u = v2_stat_user.u + EXCLUDED.u', $sql);
        $this->assertStringContainsString('d = v2_stat_user.d + EXCLUDED.d', $sql);

        $this->assertCount(8, $bindings);
        $this->assertSame([10, 1.0, 1700000000, 'd', 100.0, 50.0, 1700000001, 1700000001], $bindings);
    }
}

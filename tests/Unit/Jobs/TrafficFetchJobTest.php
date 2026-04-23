<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\TrafficFetchJob;
use ReflectionMethod;
use Tests\TestCase;

final class TrafficFetchJobTest extends TestCase
{
    public function test_normalize_traffic_data_filters_invalid_and_scales_with_rate(): void
    {
        $job = new TrafficFetchJob(
            ['rate' => 1.5],
            [],
            'vless',
            time()
        );

        $method = new ReflectionMethod(TrafficFetchJob::class, 'normalizeTrafficData');
        $method->setAccessible(true);

        $normalized = $method->invoke($job, [
            '1' => [100, 50],
            2 => [0, 0],
            3 => ['invalid', 10],
            -1 => [20, 30],
            4 => [100],
            5 => ['5', '2'],
        ]);

        $this->assertSame([1, 2, 3, 5], array_keys($normalized));
        $this->assertSame(150.0, $normalized[1]['u']);
        $this->assertSame(75.0, $normalized[1]['d']);
        $this->assertSame(0.0, $normalized[2]['u']);
        $this->assertSame(0.0, $normalized[2]['d']);
        $this->assertSame(0.0, $normalized[3]['u']);
        $this->assertSame(15.0, $normalized[3]['d']);
        $this->assertSame(7.5, $normalized[5]['u']);
        $this->assertSame(3.0, $normalized[5]['d']);
    }

    public function test_build_batch_update_statement_returns_expected_sql_and_bindings(): void
    {
        $job = new TrafficFetchJob(
            ['rate' => 1],
            [],
            'vless',
            time()
        );

        $method = new ReflectionMethod(TrafficFetchJob::class, 'buildBatchUpdateStatement');
        $method->setAccessible(true);

        [$sql, $bindings] = $method->invoke(
            $job,
            [
                1 => ['u' => 10, 'd' => 20],
                5 => ['u' => 1.5, 'd' => 2.5],
            ],
            123
        );

        $this->assertStringContainsString('UPDATE v2_user SET u = u + CASE id', $sql);
        $this->assertStringContainsString('d = d + CASE id', $sql);
        $this->assertStringContainsString('WHERE id IN (?, ?)', $sql);

        $this->assertCount(11, $bindings);
        $this->assertSame([1, 10, 5, 1.5, 1, 20, 5, 2.5, 123, 1, 5], $bindings);
    }
}

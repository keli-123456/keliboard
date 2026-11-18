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

}

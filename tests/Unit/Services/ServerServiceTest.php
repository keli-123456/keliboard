<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerService;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class ServerServiceTest extends TestCase
{
    public function test_order_by_id_sequence_preserves_requested_route_order(): void
    {
        $records = new Collection([
            (object) ['id' => 3, 'action' => 'third'],
            (object) ['id' => 1, 'action' => 'first'],
            (object) ['id' => 2, 'action' => 'second'],
        ]);

        $ordered = ServerService::orderByIdSequence($records, [2, 3, 1]);

        $this->assertSame([2, 3, 1], $ordered->pluck('id')->all());
        $this->assertSame(['second', 'third', 'first'], $ordered->pluck('action')->all());
    }

    public function test_order_by_id_sequence_pushes_unrequested_records_to_tail(): void
    {
        $records = new Collection([
            (object) ['id' => 9],
            (object) ['id' => 5],
            (object) ['id' => 7],
        ]);

        $ordered = ServerService::orderByIdSequence($records, [7, 5]);

        $this->assertSame([7, 5, 9], $ordered->pluck('id')->all());
    }
}

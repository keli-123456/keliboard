<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerService;
use Tests\TestCase;

final class ServerServiceInternalTest extends TestCase
{
    public function test_order_by_id_sequence_deduplicates_requested_ids_and_preserves_records(): void
    {
        $records = collect([
            (object) ['id' => 5, 'name' => 'five'],
            (object) ['id' => 3, 'name' => 'three'],
            (object) ['id' => 7, 'name' => 'seven'],
        ]);

        $ordered = ServerService::orderByIdSequence($records, [3, 3, 7, 999]);

        $this->assertSame([3, 7, 5], $ordered->pluck('id')->all());
        $this->assertSame(['three', 'seven', 'five'], $ordered->pluck('name')->all());
    }

    public function test_normalize_group_ids_sorts_values_and_removes_duplicates(): void
    {
        $method = new \ReflectionMethod(ServerService::class, 'normalizeGroupIds');
        $method->setAccessible(true);

        $normalized = $method->invoke(null, ['10', 2, '2', 5, 10, 3]);

        $this->assertSame([2, 3, 5, 10], $normalized);
    }

    public function test_available_user_ids_cache_key_uses_normalized_group_order(): void
    {
        $normalizeMethod = new \ReflectionMethod(ServerService::class, 'normalizeGroupIds');
        $normalizeMethod->setAccessible(true);

        $cacheKeyMethod = new \ReflectionMethod(ServerService::class, 'availableUserIdsCacheKey');
        $cacheKeyMethod->setAccessible(true);

        $groupsA = $normalizeMethod->invoke(null, [1, 5, 2]);
        $groupsB = $normalizeMethod->invoke(null, [5, 2, 1]);

        $allKeyA = $cacheKeyMethod->invoke(null, $groupsA, false);
        $allKeyB = $cacheKeyMethod->invoke(null, $groupsB, false);
        $deviceKey = $cacheKeyMethod->invoke(null, $groupsA, true);

        $this->assertSame($allKeyA, $allKeyB);
        $this->assertStringStartsWith('server:available-user-ids:all:', $allKeyA);
        $this->assertStringStartsWith('server:available-user-ids:device:', $deviceKey);
        $this->assertNotSame($allKeyA, $deviceKey);
    }
}

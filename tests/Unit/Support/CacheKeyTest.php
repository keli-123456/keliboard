<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Utils\CacheKey;
use Tests\TestCase;

final class CacheKeyTest extends TestCase
{
    public function test_admin_dashboard_snapshot_cache_keys_are_known_core_keys(): void
    {
        $this->assertSame('ADMIN_SYSTEM_STATUS_SNAPSHOT', CacheKey::get('ADMIN_SYSTEM_STATUS_SNAPSHOT'));
        $this->assertSame('ADMIN_QUEUE_STATS_SNAPSHOT', CacheKey::get('ADMIN_QUEUE_STATS_SNAPSHOT'));
    }

    public function test_admin_dashboard_snapshot_cache_keys_keep_unique_suffixes(): void
    {
        $this->assertSame(
            'ADMIN_QUEUE_STATS_SNAPSHOT_site-1',
            CacheKey::get('ADMIN_QUEUE_STATS_SNAPSHOT', 'site-1')
        );
    }
}

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

    public function test_health_diagnostics_key_is_registered_without_masking_unknown_keys(): void
    {
        app()->instance('env', 'local');
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Unknown cache key used: UNREGISTERED_TEST_KEY');
        app()->instance('log', $logger);

        $this->assertSame('ADMIN_SYSTEM_HEALTH_DIAGNOSTICS', CacheKey::get('ADMIN_SYSTEM_HEALTH_DIAGNOSTICS'));
        $this->assertSame('ADMIN_SYSTEM_HEALTH_DIAGNOSTICS_site-1', CacheKey::get('ADMIN_SYSTEM_HEALTH_DIAGNOSTICS', 'site-1'));
        $this->assertSame('UNREGISTERED_TEST_KEY', CacheKey::get('UNREGISTERED_TEST_KEY'));
    }
}

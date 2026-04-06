<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\UserOnlineService;
use Tests\TestCase;

final class UserOnlineServiceTest extends TestCase
{
    public function test_calculate_device_count_counts_every_ip_in_strict_mode(): void
    {
        $ipsArray = [
            'hysteria21' => [
                'aliveips' => ['1.1.1.1_1', '1.1.1.1_2', '2.2.2.2_1'],
                'lastupdateAt' => time(),
            ],
            'alive_ip' => 99,
        ];

        $count = UserOnlineService::calculateDeviceCount($ipsArray, 0);

        $this->assertSame(3, $count);
    }

    public function test_calculate_device_count_deduplicates_ips_in_loose_mode(): void
    {
        $ipsArray = [
            'hysteria21' => [
                'aliveips' => ['1.1.1.1_1', '2.2.2.2_1'],
                'lastupdateAt' => time(),
            ],
            'vless2' => [
                'aliveips' => ['1.1.1.1_2', '3.3.3.3_1'],
                'lastupdateAt' => time(),
            ],
        ];

        $count = UserOnlineService::calculateDeviceCount($ipsArray, 1);

        $this->assertSame(3, $count);
    }

    public function test_normalize_hmset_parameters_converts_flat_arguments_to_hash_map(): void
    {
        $method = new \ReflectionMethod(UserOnlineService::class, 'normalizeHmsetParameters');
        $method->setAccessible(true);

        $normalized = $method->invoke(null, ['ALIVE_IP_ACTIVE_COUNTS', '104875', '2', '120220', '1']);

        $this->assertSame([
            'ALIVE_IP_ACTIVE_COUNTS',
            [
                '104875' => '2',
                '120220' => '1',
            ],
        ], $normalized);
    }

    public function test_normalize_hmset_parameters_keeps_hash_map_shape_unchanged(): void
    {
        $method = new \ReflectionMethod(UserOnlineService::class, 'normalizeHmsetParameters');
        $method->setAccessible(true);

        $normalized = $method->invoke(null, [
            'ALIVE_IP_ACTIVE_COUNTS',
            [
                '104875' => '2',
                '120220' => '1',
            ],
        ]);

        $this->assertSame([
            'ALIVE_IP_ACTIVE_COUNTS',
            [
                '104875' => '2',
                '120220' => '1',
            ],
        ], $normalized);
    }

    public function test_normalize_hmget_parameters_converts_flat_arguments_to_field_list(): void
    {
        $method = new \ReflectionMethod(UserOnlineService::class, 'normalizeHmgetParameters');
        $method->setAccessible(true);

        $normalized = $method->invoke(null, ['ALIVE_IP_ACTIVE_COUNTS', '104875', '120220']);

        $this->assertSame([
            'ALIVE_IP_ACTIVE_COUNTS',
            ['104875', '120220'],
        ], $normalized);
    }

    public function test_normalize_hmget_parameters_keeps_field_list_shape_unchanged(): void
    {
        $method = new \ReflectionMethod(UserOnlineService::class, 'normalizeHmgetParameters');
        $method->setAccessible(true);

        $normalized = $method->invoke(null, [
            'ALIVE_IP_ACTIVE_COUNTS',
            ['104875', '120220'],
        ]);

        $this->assertSame([
            'ALIVE_IP_ACTIVE_COUNTS',
            ['104875', '120220'],
        ], $normalized);
    }

    public function test_extract_alive_ips_deduplicates_same_ip_across_nodes(): void
    {
        $method = new \ReflectionMethod(UserOnlineService::class, 'extractAliveIps');
        $method->setAccessible(true);

        $ips = $method->invoke(null, [
            'vless28' => [
                'aliveips' => ['1.1.1.1', '2.2.2.2'],
                'lastupdateAt' => time(),
            ],
            'tuic31' => [
                'aliveips' => ['1.1.1.1', '3.3.3.3'],
                'lastupdateAt' => time(),
            ],
            'alive_ip' => 3,
        ]);

        $this->assertSame(['1.1.1.1', '2.2.2.2', '3.3.3.3'], $ips);
    }

    public function test_summarize_alive_cache_ignores_stale_node_entries(): void
    {
        $method = new \ReflectionMethod(UserOnlineService::class, 'summarizeAliveCache');
        $method->setAccessible(true);

        $now = time();
        $summary = $method->invoke(null, [
            'vless8' => [
                'aliveips' => ['1.1.1.1', '2.2.2.2'],
                'lastupdateAt' => $now - 10,
            ],
            'tuic9' => [
                'aliveips' => ['3.3.3.3'],
                'lastupdateAt' => $now - 200,
            ],
            'alive_ip' => 99,
        ], 1, $now);

        $this->assertSame(2, $summary['alive_ip']);
        $this->assertSame(['1.1.1.1', '2.2.2.2'], $summary['ips']);
        $this->assertArrayHasKey('vless8', $summary['nodes']);
        $this->assertArrayNotHasKey('tuic9', $summary['nodes']);
    }

    public function test_calculate_device_count_normalizes_same_ip_variants_in_loose_mode(): void
    {
        $ipsArray = [
            'vless8' => [
                'aliveips' => ['27.37.83.78', '27.37.83.78 ', '::ffff:27.37.83.78'],
                'lastupdateAt' => time(),
            ],
            'tuic9' => [
                'aliveips' => ['27.37.83.78'],
                'lastupdateAt' => time(),
            ],
        ];

        $count = UserOnlineService::calculateDeviceCount($ipsArray, 1);

        $this->assertSame(1, $count);
    }

    public function test_extract_alive_ips_normalizes_same_ip_variants(): void
    {
        $method = new \ReflectionMethod(UserOnlineService::class, 'extractAliveIps');
        $method->setAccessible(true);

        $ips = $method->invoke(null, [
            'vless8' => [
                'aliveips' => ['27.37.83.78', '27.37.83.78 ', '::ffff:27.37.83.78'],
                'lastupdateAt' => time(),
            ],
            'tuic9' => [
                'aliveips' => ['27.37.83.78'],
                'lastupdateAt' => time(),
            ],
        ]);

        $this->assertSame(['27.37.83.78'], $ips);
    }

    public function test_display_summary_always_deduplicates_same_ip_across_nodes(): void
    {
        $method = new \ReflectionMethod(UserOnlineService::class, 'summarizeAliveDisplayCache');
        $method->setAccessible(true);

        $summary = $method->invoke(null, [
            'vless8' => [
                'aliveips' => ['211.158.12.8', '211.158.12.8', '123.147.236.68'],
                'lastupdateAt' => time(),
            ],
            'tuic9' => [
                'aliveips' => ['211.158.12.8', '::ffff:211.158.12.8', '211.158.12.8 '],
                'lastupdateAt' => time(),
            ],
        ]);

        $this->assertSame(2, $summary['alive_ip']);
        $this->assertSame(['123.147.236.68', '211.158.12.8'], $summary['ips']);
    }
}

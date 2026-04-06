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
}

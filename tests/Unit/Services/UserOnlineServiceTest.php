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
}

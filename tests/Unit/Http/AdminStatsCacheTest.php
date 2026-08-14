<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\StatController;
use App\Services\StatisticalService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class AdminStatsCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_force_refresh_rebuilds_the_cached_dashboard_payload(): void
    {
        $calls = 0;
        $resolver = static function () use (&$calls): array {
            $calls++;

            return ['sequence' => $calls];
        };

        $method = new \ReflectionMethod(StatController::class, 'cachedAdminStats');
        $method->setAccessible(true);
        $controller = new StatController(new StatisticalService());

        $first = $method->invoke($controller, 'test:admin:stats', $resolver, false);
        $cached = $method->invoke($controller, 'test:admin:stats', $resolver, false);
        $refreshed = $method->invoke($controller, 'test:admin:stats', $resolver, true);
        $cachedAfterRefresh = $method->invoke($controller, 'test:admin:stats', $resolver, false);

        $this->assertSame(['sequence' => 1], $first);
        $this->assertSame($first, $cached);
        $this->assertSame(['sequence' => 2], $refreshed);
        $this->assertSame($refreshed, $cachedAfterRefresh);
        $this->assertSame(2, $calls);
    }

    public function test_fresh_online_overview_replaces_cached_online_values(): void
    {
        $method = new \ReflectionMethod(StatController::class, 'withFreshOnlineOverview');
        $method->setAccessible(true);
        $controller = new StatController(new StatisticalService());

        $payload = [
            'totalUsers' => 1200,
            'onlineDevices' => 1,
            'onlineUsers' => 1,
            'online_devices' => 1,
            'online_users' => 1,
        ];

        $result = $method->invoke($controller, $payload, [
            'online_devices' => 567,
            'online_users' => 321,
        ]);

        $this->assertSame(1200, $result['totalUsers']);
        $this->assertSame(567, $result['onlineDevices']);
        $this->assertSame(321, $result['onlineUsers']);
        $this->assertSame(567, $result['online_devices']);
        $this->assertSame(321, $result['online_users']);
    }
}
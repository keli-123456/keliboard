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
}
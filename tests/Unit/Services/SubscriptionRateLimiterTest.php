<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Cache;
use Plugin\SubscriptionControl\Services\SubscriptionRateLimiter;
use Tests\TestCase;

final class SubscriptionRateLimiterTest extends TestCase
{
    public function test_accounts_are_independent_and_expired_entries_do_not_consume_quota(): void
    {
        $service = app(SubscriptionRateLimiter::class);
        Cache::put('subscription_control:rate_limit:11', [time() - 301, time() - 10], 300);
        $this->assertTrue($service->admit(11, 2, 300));
        $this->assertFalse($service->admit(11, 2, 300));
        $this->assertTrue($service->admit(12, 2, 300));
    }
}

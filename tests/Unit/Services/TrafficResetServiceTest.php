<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\User;
use App\Services\TrafficResetService;
use App\Support\Setting;
use Carbon\Carbon;
use Tests\TestCase;

final class TrafficResetServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monthly_reset_clamps_to_month_end_for_missing_anchor_day(): void
    {
        config()->set('app.timezone', 'Asia/Shanghai');
        $this->bindSettings(['reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        Carbon::setTestNow(Carbon::parse('2026-04-10 09:00:00', 'Asia/Shanghai'));

        $plan = new Plan();
        $plan->reset_traffic_method = Plan::RESET_TRAFFIC_MONTHLY;

        $user = new User();
        $user->plan_id = 1;
        $user->banned = 0;
        $user->expired_at = Carbon::parse('2025-01-31 08:30:15', 'Asia/Shanghai')->timestamp;
        $user->setRelation('plan', $plan);

        $next = (new TrafficResetService())->calculateNextResetTime($user);

        $this->assertNotNull($next);
        $this->assertSame('2026-04-30 08:30:15', $next->format('Y-m-d H:i:s'));
    }

    public function test_yearly_reset_clamps_feb_29_to_feb_28_in_non_leap_year(): void
    {
        config()->set('app.timezone', 'Asia/Shanghai');
        $this->bindSettings(['reset_traffic_method' => Plan::RESET_TRAFFIC_YEARLY]);
        Carbon::setTestNow(Carbon::parse('2025-01-10 00:00:00', 'Asia/Shanghai'));

        $plan = new Plan();
        $plan->reset_traffic_method = Plan::RESET_TRAFFIC_YEARLY;

        $user = new User();
        $user->plan_id = 1;
        $user->banned = 0;
        $user->expired_at = Carbon::parse('2024-02-29 12:00:00', 'Asia/Shanghai')->timestamp;
        $user->setRelation('plan', $plan);

        $next = (new TrafficResetService())->calculateNextResetTime($user);

        $this->assertNotNull($next);
        $this->assertSame('2025-02-28 12:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_follow_system_never_returns_null_reset_time(): void
    {
        config()->set('app.timezone', 'Asia/Shanghai');
        $this->bindSettings(['reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        Carbon::setTestNow(Carbon::parse('2026-04-10 09:00:00', 'Asia/Shanghai'));

        $plan = new Plan();
        $plan->reset_traffic_method = Plan::RESET_TRAFFIC_FOLLOW_SYSTEM;

        $user = new User();
        $user->plan_id = 1;
        $user->banned = 0;
        $user->expired_at = Carbon::parse('2025-01-15 10:00:00', 'Asia/Shanghai')->timestamp;
        $user->setRelation('plan', $plan);

        $next = (new TrafficResetService())->calculateNextResetTime($user);

        $this->assertNull($next);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function bindSettings(array $values): void
    {
        app()->instance(Setting::class, new class($values) extends Setting {
            /**
             * @var array<string, mixed>
             */
            private array $values;

            /**
             * @param array<string, mixed> $values
             */
            public function __construct(array $values)
            {
                // do not call parent constructor in tests
                $this->values = $values;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                $key = strtolower($key);
                return $this->values[$key] ?? $default;
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Tests\TestCase;

final class PlanServicePurchaseValidationTest extends TestCase
{
    public function test_expired_user_can_restore_same_hidden_renewable_plan(): void
    {
        $plan = new Plan([
            'name' => 'Hidden renewable plan',
            'show' => false,
            'sell' => true,
            'renew' => true,
            'prices' => [
                Plan::PERIOD_YEARLY => 108,
            ],
        ]);
        $plan->id = 12;

        $user = new User([
            'plan_id' => 12,
            'expired_at' => time() - 3600,
            'transfer_enable' => 100 * 1024 * 1024 * 1024,
            'banned' => 0,
        ]);

        (new PlanService($plan))->validatePurchase($user, 'year_price');

        $this->addToAssertionCount(1);
    }

    public function test_expired_user_still_cannot_buy_reset_traffic_package(): void
    {
        $plan = new Plan([
            'name' => 'Reset package plan',
            'show' => false,
            'sell' => true,
            'renew' => true,
            'prices' => [
                Plan::PERIOD_RESET_TRAFFIC => 9,
            ],
        ]);
        $plan->id = 12;

        $user = new User([
            'plan_id' => 12,
            'expired_at' => time() - 3600,
            'transfer_enable' => 100 * 1024 * 1024 * 1024,
            'banned' => 0,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Subscription has expired or no active subscription, unable to purchase Data Reset Package');

        (new PlanService($plan))->validatePurchase($user, Plan::PERIOD_RESET_TRAFFIC);
    }

    public function test_hidden_renewable_plan_still_cannot_be_purchased_by_other_users(): void
    {
        $plan = new Plan([
            'name' => 'Hidden renewable plan',
            'show' => false,
            'sell' => true,
            'renew' => true,
            'prices' => [
                Plan::PERIOD_YEARLY => 108,
            ],
        ]);
        $plan->id = 12;

        $user = new User([
            'plan_id' => 34,
            'expired_at' => time() - 3600,
            'transfer_enable' => 100 * 1024 * 1024 * 1024,
            'banned' => 0,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This subscription has been sold out, please choose another subscription');

        (new PlanService($plan))->validatePurchase($user, 'year_price');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\User\OrderSave;
use App\Models\Plan;
use PHPUnit\Framework\TestCase;

final class OrderSaveRequestTest extends TestCase
{
    public function test_period_rule_accepts_legacy_and_modern_period_keys(): void
    {
        $rule = (new OrderSave())->rules()['period'];

        foreach ([
            'month_price',
            'year_price',
            'reset_price',
            Plan::PERIOD_MONTHLY,
            Plan::PERIOD_YEARLY,
            Plan::PERIOD_RESET_TRAFFIC,
        ] as $period) {
            $this->assertStringContainsString($period, $rule);
        }
    }
}

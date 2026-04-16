<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Services\PlanService;
use Tests\TestCase;

final class PlanServicePeriodMappingTest extends TestCase
{
    public function test_get_period_key_converts_legacy_periods_and_keeps_new_periods(): void
    {
        $this->assertSame(Plan::PERIOD_MONTHLY, PlanService::getPeriodKey('month_price'));
        $this->assertSame(Plan::PERIOD_QUARTERLY, PlanService::getPeriodKey(Plan::PERIOD_QUARTERLY));
        $this->assertSame('custom_period', PlanService::getPeriodKey('custom_period'));
    }

    public function test_legacy_period_conversion_is_bidirectional_for_known_values(): void
    {
        $this->assertSame('month_price', PlanService::convertToLegacyPeriod(Plan::PERIOD_MONTHLY));
        $this->assertSame('year_price', PlanService::getLegacyPeriod(Plan::PERIOD_YEARLY));
        $this->assertSame('unknown', PlanService::convertToLegacyPeriod('unknown'));
    }
}


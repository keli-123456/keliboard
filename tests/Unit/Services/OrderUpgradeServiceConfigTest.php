<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Services\OrderUpgradeService;
use Tests\TestCase;

final class OrderUpgradeServiceConfigTest extends TestCase
{
    public function test_normalize_credit_coefficients_uses_defaults_for_invalid_input(): void
    {
        $normalized = OrderUpgradeService::normalizeCreditCoefficients('invalid');

        $this->assertSame(OrderUpgradeService::getDefaultCreditCoefficients(), $normalized);
    }

    public function test_normalize_credit_coefficients_clamps_and_merges_values(): void
    {
        $normalized = OrderUpgradeService::normalizeCreditCoefficients([
            Plan::PERIOD_MONTHLY => 1.3,
            Plan::PERIOD_YEARLY => -0.1,
            Plan::PERIOD_QUARTERLY => '0.6',
        ]);

        $this->assertEquals(1.0, $normalized[Plan::PERIOD_MONTHLY]);
        $this->assertEquals(0.6, $normalized[Plan::PERIOD_QUARTERLY]);
        $this->assertEquals(0.0, $normalized[Plan::PERIOD_YEARLY]);
        $this->assertArrayHasKey(Plan::PERIOD_TWO_YEARLY, $normalized);
    }

    public function test_normalize_usage_penalty_rules_sorts_and_clamps_valid_rules(): void
    {
        $normalized = OrderUpgradeService::normalizeUsagePenaltyRules([
            ['max_usage_percentage' => 120, 'coefficient' => 1.5],
            ['max_usage_percentage' => '40', 'coefficient' => '0.8'],
            ['max_usage_percentage' => 20, 'coefficient' => -0.4],
            ['max_usage_percentage' => null, 'coefficient' => 0.1],
        ]);

        $this->assertCount(3, $normalized);
        $this->assertEquals(20.0, $normalized[0]['max_usage_percentage']);
        $this->assertEquals(0.0, $normalized[0]['coefficient']);
        $this->assertEquals(40.0, $normalized[1]['max_usage_percentage']);
        $this->assertEquals(0.8, $normalized[1]['coefficient']);
        $this->assertEquals(100.0, $normalized[2]['max_usage_percentage']);
        $this->assertEquals(1.0, $normalized[2]['coefficient']);
    }

    public function test_normalize_usage_penalty_rules_falls_back_to_defaults_when_empty(): void
    {
        $normalized = OrderUpgradeService::normalizeUsagePenaltyRules([]);

        $this->assertSame(OrderUpgradeService::getDefaultUsagePenaltyRules(), $normalized);
    }
}

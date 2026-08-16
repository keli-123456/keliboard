<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Cache;
use Plugin\SubscriptionControl\Services\SubscriptionBehaviorBaseline;
use Tests\TestCase;

final class SubscriptionBehaviorBaselineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_waits_for_a_mature_baseline_before_reporting_deviation(): void
    {
        $baseline = new SubscriptionBehaviorBaseline([
            'behavior_baseline_min_observations' => 8,
            'behavior_baseline_score_threshold' => 45,
        ]);

        for ($index = 0; $index < 8; $index++) {
            $this->assertNull($baseline->observe(
                1001,
                'token-a',
                'ip-a',
                'sing-box',
                '中国/广东',
                ['中国/广东'],
                false,
                false,
                true
            ));
        }

        $event = $baseline->observe(
            1001,
            'token-a',
            'ip-b',
            'script',
            '美国',
            ['中国/广东'],
            false,
            true,
            false
        );

        $this->assertNotNull($event);
        $this->assertGreaterThanOrEqual(45, $event['risk_score']);
        $this->assertContains('behavior_new_risky_ua', $event['signals']);
        $this->assertContains('behavior_new_region', $event['signals']);
        $this->assertContains('behavior_new_ip', $event['signals']);
        $this->assertContains('behavior_online_region_mismatch', $event['signals']);
        $this->assertContains('behavior_combined_deviation', $event['signals']);
    }

    public function test_a_single_low_weight_change_remains_observation_free(): void
    {
        $baseline = new SubscriptionBehaviorBaseline([
            'behavior_baseline_min_observations' => 3,
            'behavior_baseline_score_threshold' => 45,
        ]);

        for ($index = 0; $index < 3; $index++) {
            $baseline->observe(1002, 'token-b', 'ip-a', 'sing-box', '中国/上海', ['中国/上海'], false, false, true);
        }

        $this->assertNull($baseline->observe(
            1002,
            'token-b',
            'ip-b',
            'mihomo',
            '中国/上海',
            ['中国/上海'],
            false,
            false,
            true
        ));
    }

    public function test_it_never_stores_the_plain_subscription_token_in_cache_keys(): void
    {
        $baseline = new SubscriptionBehaviorBaseline([
            'behavior_baseline_min_observations' => 3,
        ]);

        $baseline->observe(1003, 'plain-secret-token', 'ip-a', 'sing-box', '日本', ['日本']);

        $this->assertFalse(Cache::has('subscription_control:behavior_baseline:1003:plain-secret-token'));
    }
}

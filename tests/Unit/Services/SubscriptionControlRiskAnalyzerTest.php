<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Plugin\SubscriptionControl\Services\SubscriptionRiskAnalyzer;
use Tests\TestCase;

final class SubscriptionControlRiskAnalyzerTest extends TestCase
{
    public function test_classifies_mihomo_and_clash_as_one_client_family(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer();

        $mihomo = $analyzer->classifyUserAgent('mihomo/1.19.8');
        $clashMeta = $analyzer->classifyUserAgent('Clash.Meta/1.18.0');

        $this->assertSame('mihomo', $mihomo['category']);
        $this->assertSame('mihomo', $clashMeta['category']);
    }

    public function test_multi_ua_detection_ignores_version_changes_inside_same_family(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_multi_ua_detection' => true,
            'multi_ua_allowed_count' => 1,
            'multi_ua_window_seconds' => 600,
            'multi_ua_action' => 'empty',
        ]);

        $first = $analyzer->inspectSubscriptionPull(1001, 'token-a', '1.1.1.1', 'mihomo/1.19.8');
        $second = $analyzer->inspectSubscriptionPull(1001, 'token-a', '1.1.1.1', 'Clash.Meta/1.18.0');

        $this->assertSame([], $first);
        $this->assertSame([], $second);
    }

    public function test_multi_ua_detection_flags_distinct_client_families_on_same_token(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_multi_ua_detection' => true,
            'multi_ua_allowed_count' => 1,
            'multi_ua_window_seconds' => 600,
            'multi_ua_action' => 'empty',
        ]);

        $analyzer->inspectSubscriptionPull(1002, 'token-b', '1.1.1.1', 'mihomo/1.19.8');
        $decisions = $analyzer->inspectSubscriptionPull(1002, 'token-b', '1.1.1.1', 'sing-box/1.11.0');

        $this->assertCount(1, $decisions);
        $this->assertSame('multi_ua_pull', $decisions[0]['code']);
        $this->assertSame('empty', $decisions[0]['action']);
        $this->assertSame(['mihomo', 'sing-box'], $decisions[0]['meta']['ua_categories']);
    }

    public function test_legacy_reset_token_action_is_normalized_to_full_credential_reset(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_multi_ua_detection' => true,
            'multi_ua_allowed_count' => 1,
            'multi_ua_window_seconds' => 600,
            'multi_ua_action' => 'reset_token',
        ]);

        $analyzer->inspectSubscriptionPull(1006, 'token-f', '1.1.1.1', 'mihomo/1.19.8');
        $decisions = $analyzer->inspectSubscriptionPull(1006, 'token-f', '1.1.1.1', 'sing-box/1.11.0');

        $this->assertCount(1, $decisions);
        $this->assertSame('reset_token_uuid', $decisions[0]['action']);
    }

    public function test_client_ua_whitelist_flags_unapproved_client_family(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_client_ua_whitelist' => true,
            'client_ua_whitelist' => "mihomo\nsing-box\nshadowrocket",
            'client_ua_unknown_action' => 'block',
        ]);

        $allowed = $analyzer->inspectSubscriptionPull(1003, 'token-c', '1.1.1.1', 'mihomo/1.19.8');
        $blocked = $analyzer->inspectSubscriptionPull(1003, 'token-c', '1.1.1.1', 'curl/8.5.0');

        $this->assertSame([], $allowed);
        $this->assertCount(1, $blocked);
        $this->assertSame('client_ua_not_allowed', $blocked[0]['code']);
        $this->assertSame('block', $blocked[0]['action']);
        $this->assertSame('script', $blocked[0]['meta']['ua_category']);
    }

    public function test_multi_region_pull_detection_flags_same_token_from_distinct_regions(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_multi_region_pull_detection' => true,
            'multi_region_pull_allowed_count' => 1,
            'multi_region_pull_window_seconds' => 600,
            'multi_region_pull_action' => 'empty',
            'ip_region_overrides' => [
                '1.1.1.1' => 'US',
                '2.2.2.2' => 'JP',
            ],
        ]);

        $analyzer->inspectSubscriptionPull(1004, 'token-d', '1.1.1.1', 'mihomo/1.19.8');
        $decisions = $analyzer->inspectSubscriptionPull(1004, 'token-d', '2.2.2.2', 'mihomo/1.19.8');

        $this->assertCount(1, $decisions);
        $this->assertSame('multi_region_pull', $decisions[0]['code']);
        $this->assertSame('empty', $decisions[0]['action']);
        $this->assertSame(['JP', 'US'], $decisions[0]['meta']['regions']);
    }

    public function test_multi_region_online_detection_uses_existing_online_ips(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_multi_region_online_detection' => true,
            'multi_region_online_allowed_count' => 1,
            'multi_region_online_action' => 'observe',
            'ip_region_overrides' => [
                '1.1.1.1' => 'US',
                '2.2.2.2' => 'JP',
            ],
        ]);

        $decisions = $analyzer->inspectSubscriptionPull(
            1005,
            'token-e',
            '1.1.1.1',
            'mihomo/1.19.8',
            ['online_ips' => ['1.1.1.1', '2.2.2.2']]
        );

        $this->assertCount(1, $decisions);
        $this->assertSame('multi_region_online', $decisions[0]['code']);
        $this->assertSame('observe', $decisions[0]['action']);
        $this->assertSame(['JP', 'US'], $decisions[0]['meta']['regions']);
    }

    public function test_leak_guard_returns_empty_for_script_pull_outside_online_region(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_leak_guard' => true,
            'leak_guard_action' => 'empty',
            'leak_guard_score_threshold' => 80,
            'ip_region_overrides' => [
                '1.1.1.1' => 'US',
                '2.2.2.2' => 'JP',
            ],
        ]);

        $decisions = $analyzer->inspectSubscriptionPull(
            1007,
            'token-g',
            '2.2.2.2',
            'curl/8.5.0',
            ['online_ips' => ['1.1.1.1']]
        );

        $this->assertCount(1, $decisions);
        $this->assertSame('subscription_leak_guard', $decisions[0]['code']);
        $this->assertSame('empty', $decisions[0]['action']);
        $this->assertContains('risky_ua', $decisions[0]['meta']['signals']);
        $this->assertContains('online_region_mismatch', $decisions[0]['meta']['signals']);
        $this->assertGreaterThanOrEqual(80, $decisions[0]['meta']['risk_score']);
    }

    public function test_leak_guard_allows_known_client_inside_online_region(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_leak_guard' => true,
            'leak_guard_score_threshold' => 80,
            'ip_region_overrides' => [
                '1.1.1.1' => 'US',
            ],
        ]);

        $decisions = $analyzer->inspectSubscriptionPull(
            1008,
            'token-h',
            '1.1.1.1',
            'Sparkle/1.0.0',
            ['online_ips' => ['1.1.1.1']]
        );

        $this->assertSame([], $decisions);
    }
}

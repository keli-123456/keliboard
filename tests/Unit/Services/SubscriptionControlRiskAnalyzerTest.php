<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Plugin\SubscriptionControl\Services\SubscriptionRiskAnalyzer;
use Plugin\SubscriptionControl\Services\SubscriptionIpIntelligenceService;
use Tests\TestCase;

final class SubscriptionControlRiskAnalyzerTest extends TestCase
{
    public function test_classifies_mihomo_and_clash_as_one_client_family(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer();

        $mihomo = $analyzer->classifyUserAgent('mihomo/1.19.8');
        $clashMeta = $analyzer->classifyUserAgent('Clash.Meta/1.18.0');
        $clashXMeta = $analyzer->classifyUserAgent('ClashX.Meta/1.118.0');

        $this->assertSame('mihomo', $mihomo['category']);
        $this->assertSame('mihomo', $clashMeta['category']);
        $this->assertSame('mihomo', $clashXMeta['category']);
    }

    public function test_classifies_throne_as_known_client_family(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer();

        $throne = $analyzer->classifyUserAgent('Throne/1.0.0');

        $this->assertSame('throne', $throne['category']);
        $this->assertFalse($throne['risky']);
    }

    public function test_classifies_legacy_clash_as_risky_legacy_client_family(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer();

        $windows = $analyzer->classifyUserAgent('ClashforWindows/0.20.39');
        $android = $analyzer->classifyUserAgent('ClashForAndroid/3.0.0');

        $this->assertSame('legacy_clash', $windows['category']);
        $this->assertSame('legacy_clash', $android['category']);
        $this->assertTrue($windows['risky']);
        $this->assertTrue($android['risky']);
    }

    public function test_classifies_browser_and_social_app_subscription_pull_as_risky(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer();

        $mozilla = $analyzer->classifyUserAgent('Mozilla/5.0 Chrome/138.0 Safari/537.36');
        $qq = $analyzer->classifyUserAgent('Mozilla/5.0 MQQBrowser/20.2 Mobile Safari/537.36 QQ/9.2.90');
        $telegram = $analyzer->classifyUserAgent('TelegramBot (like TwitterBot)');
        $wechat = $analyzer->classifyUserAgent('WeChat/8.0.45 MicroMessenger/8.0.45');

        $this->assertSame('browser', $mozilla['category']);
        $this->assertSame('social_app', $qq['category']);
        $this->assertSame('social_app', $telegram['category']);
        $this->assertSame('social_app', $wechat['category']);
        $this->assertTrue($mozilla['risky']);
        $this->assertTrue($qq['risky']);
        $this->assertTrue($telegram['risky']);
        $this->assertTrue($wechat['risky']);
    }

    public function test_classifies_clash_meta_for_android_as_normal_subscription_client(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer();

        $android = $analyzer->classifyUserAgent('ClashMetaForAndroid/2.11.28');

        $this->assertSame('mihomo', $android['category']);
        $this->assertFalse($android['risky']);
    }

    public function test_default_whitelist_allows_common_supported_clients_and_rejects_old_cfa(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_client_ua_whitelist' => true,
            'client_ua_unknown_action' => 'reset_token_uuid',
        ]);

        $clashMetaForAndroid = $analyzer->inspectSubscriptionPull(1015, 'token-cmfa', '1.1.1.1', 'ClashMetaForAndroid/2.11.28');
        $oldClashForAndroid = $analyzer->inspectSubscriptionPull(1017, 'token-cfa', '1.1.1.1', 'ClashForAndroid/2.5.12.premium');
        $v2rayNg = $analyzer->inspectSubscriptionPull(1016, 'token-v2rayng', '1.1.1.1', 'v2rayNG/2.2.0');
        $clashVergeRev = $analyzer->inspectSubscriptionPull(1018, 'token-verge', '1.1.1.1', 'Clash-Verge-Rev/2.4.0');
        $sfa = $analyzer->inspectSubscriptionPull(1019, 'token-sfa', '1.1.1.1', 'SFA/1.13.12');

        $this->assertSame([], $clashMetaForAndroid);
        $this->assertSame([], $v2rayNg);
        $this->assertSame([], $clashVergeRev);
        $this->assertSame([], $sfa);
        $this->assertCount(1, $oldClashForAndroid);
        $this->assertSame('client_ua_not_allowed', $oldClashForAndroid[0]['code']);
        $this->assertSame('reset_token_uuid', $oldClashForAndroid[0]['action']);
        $this->assertSame('legacy_clash', $oldClashForAndroid[0]['meta']['ua_category']);
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

    public function test_client_ua_whitelist_rejects_legacy_clash_even_when_modern_clash_is_allowed(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_client_ua_whitelist' => true,
            'client_ua_whitelist' => "mihomo\nclashmeta\nclashxmeta\nsparkle",
            'client_ua_unknown_action' => 'empty',
        ]);

        $allowed = $analyzer->inspectSubscriptionPull(1014, 'token-modern', '1.1.1.1', 'Clash.Meta/1.18.0');
        $blocked = $analyzer->inspectSubscriptionPull(1014, 'token-modern', '1.1.1.1', 'ClashforWindows/0.20.39');

        $this->assertSame([], $allowed);
        $this->assertCount(1, $blocked);
        $this->assertSame('client_ua_not_allowed', $blocked[0]['code']);
        $this->assertSame('empty', $blocked[0]['action']);
        $this->assertSame('legacy_clash', $blocked[0]['meta']['ua_category']);
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

    public function test_leak_guard_adds_low_usage_signal_only_for_active_plan_user(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_leak_guard' => true,
            'leak_guard_score_threshold' => 80,
            'ip_region_overrides' => [
                '1.1.1.1' => 'US',
                '2.2.2.2' => 'JP',
            ],
        ]);

        $expired = $analyzer->inspectSubscriptionPull(
            1015,
            'token-expired',
            '2.2.2.2',
            'curl/8.5.0',
            [
                'online_ips' => ['1.1.1.1'],
                'plan_id' => 3,
                'expired_at' => time() - 3600,
                'transfer_enable' => 100 * 1024 * 1024 * 1024,
                'used_traffic' => 1024,
            ]
        );
        $active = $analyzer->inspectSubscriptionPull(
            1016,
            'token-active',
            '2.2.2.2',
            'curl/8.5.0',
            [
                'online_ips' => ['1.1.1.1'],
                'plan_id' => 3,
                'expired_at' => time() + 3600,
                'transfer_enable' => 100 * 1024 * 1024 * 1024,
                'used_traffic' => 1024,
            ]
        );

        $this->assertNotContains('active_plan_low_usage', $expired[0]['meta']['signals']);
        $this->assertContains('active_plan_low_usage', $active[0]['meta']['signals']);
        $this->assertTrue($active[0]['meta']['active_plan_user']);
        $this->assertSame(1024, $active[0]['meta']['used_traffic']);
    }

    public function test_leak_guard_allows_low_usage_active_plan_user_with_normal_client_only(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_leak_guard' => true,
            'leak_guard_score_threshold' => 80,
            'ip_region_overrides' => [
                '1.1.1.1' => 'US',
            ],
        ]);

        $decisions = $analyzer->inspectSubscriptionPull(
            1017,
            'token-low-normal',
            '1.1.1.1',
            'Sparkle/1.0.0',
            [
                'online_ips' => ['1.1.1.1'],
                'plan_id' => 3,
                'expired_at' => time() + 3600,
                'transfer_enable' => 100 * 1024 * 1024 * 1024,
                'used_traffic' => 1024,
            ]
        );

        $this->assertSame([], $decisions);
    }

    public function test_leak_guard_blocks_low_usage_active_plan_user_with_rotating_client_families(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_leak_guard' => true,
            'leak_guard_score_threshold' => 80,
            'leak_guard_allowed_ua_count' => 1,
            'ip_region_overrides' => [
                '1.1.1.1' => 'US',
            ],
        ]);

        $context = [
            'online_ips' => ['1.1.1.1'],
            'plan_id' => 3,
            'expired_at' => time() + 3600,
            'transfer_enable' => 100 * 1024 * 1024 * 1024,
            'used_traffic' => 1024,
        ];

        $first = $analyzer->inspectSubscriptionPull(1018, 'token-low-rotate', '1.1.1.1', 'Sparkle/1.0.0', $context);
        $second = $analyzer->inspectSubscriptionPull(1018, 'token-low-rotate', '1.1.1.1', 'sing-box/1.12.0', $context);

        $this->assertSame([], $first);
        $this->assertCount(1, $second);
        $this->assertSame('subscription_leak_guard', $second[0]['code']);
        $this->assertContains('active_plan_low_usage', $second[0]['meta']['signals']);
        $this->assertContains('active_plan_very_low_usage', $second[0]['meta']['signals']);
        $this->assertContains('active_plan_low_usage_with_many_ua', $second[0]['meta']['signals']);
        $this->assertGreaterThanOrEqual(80, $second[0]['meta']['risk_score']);
    }

    public function test_leak_guard_strict_mode_blocks_known_client_from_new_pull_ip(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_leak_guard' => true,
            'enable_leak_guard_strict_mode' => true,
            'leak_guard_score_threshold' => 80,
            'leak_guard_allowed_ip_count' => 1,
            'ip_region_overrides' => [
                '1.1.1.1' => 'US',
                '1.1.1.2' => 'US',
            ],
        ]);

        $first = $analyzer->inspectSubscriptionPull(1009, 'token-i', '1.1.1.1', 'Sparkle/1.0.0');
        $second = $analyzer->inspectSubscriptionPull(1009, 'token-i', '1.1.1.2', 'Sparkle/1.0.0');

        $this->assertSame([], $first);
        $this->assertCount(1, $second);
        $this->assertSame('subscription_leak_guard', $second[0]['code']);
        $this->assertSame('reset_token_uuid', $second[0]['action']);
        $this->assertContains('new_pull_ip', $second[0]['meta']['signals']);
        $this->assertContains('many_pull_ips', $second[0]['meta']['signals']);
    }

    public function test_leak_guard_escalates_repeated_hits_to_credential_reset(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_leak_guard' => true,
            'enable_leak_guard_escalation' => true,
            'leak_guard_action' => 'empty',
            'leak_guard_escalate_hits' => 2,
            'leak_guard_escalate_action' => 'reset_token_uuid',
            'leak_guard_score_threshold' => 80,
            'ip_region_overrides' => [
                '1.1.1.1' => 'US',
                '2.2.2.2' => 'JP',
            ],
        ]);

        $first = $analyzer->inspectSubscriptionPull(
            1010,
            'token-j',
            '2.2.2.2',
            'curl/8.5.0',
            ['online_ips' => ['1.1.1.1']]
        );
        $second = $analyzer->inspectSubscriptionPull(
            1010,
            'token-j',
            '2.2.2.2',
            'curl/8.5.0',
            ['online_ips' => ['1.1.1.1']]
        );

        $this->assertSame('empty', $first[0]['action']);
        $this->assertSame(1, $first[0]['meta']['hit_count']);
        $this->assertSame('reset_token_uuid', $second[0]['action']);
        $this->assertSame(2, $second[0]['meta']['hit_count']);
    }

    public function test_source_batch_detection_flags_same_ip_and_ua_across_many_users(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_source_batch_detection' => true,
            'source_batch_user_threshold' => 3,
            'source_batch_window_seconds' => 600,
            'source_batch_action' => 'empty',
        ]);

        $this->assertSame([], $analyzer->inspectSubscriptionPull(1101, 'token-k1', '3.3.3.3', 'Sparkle/1.0.0'));
        $this->assertSame([], $analyzer->inspectSubscriptionPull(1102, 'token-k2', '3.3.3.3', 'Sparkle/1.0.0'));
        $decisions = $analyzer->inspectSubscriptionPull(1103, 'token-k3', '3.3.3.3', 'Sparkle/1.0.0');

        $this->assertCount(1, $decisions);
        $this->assertSame('source_batch_pull', $decisions[0]['code']);
        $this->assertSame('empty', $decisions[0]['action']);
        $this->assertSame(3, $decisions[0]['meta']['source_user_count']);
        $this->assertSame('sparkle', $decisions[0]['meta']['ua_category']);
    }

    public function test_source_batch_detection_counts_same_ip_across_rotating_user_agents(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_source_batch_detection' => true,
            'source_batch_user_threshold' => 3,
            'source_batch_window_seconds' => 600,
            'source_batch_action' => 'reset_token_uuid',
        ]);

        $this->assertSame([], $analyzer->inspectSubscriptionPull(1141, 'token-o1', '4.4.4.4', 'Sparkle/1.0.0'));
        $this->assertSame([], $analyzer->inspectSubscriptionPull(1142, 'token-o2', '4.4.4.4', 'sing-box/1.12.0'));
        $decisions = $analyzer->inspectSubscriptionPull(1143, 'token-o3', '4.4.4.4', 'curl/8.5.0');

        $this->assertCount(1, $decisions);
        $this->assertSame('source_batch_pull', $decisions[0]['code']);
        $this->assertSame('reset_token_uuid', $decisions[0]['action']);
        $this->assertSame(3, $decisions[0]['meta']['source_user_count']);
        $this->assertSame('script', $decisions[0]['meta']['ua_category']);
        $this->assertSame(['script', 'sing-box', 'sparkle'], $decisions[0]['meta']['source_ua_categories']);
    }

    public function test_source_batch_detection_keeps_different_source_ips_separate(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_source_batch_detection' => true,
            'source_batch_user_threshold' => 3,
            'source_batch_window_seconds' => 600,
            'source_batch_action' => 'empty',
        ]);

        $this->assertSame([], $analyzer->inspectSubscriptionPull(1111, 'token-l1', '3.3.3.1', 'Sparkle/1.0.0'));
        $this->assertSame([], $analyzer->inspectSubscriptionPull(1112, 'token-l2', '3.3.3.2', 'Sparkle/1.0.0'));
        $this->assertSame([], $analyzer->inspectSubscriptionPull(1113, 'token-l3', '3.3.3.3', 'Sparkle/1.0.0'));
    }

    public function test_trusted_egress_ip_is_excluded_from_source_batch_detection(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_source_batch_detection' => true,
            'source_batch_user_threshold' => 3,
            'trusted_egress_ips' => "3.3.3.3\n2001:db8::/32",
        ]);

        $this->assertSame([], $analyzer->inspectSubscriptionPull(1121, 'token-m1', '3.3.3.3', 'Sparkle/1.0.0'));
        $this->assertSame([], $analyzer->inspectSubscriptionPull(1122, 'token-m2', '3.3.3.3', 'Sparkle/1.0.0'));
        $this->assertSame([], $analyzer->inspectSubscriptionPull(1123, 'token-m3', '3.3.3.3', 'Sparkle/1.0.0'));
    }

    public function test_trusted_egress_ip_can_be_checked_by_legacy_plugin_rules(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'trusted_egress_ips' => "3.3.3.0/24\n2001:db8::/32",
        ]);

        $this->assertTrue($analyzer->isTrustedEgressIp('3.3.3.3'));
        $this->assertTrue($analyzer->isTrustedEgressIp('2001:db8::8'));
        $this->assertFalse($analyzer->isTrustedEgressIp('4.4.4.4'));
    }

    public function test_source_ip_denylist_blocks_matching_cidr_without_resetting_credentials(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_source_ip_denylist' => true,
            'source_ip_deny_cidrs' => '107.150.104.0/21',
            'source_ip_deny_action' => 'block',
            'enable_ip_intelligence' => false,
        ]);

        $decisions = $analyzer->inspectSubscriptionPull(1161, 'token-deny-cidr', '107.150.111.5', 'Sparkle/1.0.0');

        $this->assertCount(1, $decisions);
        $this->assertSame('source_ip_denylist', $decisions[0]['code']);
        $this->assertSame('block', $decisions[0]['action']);
        $this->assertSame('cidr', $decisions[0]['meta']['source_ip_deny_match_type']);
        $this->assertSame('107.150.104.0/21', $decisions[0]['meta']['source_ip_deny_match']);
    }

    public function test_source_ip_denylist_blocks_ucloud_by_asn_and_org_keyword(): void
    {
        $intelligence = new SubscriptionIpIntelligenceService([], function (string $query): array {
            return match ($query) {
                '5.111.150.107.origin.asn.cymru.com' => ['135377 | 107.150.111.5 | 107.150.104.0/21 | US | arin | 2020-01-01'],
                'AS135377.asn.cymru.com' => ['135377 | US | arin | 2016-01-01 | UCLOUD INFORMATION TECHNOLOGY (HK) LIMITED'],
                default => [],
            };
        });
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_source_ip_denylist' => true,
            'source_ip_deny_asns' => "AS135377\n59077",
            'source_ip_deny_org_keywords' => 'ucloud',
            'source_ip_deny_action' => 'block',
        ], $intelligence);

        $decisions = $analyzer->inspectSubscriptionPull(1162, 'token-deny-asn', '107.150.111.5', 'Sparkle/1.0.0');

        $this->assertCount(1, $decisions);
        $this->assertSame('source_ip_denylist', $decisions[0]['code']);
        $this->assertSame('block', $decisions[0]['action']);
        $this->assertSame('asn', $decisions[0]['meta']['source_ip_deny_match_type']);
        $this->assertSame('AS135377', $decisions[0]['meta']['source_ip_deny_match']);
        $this->assertSame(135377, $decisions[0]['meta']['ip_asn']);
        $this->assertSame('hosting', $decisions[0]['meta']['ip_type']);
        $this->assertSame('UCLOUD INFORMATION TECHNOLOGY (HK) LIMITED', $decisions[0]['meta']['ip_org']);
    }

    public function test_source_ip_denylist_blocks_by_org_keyword_when_asn_is_not_listed(): void
    {
        $intelligence = new SubscriptionIpIntelligenceService([], function (string $query): array {
            return match ($query) {
                '6.111.150.107.origin.asn.cymru.com' => ['135377 | 107.150.111.6 | 107.150.104.0/21 | US | arin | 2020-01-01'],
                'AS135377.asn.cymru.com' => ['135377 | US | arin | 2016-01-01 | UCLOUD INFORMATION TECHNOLOGY (HK) LIMITED'],
                default => [],
            };
        });
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_source_ip_denylist' => true,
            'source_ip_deny_asns' => '59077',
            'source_ip_deny_org_keywords' => 'ucloud',
            'source_ip_deny_action' => 'block',
        ], $intelligence);

        $decisions = $analyzer->inspectSubscriptionPull(1163, 'token-deny-org', '107.150.111.6', 'Sparkle/1.0.0');

        $this->assertCount(1, $decisions);
        $this->assertSame('source_ip_denylist', $decisions[0]['code']);
        $this->assertSame('block', $decisions[0]['action']);
        $this->assertSame('org', $decisions[0]['meta']['source_ip_deny_match_type']);
        $this->assertSame('ucloud', $decisions[0]['meta']['source_ip_deny_match']);
        $this->assertSame(135377, $decisions[0]['meta']['ip_asn']);
    }

    public function test_source_ip_denylist_blocks_major_china_clouds_by_org_keyword(): void
    {
        $intelligence = new SubscriptionIpIntelligenceService([], function (string $query): array {
            return match ($query) {
                '8.8.8.8.origin.asn.cymru.com' => ['45102 | 8.8.8.8 | 8.8.8.0/24 | US | arin | 2020-01-01'],
                'AS45102.asn.cymru.com' => ['45102 | US | arin | 2010-01-01 | Alibaba (US) Technology Co., Ltd.'],
                '9.9.9.9.origin.asn.cymru.com' => ['45090 | 9.9.9.9 | 9.9.9.0/24 | CN | apnic | 2020-01-01'],
                'AS45090.asn.cymru.com' => ['45090 | CN | apnic | 2011-01-01 | Shenzhen Tencent Computer Systems Company Limited, CN'],
                '10.10.10.11.origin.asn.cymru.com' => ['136907 | 11.10.10.10 | 11.10.10.0/24 | HK | apnic | 2020-01-01'],
                'AS136907.asn.cymru.com' => ['136907 | HK | apnic | 2017-01-01 | HUAWEI CLOUDS'],
                default => [],
            };
        });
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_source_ip_denylist' => true,
            'source_ip_deny_org_keywords' => "alibaba\ntencent\nhuawei cloud\nhuawei clouds",
            'source_ip_deny_action' => 'block',
        ], $intelligence);

        foreach (['8.8.8.8', '9.9.9.9', '11.10.10.10'] as $index => $ip) {
            $decisions = $analyzer->inspectSubscriptionPull(1170 + $index, "token-deny-cloud-{$index}", $ip, 'Sparkle/1.0.0');

            $this->assertCount(1, $decisions);
            $this->assertSame('source_ip_denylist', $decisions[0]['code']);
            $this->assertSame('block', $decisions[0]['action']);
            $this->assertSame('org', $decisions[0]['meta']['source_ip_deny_match_type']);
        }
    }

    public function test_trusted_egress_ip_is_excluded_from_leak_guard_ip_signals(): void
    {
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_leak_guard' => true,
            'enable_leak_guard_strict_mode' => true,
            'leak_guard_score_threshold' => 80,
            'leak_guard_allowed_ip_count' => 1,
            'trusted_egress_ips' => '3.3.3.0/24',
            'ip_region_overrides' => [
                '3.3.3.1' => 'US',
                '3.3.3.2' => 'JP',
            ],
        ]);

        $this->assertSame([], $analyzer->inspectSubscriptionPull(1131, 'token-n', '3.3.3.1', 'Sparkle/1.0.0'));
        $this->assertSame([], $analyzer->inspectSubscriptionPull(1131, 'token-n', '3.3.3.2', 'Sparkle/1.0.0'));
    }

    public function test_leak_guard_uses_ip_intelligence_as_auxiliary_score_and_metadata(): void
    {
        $intelligence = new SubscriptionIpIntelligenceService([], function (string $query): array {
            return match ($query) {
                '4.3.2.1.origin.asn.cymru.com' => ['45090 | 1.2.3.4 | 1.2.3.0/24 | CN | apnic | 2020-01-01'],
                'AS45090.asn.cymru.com' => ['45090 | CN | apnic | 2011-01-01 | TENCENT-NET-AP Shenzhen Tencent Computer Systems Company Limited, CN'],
                default => [],
            };
        });
        $analyzer = new SubscriptionRiskAnalyzer([
            'enable_leak_guard' => true,
            'enable_ip_intelligence' => true,
            'ip_intelligence_score_weight' => 25,
            'leak_guard_score_threshold' => 20,
        ], $intelligence);

        $decisions = $analyzer->inspectSubscriptionPull(1151, 'token-p', '1.2.3.4', 'Sparkle/1.0.0');

        $this->assertCount(1, $decisions);
        $this->assertSame('subscription_leak_guard', $decisions[0]['code']);
        $this->assertContains('ip_intelligence_hosting', $decisions[0]['meta']['signals']);
        $this->assertSame(45090, $decisions[0]['meta']['ip_asn']);
        $this->assertSame('hosting', $decisions[0]['meta']['ip_type']);
        $this->assertContains('cloud_provider', $decisions[0]['meta']['ip_risk_tags']);
    }
}

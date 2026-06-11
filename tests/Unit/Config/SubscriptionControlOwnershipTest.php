<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Http\Requests\Admin\ConfigSave;
use Tests\TestCase;

final class SubscriptionControlOwnershipTest extends TestCase
{
    public function test_core_config_save_no_longer_accepts_subscription_control_settings(): void
    {
        $legacyKeys = array_filter(
            array_keys(ConfigSave::RULES),
            static fn(string $key): bool => str_starts_with($key, 'subscription_control_')
        );

        $this->assertSame([], array_values($legacyKeys));
    }

    public function test_core_scheduler_no_longer_runs_subscription_control_command(): void
    {
        $kernel = file_get_contents(dirname(__DIR__, 3) . '/app/Console/Kernel.php');

        $this->assertIsString($kernel);
        $this->assertStringNotContainsString('subscription-control:enforce', $kernel);
    }

    public function test_subscription_control_source_denylist_defaults_cover_major_china_clouds(): void
    {
        $path = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($config);
        $items = $config['config'] ?? [];
        $asns = (string) ($items['source_ip_deny_asns']['default'] ?? '');
        $keywords = strtolower((string) ($items['source_ip_deny_org_keywords']['default'] ?? ''));

        foreach (['AS135377', 'AS59077', 'AS45102', 'AS37963', 'AS134963', 'AS24429', 'AS45090', 'AS133478', 'AS132203', 'AS136907', 'AS55990', 'AS131444'] as $asn) {
            $this->assertStringContainsString($asn, $asns);
        }

        foreach (['ucloud', 'aliyun', 'alibaba', 'tencent', 'huawei cloud', 'huaweicloud', 'baidu cloud', 'volcengine', 'tianyi cloud', 'china mobile cloud'] as $keyword) {
            $this->assertStringContainsString($keyword, $keywords);
        }

        $this->assertStringNotContainsString("\nmobile cloud", $keywords);
    }

    public function test_subscription_control_source_denylist_is_enabled_by_default(): void
    {
        $path = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($config);
        $items = $config['config'] ?? [];

        $this->assertTrue((bool) ($items['enable_ua_blacklist']['default'] ?? false));
        $this->assertTrue((bool) ($items['enable_source_ip_denylist']['default'] ?? false));
        $this->assertTrue((bool) ($items['enable_node_source_ip_managed_routes']['default'] ?? false));
        $this->assertSame('block', (string) ($items['source_ip_deny_action']['default'] ?? ''));
    }

    public function test_subscription_control_default_config_enables_anti_gfw_baseline(): void
    {
        $path = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($config);
        $items = $config['config'] ?? [];

        foreach ([
            'enable_ua_blacklist',
            'enable_ua_block_only',
            'enable_ua_reset_token',
            'enable_source_ip_denylist',
            'enable_node_source_ip_managed_routes',
            'enable_node_source_ip_route_learned_prefixes',
            'enable_node_source_ip_builtin_provider_cidrs',
            'enable_node_source_ip_bgp_prefix_refresh',
            'enable_source_batch_detection',
            'enable_leak_guard',
            'enable_multi_ua_detection',
            'enable_multi_region_pull_detection',
            'enable_multi_region_online_detection',
        ] as $key) {
            $this->assertTrue((bool) ($items[$key]['default'] ?? false), $key . ' should be enabled by default');
        }

        $this->assertFalse((bool) ($items['enable_client_ua_whitelist']['default'] ?? true), 'UA whitelist should not be enabled by default');

        foreach ([
            'source_batch_action',
            'leak_guard_action',
            'multi_ua_action',
            'multi_region_pull_action',
            'multi_region_online_action',
        ] as $key) {
            $this->assertSame('reset_token_uuid', (string) ($items[$key]['default'] ?? ''), $key . ' should reset credentials');
        }

        $this->assertSame('block', (string) ($items['source_ip_deny_action']['default'] ?? ''));
    }

    public function test_subscription_control_malicious_ua_blacklist_defaults_are_explicit(): void
    {
        $path = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($config);
        $items = $config['config'] ?? [];
        $blacklist = strtolower((string) ($items['ua_blacklist']['default'] ?? ''));
        $resetList = strtolower((string) ($items['ua_reset_keywords']['default'] ?? ''));

        foreach ([
            'censys',
            'java-http-client',
            'apache-httpclient',
            'shodan',
            'zgrab',
            'zmap',
            'masscan',
            'nuclei',
            'sqlmap',
            'nikto',
            'daed',
            'matsuri',
            'sub_ua',
            'scan',
            'chrome/16.0.912.77',
            'webrequesthelper',
        ] as $keyword) {
            $this->assertStringContainsString($keyword, $blacklist);
            $this->assertStringNotContainsString($keyword, $resetList);
        }

        $blacklistLines = array_map('trim', preg_split('/[\r\n]+/', $blacklist) ?: []);
        $this->assertNotContains('mozilla', $blacklistLines);
    }

    public function test_subscription_control_default_ua_policy_uses_negative_rules_not_whitelist(): void
    {
        $path = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($config);
        $items = $config['config'] ?? [];

        $this->assertFalse((bool) ($items['enable_client_ua_whitelist']['default'] ?? true));
        $this->assertTrue((bool) ($items['enable_ua_blacklist']['default'] ?? false));
        $this->assertTrue((bool) ($items['enable_ua_reset_token']['default'] ?? false));
        $this->assertTrue((bool) ($items['enable_ua_block_only']['default'] ?? false));

        $blockOnly = (string) ($items['ua_block_only_keywords']['default'] ?? '');
        foreach (['Mozilla', 'Chrome', 'Safari', 'Firefox', 'Edg/'] as $keyword) {
            $this->assertStringContainsString($keyword, $blockOnly);
        }

        $resetList = (string) ($items['ua_reset_keywords']['default'] ?? '');
        foreach (['Mozilla', 'Telegram', 'WeChat', 'QQ'] as $keyword) {
            $this->assertStringNotContainsString($keyword, $resetList);
        }
    }

    public function test_subscription_control_ua_keyword_lists_use_multiline_inputs(): void
    {
        $path = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($config);
        $items = $config['config'] ?? [];

        foreach (['ua_blacklist', 'ua_block_only_keywords', 'ua_reset_keywords'] as $key) {
            $this->assertSame('text', (string) ($items[$key]['type'] ?? ''), $key . ' should render as a multiline textarea');
        }
    }
}

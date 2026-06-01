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

        $this->assertTrue((bool) ($items['enable_source_ip_denylist']['default'] ?? false));
        $this->assertTrue((bool) ($items['enable_node_source_ip_managed_routes']['default'] ?? false));
    }
}

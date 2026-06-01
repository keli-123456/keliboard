<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use Plugin\SubscriptionControl\Plugin;
use ReflectionMethod;
use Tests\TestCase;

final class SubscriptionControlPluginTest extends TestCase
{
    public function test_ua_reset_treats_browser_and_social_app_keywords_as_risky(): void
    {
        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'ua_reset_keywords' => "Mozilla\nqq\nTelegram\nWeChat\nBadBot",
        ]);

        $isResetUa = new ReflectionMethod($plugin, 'isResetUA');
        $isResetUa->setAccessible(true);

        $this->assertTrue($isResetUa->invoke(
            $plugin,
            strtolower('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/138.0 Safari/537.36')
        ));
        $this->assertTrue($isResetUa->invoke(
            $plugin,
            strtolower('Mozilla/5.0 MQQBrowser/20.2 Mobile Safari/537.36 QQ/9.2.90')
        ));
        $this->assertTrue($isResetUa->invoke($plugin, strtolower('TelegramBot (like TwitterBot)')));
        $this->assertTrue($isResetUa->invoke($plugin, strtolower('WeChat/8.0.45 MicroMessenger/8.0.45')));
        $this->assertTrue($isResetUa->invoke($plugin, strtolower('BadBot/1.0')));
    }

    public function test_risk_actions_always_reset_credentials(): void
    {
        $plugin = new Plugin('subscription_control');
        $normalize = new ReflectionMethod($plugin, 'normalizeRiskAction');
        $normalize->setAccessible(true);

        foreach (['observe', ' OBSERVE ', 'block', 'empty', 'throttle', 'reset_token', 'reset_token_uuid', '限制访问', ''] as $action) {
            $this->assertSame('reset_token_uuid', $normalize->invoke($plugin, $action));
        }
    }

    public function test_source_ip_denylist_only_blocks_without_resetting_credentials(): void
    {
        $plugin = new Plugin('subscription_control');
        $normalize = new ReflectionMethod($plugin, 'normalizeRiskAction');
        $normalize->setAccessible(true);

        foreach (['observe', 'block', 'empty', 'throttle', 'reset_token', 'reset_token_uuid', ''] as $action) {
            $this->assertSame('block', $normalize->invoke($plugin, $action, 'source_ip_denylist'));
        }
    }
}

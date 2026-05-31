<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use Plugin\SubscriptionControl\Plugin;
use ReflectionMethod;
use Tests\TestCase;

final class SubscriptionControlPluginTest extends TestCase
{
    public function test_ua_reset_ignores_common_browser_and_social_app_keywords(): void
    {
        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'ua_reset_keywords' => "Mozilla\nqq\nTelegram\nBadBot",
        ]);

        $isResetUa = new ReflectionMethod($plugin, 'isResetUA');
        $isResetUa->setAccessible(true);

        $this->assertFalse($isResetUa->invoke(
            $plugin,
            strtolower('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/138.0 Safari/537.36')
        ));
        $this->assertFalse($isResetUa->invoke(
            $plugin,
            strtolower('Mozilla/5.0 MQQBrowser/20.2 Mobile Safari/537.36 QQ/9.2.90')
        ));
        $this->assertFalse($isResetUa->invoke($plugin, strtolower('TelegramBot (like TwitterBot)')));
        $this->assertTrue($isResetUa->invoke($plugin, strtolower('BadBot/1.0')));
    }
}

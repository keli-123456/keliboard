<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Services\Plugin\InterceptResponseException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Plugin\SubscriptionControl\Plugin;
use ReflectionMethod;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionControlPluginTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

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

    public function test_ua_blacklist_blocks_without_resetting_credentials(): void
    {
        $plugin = new Plugin('subscription_control');
        $normalize = new ReflectionMethod($plugin, 'normalizeRiskAction');
        $normalize->setAccessible(true);

        foreach (['observe', 'block', 'empty', 'throttle', 'reset_token', 'reset_token_uuid', ''] as $action) {
            $this->assertSame('block', $normalize->invoke($plugin, $action, 'ua_blacklist'));
        }
    }

    public function test_ua_block_only_blocks_without_resetting_credentials(): void
    {
        $plugin = new Plugin('subscription_control');
        $normalize = new ReflectionMethod($plugin, 'normalizeRiskAction');
        $normalize->setAccessible(true);

        foreach (['observe', 'block', 'empty', 'throttle', 'reset_token', 'reset_token_uuid', ''] as $action) {
            $this->assertSame('block', $normalize->invoke($plugin, $action, 'ua_block_only'));
        }
    }

    public function test_client_ua_not_allowed_can_block_without_resetting_credentials(): void
    {
        $plugin = new Plugin('subscription_control');
        $normalize = new ReflectionMethod($plugin, 'normalizeRiskAction');
        $normalize->setAccessible(true);

        $this->assertSame('block', $normalize->invoke($plugin, 'block', 'client_ua_not_allowed'));
        $this->assertSame('reset_token_uuid', $normalize->invoke($plugin, 'reset_token_uuid', 'client_ua_not_allowed'));
    }

    public function test_ua_blacklist_treats_none_keyword_as_empty_user_agent(): void
    {
        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'ua_blacklist' => "Censys\nNone",
        ]);

        $isBlacklistedUa = new ReflectionMethod($plugin, 'isBlacklistedUA');
        $isBlacklistedUa->setAccessible(true);

        $this->assertTrue($isBlacklistedUa->invoke($plugin, ''));
        $this->assertTrue($isBlacklistedUa->invoke($plugin, 'censysinspect/1.1'));
        $this->assertFalse($isBlacklistedUa->invoke($plugin, 'sparkle/1.0.0'));
    }

    public function test_default_ua_blacklist_includes_social_platform_preview_user_agents(): void
    {
        $configPath = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($configPath), true);
        $defaultBlacklist = (string) ($config['config']['ua_blacklist']['default'] ?? '');

        foreach (['Telegram', 'TelegramBot', 'WeChat', 'Weixin', 'MicroMessenger', 'QQ', 'MQQBrowser', 'Weibo'] as $keyword) {
            $this->assertStringContainsString($keyword, $defaultBlacklist);
        }

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'ua_blacklist' => $defaultBlacklist,
        ]);
        $isBlacklistedUa = new ReflectionMethod($plugin, 'isBlacklistedUA');
        $isBlacklistedUa->setAccessible(true);

        $this->assertTrue($isBlacklistedUa->invoke($plugin, strtolower('TelegramBot (like TwitterBot)')));
        $this->assertTrue($isBlacklistedUa->invoke($plugin, strtolower('WeChat/8.0.45 MicroMessenger/8.0.45')));
        $this->assertTrue($isBlacklistedUa->invoke($plugin, strtolower('Mozilla/5.0 MQQBrowser/20.2 Mobile Safari/537.36 QQ/9.2.90')));
        $this->assertTrue($isBlacklistedUa->invoke($plugin, strtolower('Weibo/13.0.0')));
    }

    public function test_malicious_ua_persists_public_client_ip_to_source_denylist(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createPluginTable();

        PluginModel::create([
            'code' => 'subscription_control',
            'name' => '订阅风控',
            'description' => '',
            'version' => '1.5.24',
            'author' => '',
            'url' => '',
            'email' => '',
            'config' => json_encode([
                'enable_source_ip_denylist' => false,
                'source_ip_deny_cidrs' => "9.9.9.9",
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'enable_source_ip_denylist' => false,
            'source_ip_deny_cidrs' => "9.9.9.9",
        ]);

        $persist = new ReflectionMethod($plugin, 'persistPermanentSourceIpDeny');
        $persist->setAccessible(true);
        $meta = [];

        $this->assertTrue($persist->invokeArgs($plugin, ['8.8.8.8', false, &$meta]));
        $this->assertTrue($meta['permanent_source_ip_blocked']);
        $this->assertSame('added', $meta['permanent_source_ip_block_status']);

        $config = json_decode((string) PluginModel::query()->where('code', 'subscription_control')->value('config'), true);
        $this->assertTrue((bool) $config['enable_source_ip_denylist']);
        $this->assertSame("9.9.9.9\n8.8.8.8", $config['source_ip_deny_cidrs']);

        $this->assertTrue($persist->invokeArgs($plugin, ['8.8.8.8', false, &$meta]));
        $config = json_decode((string) PluginModel::query()->where('code', 'subscription_control')->value('config'), true);
        $this->assertSame("9.9.9.9\n8.8.8.8", $config['source_ip_deny_cidrs']);
    }

    public function test_malicious_ua_does_not_persist_trusted_or_non_public_client_ip(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createPluginTable();

        PluginModel::create([
            'code' => 'subscription_control',
            'name' => '订阅风控',
            'description' => '',
            'version' => '1.5.24',
            'author' => '',
            'url' => '',
            'email' => '',
            'config' => json_encode([
                'enable_source_ip_denylist' => true,
                'source_ip_deny_cidrs' => "9.9.9.9",
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'enable_source_ip_denylist' => true,
            'source_ip_deny_cidrs' => "9.9.9.9",
        ]);

        $persist = new ReflectionMethod($plugin, 'persistPermanentSourceIpDeny');
        $persist->setAccessible(true);

        $trustedMeta = [];
        $privateMeta = [];

        $this->assertFalse($persist->invokeArgs($plugin, ['8.8.4.4', true, &$trustedMeta]));
        $this->assertSame('trusted_egress', $trustedMeta['permanent_source_ip_block_status']);

        $this->assertFalse($persist->invokeArgs($plugin, ['127.0.0.1', false, &$privateMeta]));
        $this->assertSame('non_public_ip', $privateMeta['permanent_source_ip_block_status']);

        $config = json_decode((string) PluginModel::query()->where('code', 'subscription_control')->value('config'), true);
        $this->assertSame("9.9.9.9", $config['source_ip_deny_cidrs']);
    }

    public function test_malicious_ua_blacklist_runs_before_client_whitelist_and_persists_source_ip(): void
    {
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createPluginTable();
        $this->createUserTable();

        PluginModel::create([
            'code' => 'subscription_control',
            'name' => '订阅风控',
            'description' => '',
            'version' => '1.5.24',
            'author' => '',
            'url' => '',
            'email' => '',
            'config' => json_encode([
                'enable_ua_blacklist' => true,
                'ua_blacklist' => "Censys\nNone",
                'enable_client_ua_whitelist' => true,
                'client_ua_unknown_action' => 'reset_token_uuid',
                'enable_auto_trusted_node_ips' => false,
                'enable_source_ip_denylist' => false,
                'source_ip_deny_cidrs' => '',
                'enable_email_notice' => false,
                'enable_telegram_notice' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $user = User::query()->create([
            'email' => 'risk@example.test',
            'token' => 'old-token',
            'uuid' => 'old-uuid',
            'u' => 0,
            'd' => 0,
        ]);

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'enable_ua_blacklist' => true,
            'ua_blacklist' => "Censys\nNone",
            'enable_client_ua_whitelist' => true,
            'client_ua_unknown_action' => 'reset_token_uuid',
            'enable_auto_trusted_node_ips' => false,
            'enable_source_ip_denylist' => false,
            'source_ip_deny_cidrs' => '',
            'enable_email_notice' => false,
            'enable_telegram_notice' => false,
        ]);

        $request = Request::create('/api/v1/client/subscribe', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_USER_AGENT' => 'CensysInspect/1.1',
        ]);

        try {
            $plugin->checkSubscribeAccess([], $user, $request);
            $this->fail('Expected subscription request to be intercepted.');
        } catch (InterceptResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
        }

        $user->refresh();
        $this->assertSame('old-token', $user->token);
        $this->assertSame('old-uuid', $user->uuid);

        $config = json_decode((string) PluginModel::query()->where('code', 'subscription_control')->value('config'), true);
        $this->assertTrue((bool) $config['enable_source_ip_denylist']);
        $this->assertSame('8.8.8.8', $config['source_ip_deny_cidrs']);

        $event = Cache::get("subscription_control:last_event:{$user->id}");
        $this->assertSame('ua_blacklist', $event['code']);
        $this->assertSame('block', $event['action']);
    }

    public function test_browser_ua_block_only_runs_before_client_whitelist_without_resetting_credentials(): void
    {
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createPluginTable();
        $this->createUserTable();

        PluginModel::create([
            'code' => 'subscription_control',
            'name' => '订阅风控',
            'description' => '',
            'version' => '1.5.24',
            'author' => '',
            'url' => '',
            'email' => '',
            'config' => json_encode([
                'enable_ua_blacklist' => false,
                'enable_ua_block_only' => true,
                'ua_block_only_keywords' => "Mozilla\nChrome\nSafari",
                'enable_client_ua_whitelist' => true,
                'client_ua_unknown_action' => 'reset_token_uuid',
                'enable_auto_trusted_node_ips' => false,
                'enable_source_ip_denylist' => false,
                'source_ip_deny_cidrs' => '',
                'enable_email_notice' => false,
                'enable_telegram_notice' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $user = User::query()->create([
            'email' => 'browser@example.test',
            'token' => 'old-token',
            'uuid' => 'old-uuid',
            'u' => 0,
            'd' => 0,
        ]);

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'enable_ua_blacklist' => false,
            'enable_ua_block_only' => true,
            'ua_block_only_keywords' => "Mozilla\nChrome\nSafari",
            'enable_client_ua_whitelist' => true,
            'client_ua_unknown_action' => 'reset_token_uuid',
            'enable_auto_trusted_node_ips' => false,
            'enable_source_ip_denylist' => false,
            'source_ip_deny_cidrs' => '',
            'enable_email_notice' => false,
            'enable_telegram_notice' => false,
        ]);

        $request = Request::create('/api/v1/client/subscribe', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/138.0 Safari/537.36',
        ]);

        try {
            $plugin->checkSubscribeAccess([], $user, $request);
            $this->fail('Expected browser subscription request to be intercepted.');
        } catch (InterceptResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
        }

        $user->refresh();
        $this->assertSame('old-token', $user->token);
        $this->assertSame('old-uuid', $user->uuid);

        $config = json_decode((string) PluginModel::query()->where('code', 'subscription_control')->value('config'), true);
        $this->assertFalse((bool) $config['enable_source_ip_denylist']);
        $this->assertSame('', $config['source_ip_deny_cidrs']);

        $event = Cache::get("subscription_control:last_event:{$user->id}");
        $this->assertSame('ua_block_only', $event['code']);
        $this->assertSame('block', $event['action']);
    }

    private function createPluginTable(): void
    {
        $this->database->schema()->create('v2_plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name')->default('');
            $table->text('description')->nullable();
            $table->string('version')->default('1.0.0');
            $table->string('author')->nullable();
            $table->string('url')->nullable();
            $table->string('email')->nullable();
            $table->string('license')->nullable();
            $table->string('requires')->nullable();
            $table->text('config')->nullable();
            $table->string('type')->default(PluginModel::TYPE_FEATURE);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }
}

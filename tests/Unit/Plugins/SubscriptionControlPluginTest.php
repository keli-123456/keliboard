<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\AgentUser;
use App\Models\Plugin as PluginModel;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\Plugin\InterceptResponseException;
use Illuminate\Contracts\Bus\Dispatcher;
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

    public function test_behavior_baseline_observation_is_never_promoted_to_enforcement(): void
    {
        $plugin = new Plugin('subscription_control');
        $normalize = new ReflectionMethod($plugin, 'normalizeRiskAction');
        $normalize->setAccessible(true);

        foreach (['observe', 'block', 'empty', 'throttle', 'reset_token_uuid', ''] as $action) {
            $this->assertSame(
                'observe',
                $normalize->invoke($plugin, $action, 'behavior_baseline_observation')
            );
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

    public function test_ua_blacklist_allows_v2raya_webrequesthelper_but_blocks_other_webrequesthelper_agents(): void
    {
        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'ua_blacklist' => "Censys\ndaed\nWebRequestHelper",
        ]);

        $isBlacklistedUa = new ReflectionMethod($plugin, 'isBlacklistedUA');
        $isBlacklistedUa->setAccessible(true);

        $this->assertFalse($isBlacklistedUa->invoke($plugin, strtolower('v2rayA/2.2.7.5 WebRequestHelper')));
        $this->assertTrue($isBlacklistedUa->invoke($plugin, strtolower('v2rayN/1.0 WebRequestHelper')));
        $this->assertTrue($isBlacklistedUa->invoke($plugin, strtolower('WebRequestHelper')));
        $this->assertTrue($isBlacklistedUa->invoke($plugin, strtolower('daed/v0.4.0rc1 (like v2rayA/1.0 WebRequestHelper)')));
        $this->assertTrue($isBlacklistedUa->invoke($plugin, strtolower('CensysInspect/1.1 v2rayA/2.2.7.5 WebRequestHelper')));
    }

    public function test_default_ua_blacklist_excludes_social_platform_preview_user_agents(): void
    {
        $configPath = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($configPath), true);
        $defaultBlacklist = (string) ($config['config']['ua_blacklist']['default'] ?? '');
        $defaultBlockOnly = (string) ($config['config']['ua_block_only_keywords']['default'] ?? '');

        foreach (['Telegram', 'TelegramBot', 'WeChat', 'Weixin', 'MicroMessenger', 'QQ', 'MQQBrowser', 'Weibo'] as $keyword) {
            $this->assertStringNotContainsString($keyword, $defaultBlacklist);
            $this->assertStringContainsString($keyword, $defaultBlockOnly);
        }

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'ua_blacklist' => $defaultBlacklist,
            'ua_block_only_keywords' => $defaultBlockOnly,
        ]);
        $isBlacklistedUa = new ReflectionMethod($plugin, 'isBlacklistedUA');
        $isBlacklistedUa->setAccessible(true);
        $isBlockOnlyUa = new ReflectionMethod($plugin, 'isBlockOnlyUA');
        $isBlockOnlyUa->setAccessible(true);

        foreach ([
            'TelegramBot (like TwitterBot)',
            'WeChat/8.0.45 MicroMessenger/8.0.45',
            'Mozilla/5.0 MQQBrowser/20.2 Mobile Safari/537.36 QQ/9.2.90',
            'Weibo/13.0.0',
        ] as $userAgent) {
            $normalized = strtolower($userAgent);
            $this->assertFalse($isBlacklistedUa->invoke($plugin, $normalized), $userAgent);
            $this->assertTrue($isBlockOnlyUa->invoke($plugin, $normalized), $userAgent);
        }
    }

    public function test_default_ua_blacklist_matches_scanner_user_agents(): void
    {
        $configPath = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($configPath), true);
        $defaultBlacklist = (string) ($config['config']['ua_blacklist']['default'] ?? '');

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'ua_blacklist' => $defaultBlacklist,
        ]);
        $isBlacklistedUa = new ReflectionMethod($plugin, 'isBlacklistedUA');
        $isBlacklistedUa->setAccessible(true);

        foreach ([
            '',
            'CensysInspect/1.1',
            'Mozilla/5.0 (bang2013@atomicmail.io)',
            'Java-http-client/17',
            'Apache-HttpClient/4.5.13',
            'Shodan/1.0',
            'zgrab/0.x',
            'zmap',
            'masscan/1.3',
            'nuclei - Open-source project',
            'sqlmap/1.8',
            'Nikto/2.5.0',
            'daed/v0.4.0rc1 (like v2rayA/1.0 WebRequestHelper) (like v2rayN/1.0 WebRequestHelper)',
            'Matsuri/0.8.0',
            '${sub_ua}',
            'scan',
            'ASUS',
            'Chrome/3',
            'Chrome/4',
            'Chrome/16.0.912.77',
            'Chrome/5',
            'Chrome/6',
            'ZTE',
            'Chrome/2',
        ] as $userAgent) {
            $this->assertTrue($isBlacklistedUa->invoke($plugin, strtolower($userAgent)), $userAgent);
        }
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

    public function test_subscription_control_applies_to_multisite_users(): void
    {
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createSiteTenantTables();

        $site = $this->createSite('gm', '光喵', 'gm.example.test');
        $user = $this->createUser('site-customer@example.test', $site->id);

        $this->assertBrowserUaBlockedForUser($user);
    }

    public function test_subscription_control_applies_to_agent_sub_users(): void
    {
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createAgentCenterTables();

        $agent = $this->createAgent('agent@example.test');
        $user = $this->createUser('agent-sub@example.test', null);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $user->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertBrowserUaBlockedForUser($user);
    }

    public function test_default_browser_ua_block_only_matches_common_browsers(): void
    {
        $configPath = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($configPath), true);
        $defaultBlockOnly = (string) ($config['config']['ua_block_only_keywords']['default'] ?? '');

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'ua_block_only_keywords' => $defaultBlockOnly,
        ]);
        $isBlockOnlyUa = new ReflectionMethod($plugin, 'isBlockOnlyUA');
        $isBlockOnlyUa->setAccessible(true);

        foreach ([
            'Mozilla/5.0 AppleWebKit/537.36 Chrome/138.0 Safari/537.36',
            'Mozilla/5.0 AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15',
            'Mozilla/5.0 AppleWebKit/605.1.15 CriOS/138.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 AppleWebKit/605.1.15 FxiOS/127.0 Mobile/15E148 Safari/605.1.15',
            'Mozilla/5.0 AppleWebKit/537.36 SamsungBrowser/26.0 Chrome/122.0 Mobile Safari/537.36',
            'Mozilla/5.0 AppleWebKit/537.36 UCBrowser/15.5 Mobile Safari/537.36',
            'Mozilla/5.0 AppleWebKit/537.36 MiuiBrowser/13.0 Mobile Safari/537.36',
            'Mozilla/5.0 AppleWebKit/537.36 HuaweiBrowser/15.0 Mobile Safari/537.36',
            'Mozilla/5.0 AppleWebKit/537.36 Quark/7.0 Mobile Safari/537.36',
            'Mozilla/5.0 AppleWebKit/537.36 SogouMobileBrowser/7.0 Mobile Safari/537.36',
            'Mozilla/5.0 AppleWebKit/537.36 BaiduBrowser/13.0 Mobile Safari/537.36',
        ] as $userAgent) {
            $this->assertTrue($isBlockOnlyUa->invoke($plugin, strtolower($userAgent)), $userAgent);
        }
    }

    public function test_default_ua_reset_matches_legacy_clients_without_matching_modern_clients(): void
    {
        $configPath = dirname(__DIR__, 3) . '/plugins/SubscriptionControl/config.json';
        $config = json_decode((string) file_get_contents($configPath), true);
        $defaultReset = (string) ($config['config']['ua_reset_keywords']['default'] ?? '');
        $defaultResetExclude = (string) ($config['config']['ua_reset_exclude_keywords']['default'] ?? '');

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'ua_reset_keywords' => $defaultReset,
            'ua_reset_exclude_keywords' => $defaultResetExclude,
        ]);
        $isResetUa = new ReflectionMethod($plugin, 'isResetUA');
        $isResetUa->setAccessible(true);

        foreach ([
            'west2online',
            'ClashForWindows/0.20.39',
            'Clash for Windows/0.20.39',
            'ClashForAndroid/3.0.0',
            'Clash for Android/3.0.0',
            'ClashX/1.118.0',
            'ClashDotNetFramework/1.2.0',
            'Clash.NET/0.2.0',
            'clashR',
            'v2rayN/6.23',
            'v2rayNG/1.8.5',
            'Quantumult%20X/1.0.29',
            'Quantumult X/1.0.29',
            'Loon/636',
            'Surge/2390',
            'Stash/2.4.0',
            'Surfboard/2.15.0',
            'Kitsunebi/1.8.0',
            'SagerNet/0.8.1',
            'Potatso/2.9.0',
            'Pharos/1.0',
            'Postern/3.1',
            'ShadowsocksX-NG',
            'sstap',
            'SSD',
            'v2raytun',
        ] as $userAgent) {
            $this->assertTrue($isResetUa->invoke($plugin, strtolower($userAgent)), $userAgent);
        }

        foreach ([
            'ClashforWindows/0.19.23',
            'Karing/1.2.19.2209 windows',
            'mihomo/1.19.8',
            'sing-box/1.13.0',
            'clash-verge/v1.3.8',
            'ClashX.Meta/1.118.0',
            'Clash-Verge-Rev/2.4.0',
            'Shadowrocket/1993',
            'Shadowrocket/2698',
            'Loon/637',
            'Stash/2.5.0',
            'v2rayNG/2.2.0',
        ] as $userAgent) {
            $this->assertFalse($isResetUa->invoke($plugin, strtolower($userAgent)), $userAgent);
        }
    }

    public function test_risk_notifications_use_agent_site_branding_for_bound_user(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->database->schema()->table('v2_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('telegram_id')->nullable();
        });
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
        $this->bindTestSettings([
            'app_name' => 'Main Cloud',
            'app_url' => 'https://main.example.test',
            'telegram_bot_token' => 'telegram-token',
        ]);
        app()->instance('log', new class {
            public function info(...$arguments): void {}
            public function warning(...$arguments): void {}
            public function error(...$arguments): void {}
        });
        $dispatcher = $this->bindCapturingDispatcher();

        $site = $this->createSite('gm', '光喵', 'gm.example.test');
        $agent = $this->createAgent('agent@example.test');
        $user = $this->createUser('customer@example.test', $site->id);
        $user->telegram_id = 123456;
        $user->save();
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $user->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => null,
            'setting_scope' => AgentSiteSetting::SCOPE_DEFAULT,
            'setting_key' => AgentSiteSetting::KEY_DEFAULT,
            'site_name' => '代理云',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'enable_email_notice' => true,
            'enable_telegram_notice' => true,
            'notify_cooldown_seconds' => 60,
        ]);
        $method = new ReflectionMethod($plugin, 'sendRiskNotifications');
        $method->setAccessible(true);

        $result = $method->invoke($plugin, $user, 'ua_blacklist', '恶意扫描 UA', []);

        $this->assertTrue($result['email_sent']);
        $this->assertTrue($result['telegram_sent']);
        $this->assertCount(2, $dispatcher->dispatched);
        $emailParams = $this->emailJobParams($dispatcher->dispatched[0]);
        $telegramText = $this->telegramJobText($dispatcher->dispatched[1]);

        $this->assertSame('[代理云] 订阅风控提醒', $emailParams['subject']);
        $this->assertSame('代理云', $emailParams['template_value']['name']);
        $this->assertSame('https://agent.example.test', $emailParams['template_value']['url']);
        $this->assertStringContainsString('站点来源：代理云（代理站点）', $emailParams['template_value']['content']);
        $this->assertStringNotContainsString('面板地址', $emailParams['template_value']['content']);
        $this->assertStringNotContainsString('https://agent.example.test', $emailParams['template_value']['content']);
        $this->assertSame($agent->id, $emailParams['dispatch_context']['agent_user_id']);
        $this->assertStringContainsString('[代理云] 订阅风控提醒', $telegramText);
        $this->assertStringContainsString('站点来源：代理云（代理站点）', $telegramText);
        $this->assertStringNotContainsString('面板地址', $telegramText);
        $this->assertStringNotContainsString('https://agent.example.test', $telegramText);
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

    private function createSite(string $code, string $name, string $host): Site
    {
        $site = Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $site;
    }

    private function createAgent(string $email): User
    {
        $agent = $this->createUser($email, null);
        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createUser(string $email, ?int $siteId): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'site_id' => $siteId,
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function assertBrowserUaBlockedForUser(User $user): void
    {
        $oldToken = (string) $user->token;
        $oldUuid = (string) $user->uuid;
        $plugin = new Plugin('subscription_control');
        $plugin->setConfig([
            'enable_ua_blacklist' => false,
            'enable_ua_block_only' => true,
            'ua_block_only_keywords' => "Mozilla\nChrome\nSafari",
            'enable_client_ua_whitelist' => false,
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
        $this->assertSame($oldToken, $user->token);
        $this->assertSame($oldUuid, $user->uuid);

        $event = Cache::get("subscription_control:last_event:{$user->id}");
        $this->assertSame('ua_block_only', $event['code']);
        $this->assertSame('block', $event['action']);
    }

    private function bindCapturingDispatcher(): object
    {
        $dispatcher = new class implements Dispatcher {
            public array $dispatched = [];

            public function dispatch($command)
            {
                $this->dispatched[] = $command;

                return $command;
            }

            public function dispatchSync($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function dispatchNow($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function hasCommandHandler($command)
            {
                return false;
            }

            public function getCommandHandler($command)
            {
                return null;
            }

            public function pipeThrough(array $pipes)
            {
                return $this;
            }

            public function map(array $map)
            {
                return $this;
            }
        };

        app()->instance(Dispatcher::class, $dispatcher);

        return $dispatcher;
    }

    private function emailJobParams(SendEmailJob $job): array
    {
        $property = new \ReflectionProperty($job, 'params');
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    private function telegramJobText(SendTelegramJob $job): string
    {
        $property = new \ReflectionProperty($job, 'text');
        $property->setAccessible(true);

        return (string) $property->getValue($job);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Models\User;
use App\Services\Plugin\InterceptResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Plugin\SubscriptionControl\Plugin;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionGraduatedProtectionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private object $logger;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->createUserTable(); $this->bindJsonResponseFactory();
        $this->logger = new class {
            public array $records = [];
            public function info(...$args): void { $this->records[] = $args; }
            public function warning(...$args): void { $this->records[] = $args; }
            public function error(...$args): void { $this->records[] = $args; }
        };
        app()->instance('log', $this->logger);
        $this->user = User::query()->create(['email' => 'synthetic@example.test', 'token' => 'synthetic-private-token', 'uuid' => 'synthetic-private-uuid']);
    }

    private function plugin(array $config): Plugin
    {
        $plugin = new Plugin('subscription_control');
        $plugin->setConfig(array_merge(['enable_auto_trusted_node_ips' => false, 'enable_email_notice' => false,
            'enable_telegram_notice' => false, 'enable_ip_intelligence' => false], $config));
        return $plugin;
    }

    private function request(string $ua = 'Sparkle/1.0', string $ip = '8.8.8.8'): Request
    {
        return Request::create('/s/synthetic', 'GET', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]);
    }

    private function assertUnchanged(): void
    {
        $this->assertSame('synthetic-private-token', $this->user->fresh()->token);
        $this->assertSame('synthetic-private-uuid', $this->user->fresh()->uuid);
    }

    public function test_legacy_client_and_multi_client_observations_do_not_interrupt_subscription_delivery(): void
    {
        $plugin = $this->plugin(['enable_ua_reset_token' => true, 'ua_reset_keywords' => 'OldClient',
            'enable_multi_ua_detection' => true, 'multi_ua_allowed_count' => 1, 'multi_ua_action' => 'reset_token_uuid']);
        $servers = [['id' => 1]];
        $this->assertSame($servers, $plugin->checkSubscribeAccess($servers, $this->user, $this->request('OldClient/1.0')));
        $this->assertSame($servers, $plugin->checkSubscribeAccess($servers, $this->user, $this->request('sing-box/1.0')));
        $this->assertUnchanged();
        $this->assertNull(Cache::get('subscription_control:last_event:' . $this->user->id));
        $events = Cache::get('subscription_control:recent_events', []);
        $this->assertNotEmpty($events); foreach ($events as $event) $this->assertSame('observe', $event['action']);
    }

    public function test_rate_limit_returns_429_and_retry_metadata_without_rotating_or_restricting_another_account(): void
    {
        $plugin = $this->plugin(['enable_rate_limit' => true, 'rate_limit_requests' => 1, 'rate_limit_window' => 60]);
        $servers = [['id' => 1]]; $this->assertSame($servers, $plugin->checkSubscribeAccess($servers, $this->user, $this->request()));
        try { $plugin->checkSubscribeAccess($servers, $this->user, $this->request()); $this->fail('Request should be limited'); }
        catch (InterceptResponseException $error) {
            $response = $error->getResponse(); $this->assertSame(429, $response->getStatusCode());
            $this->assertSame('60', $response->headers->get('Retry-After'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('未被重置', $response->getContent());
        }
        $this->assertUnchanged();
        $other = User::query()->create(['email' => 'other@example.test', 'token' => 'other-token', 'uuid' => 'other-uuid']);
        $this->assertSame($servers, $plugin->checkSubscribeAccess($servers, $other, $this->request()));
        $this->assertSame('throttle', Cache::get('subscription_control:last_event:' . $this->user->id)['action']);
    }

    public function test_online_address_threshold_does_not_reset_credentials_or_block_the_request(): void
    {
        $service = $this->createMock(\App\Services\UserOnlineService::class);
        $service->method('getOnlineCount')->willReturn(30); app()->instance(\App\Services\UserOnlineService::class, $service);
        $plugin = $this->plugin(['enable_online_ip_threshold' => true, 'online_ip_threshold' => 2]);
        $servers = [['id' => 1]]; $this->assertSame($servers, $plugin->checkSubscribeAccess($servers, $this->user, $this->request()));
        $this->assertUnchanged(); $this->assertNull(Cache::get('subscription_control:last_event:' . $this->user->id));
    }

    public function test_explicit_one_time_quota_still_rotates_both_credentials_without_logging_either(): void
    {
        $plugin = $this->plugin(['enable_one_time' => true, 'one_time_max_uses' => 1, 'one_time_duration' => 600]);
        $plugin->checkSubscribeAccess([], $this->user, $this->request());
        try { $plugin->checkSubscribeAccess([], $this->user, $this->request()); $this->fail('Quota should be enforced'); }
        catch (InterceptResponseException $error) { $this->assertSame(403, $error->getResponse()->getStatusCode()); }
        $fresh = $this->user->fresh(); $this->assertNotSame('synthetic-private-token', $fresh->token);
        $this->assertNotSame('synthetic-private-uuid', $fresh->uuid);
        $logs = json_encode($this->logger->records);
        foreach (['synthetic-private-token', 'synthetic-private-uuid', $fresh->token, $fresh->uuid, 'old_token', 'new_token'] as $secret) $this->assertStringNotContainsString($secret, $logs);
    }

    public function test_failed_rotation_is_not_reported_as_success_and_sql_exception_bindings_are_not_logged(): void
    {
        $plugin = $this->plugin(['enable_one_time' => true, 'one_time_max_uses' => 1, 'one_time_duration' => 600]);
        $plugin->checkSubscribeAccess([], $this->user, $this->request());
        Schema::table('v2_user', fn ($table) => $table->dropColumn('uuid'));
        try { $plugin->checkSubscribeAccess([['id' => 1]], $this->user, $this->request()); $this->fail('Failed rotation must not expose a subscription'); }
        catch (InterceptResponseException $error) {
            $this->assertSame(503, $error->getResponse()->getStatusCode());
            $this->assertStringContainsString('未确认', $error->getResponse()->getContent());
        }
        $this->assertSame('synthetic-private-token', $this->user->fresh()->token);
        $logs = json_encode($this->logger->records);
        $this->assertStringNotContainsString('synthetic-private-token', $logs);
        $this->assertStringNotContainsString('SQLSTATE', $logs);
        $this->assertStringNotContainsString('用户订阅凭证已重置', json_encode($this->logger->records, JSON_UNESCAPED_UNICODE));
    }
}

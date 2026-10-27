<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\UserInsightController;
use App\Http\Routes\V2\AdminRoute;
use App\Models\Order;
use App\Models\User;
use App\Services\NotificationSiteContextService;
use App\Services\Plugin\HookManager;
use App\Services\SiteNavigationService;
use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use App\Services\UserBusinessTimelineService;
use App\Services\UserSubscriptionDiagnosticsService;
use App\Support\ProtocolCapabilityService;
use App\Support\ProtocolManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Events\Dispatcher;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserInsightServicesTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings(['app_url' => 'https://main.example.test', 'secure_path' => 'test-admin']);
        config(['app.key' => 'test-key']);
        $this->createUserTable();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->database->schema()->create('v2_server', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->string('type');
            $table->string('port')->default('443');
            $table->json('group_ids'); $table->json('protocol_settings');
            $table->boolean('show')->default(true); $table->boolean('enabled')->default(true); $table->integer('sort')->default(0);
        });
        app()->instance('protocols.manager', new ProtocolManager(app()));
        app()->instance('protocols.capabilities', new ProtocolCapabilityService(require base_path('config/protocol_capabilities.php')));
        app()->instance(NotificationSiteContextService::class, new class extends NotificationSiteContextService {
            public function __construct() {}
            public function forUser(User $user, ?Request $request = null): array {
                return ['brand_source' => $user->site_id ? 'site' : 'main', 'site_id' => $user->site_id,
                    'app_name' => $user->site_id ? 'Branch' : 'Main', 'agent_user_id' => null];
            }
        });
        app()->instance(SiteNavigationService::class, new class extends SiteNavigationService {
            public function urlForSubscription(mixed $user, Request $request): ?string { return $user->site_id ? null : 'https://nav.example.test'; }
        });
        app()->instance(WebsiteProxyEndpointService::class, new class extends WebsiteProxyEndpointService {
            public function urlForSubscription(mixed $user, Request $request): ?string { return $user->site_id ? 'https://192.0.2.10:8444' : 'https://192.0.2.10'; }
        });
    }

    private function user(array $extra = []): User
    {
        return User::create(array_replace(['email' => 'insight@example.test', 'uuid' => 'private-user-uuid',
            'token' => 'private-user-token', 'password' => 'private-user-password', 'group_id' => 1,
            'plan_id' => 1, 'transfer_enable' => 1073741824, 'expired_at' => time() + 86400,
            'created_at' => 1000, 'updated_at' => 1000], $extra));
    }

    private function node(int $id, array $extra = []): void
    {
        $this->database->table('v2_server')->insert(array_replace(['id' => $id, 'name' => 'Node ' . $id,
            'type' => 'trojan', 'group_ids' => '["1"]', 'protocol_settings' => json_encode([
                'network' => 'tcp', 'server_name' => 'private-sni.example.test', 'password' => 'private-node-password']),
            'show' => true, 'enabled' => true, 'sort' => 0], $extra));
    }

    public function test_diagnostics_are_read_only_scoped_and_never_export_credentials_or_execute_plugins(): void
    {
        $user = $this->user();
        $this->node(1); $this->node(2, ['show' => false]); $this->node(3, ['enabled' => false]);
        $this->node(4, ['group_ids' => '["9"]']);
        HookManager::registerFilter('client.subscribe.servers', fn () => throw new \RuntimeException('Plugin must not execute'));
        HookManager::registerFilter('protocol.servers.filtered', fn () => throw new \RuntimeException('Plugin must not execute'));
        $this->database->getConnection()->enableQueryLog();
        $payload = app(UserSubscriptionDiagnosticsService::class)->inspect($user, Request::create('/', 'GET', ['flag' => 'Shadowrocket/2698']));
        $this->assertSame(3, $payload['summary']['total']);
        $this->assertSame(1, $payload['summary']['eligible']);
        $this->assertSame(['eligible', 'hidden', 'disabled'], array_column($payload['nodes'], 'reason'));
        $this->assertSame(['navigation', 'website'], array_column($payload['entries'], 'kind'));
        $this->assertTrue($payload['has_plugin_filters']);
        $this->assertFalse($payload['network_tested']);
        foreach (['private-user-uuid', 'private-user-token', 'private-user-password', 'private-node-password', 'private-sni.example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($payload));
        }
        $this->assertReadOnlyQueries();
    }

    public function test_branch_account_does_not_inherit_main_navigation_when_subscription_host_is_main(): void
    {
        $payload = app(UserSubscriptionDiagnosticsService::class)->inspect($this->user(['site_id' => 5]),
            Request::create('https://main.example.test/sub/fixture', 'GET', ['flag' => 'Shadowrocket/2698']));
        $this->assertSame(5, $payload['context']['site_id']);
        $this->assertSame([['kind' => 'website', 'url' => 'https://192.0.2.10:8444']], $payload['entries']);
        $this->assertStringNotContainsString('nav.example.test', json_encode($payload));
    }

    public function test_base_access_uses_the_actual_subscription_access_policy(): void
    {
        $user = $this->user(['u' => 1073741824]);
        $service = app(UserSubscriptionDiagnosticsService::class);
        $payload = $service->inspect($user, Request::create('/', 'GET', ['flag' => 'Shadowrocket']));
        $this->assertTrue($payload['access']['allowed']);
        $this->assertContains('traffic_exhausted', $payload['access']['warnings']);
        $user->banned = true;
        $this->assertFalse($service->inspect($user, Request::create('/', 'GET', ['flag' => 'Shadowrocket']))['access']['allowed']);
    }

    public function test_version_filtered_nodes_use_the_existing_export_rules(): void
    {
        $this->node(1, ['type' => 'hysteria', 'protocol_settings' => json_encode(['version' => 2, 'tls' => ['server_name' => 'tls.example.test']])]);
        $service = app(UserSubscriptionDiagnosticsService::class);
        $old = $service->inspect($user = $this->user(), Request::create('/', 'GET', ['flag' => 'Shadowrocket/1000']));
        $new = $service->inspect($user, Request::create('/', 'GET', ['flag' => 'Shadowrocket/2698']));
        $this->assertFalse($old['nodes'][0]['eligible']);
        $this->assertTrue($new['nodes'][0]['eligible'], json_encode($new['nodes'][0]));
    }

    private function order(User $user, int $id, array $extra = []): void
    {
        $this->database->table('v2_order')->insert(array_replace(['id' => $id, 'user_id' => $user->id, 'plan_id' => 1,
            'period' => 'yearly', 'trade_no' => 'private-trade-' . $id, 'callback_no' => 'private-callback',
            'status' => Order::STATUS_COMPLETED, 'type' => 1, 'total_amount' => 1300,
            'created_at' => 3000, 'updated_at' => 4000, 'paid_at' => 4000], $extra));
    }

    public function test_timeline_excludes_cancelled_orders_as_sources_and_does_not_invent_attribution(): void
    {
        $user = $this->user();
        $this->order($user, 1, ['status' => Order::STATUS_CANCELLED, 'created_at' => 2000, 'paid_at' => null]);
        $this->order($user, 2);
        $this->order($this->user(['email' => 'other@example.test']), 3);
        $this->database->getConnection()->enableQueryLog();
        $payload = app(UserBusinessTimelineService::class)->inspect($user, 1, 20);
        $this->assertSame('unconfirmed', $payload['entitlement']['source_status']);
        $this->assertSame([2], array_column($payload['entitlement']['related_completed_orders'], 'order_id'));
        $this->assertSame(['payment_recorded', 'order_created', 'order_created', 'registered'], array_column($payload['events'], 'kind'));
        $this->assertSame(4, $payload['pagination']['total']);
        $this->assertFalse($payload['gateway_verified']);
        $this->assertFalse($payload['complete_history']);
        foreach (['private-trade-', 'private-callback', 'other@example.test', 'private-user-token'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($payload));
        }
        $this->assertReadOnlyQueries();
    }

    public function test_timeline_pagination_is_deterministic_at_equal_timestamps(): void
    {
        $user = $this->user();
        $this->order($user, 1); $this->order($user, 2);
        $service = app(UserBusinessTimelineService::class);
        $first = $service->inspect($user, 1, 2);
        $second = $service->inspect($user, 2, 2);
        $this->assertSame(['payment_recorded:2', 'payment_recorded:1'], array_column($first['events'], 'key'));
        $this->assertSame(['order_created:2', 'order_created:1'], array_column($second['events'], 'key'));
        $this->assertSame(3, $first['pagination']['last_page']);
    }

    public function test_optional_history_is_available_without_exposing_gift_codes_or_notes(): void
    {
        $user = $this->user();
        $this->database->schema()->create('v2_gift_card_usage', function (Blueprint $table) {
            $table->increments('id'); $table->integer('user_id'); $table->json('rewards_given'); $table->integer('created_at');
        });
        $this->database->table('v2_gift_card_usage')->insert(['user_id' => $user->id, 'created_at' => 5000,
            'rewards_given' => json_encode(['plan_id' => 1, 'balance' => 100, 'secret' => 'private-gift-data'])]);
        $payload = app(UserBusinessTimelineService::class)->inspect($user);
        $this->assertSame('gift_redeemed', $payload['events'][0]['kind']);
        $this->assertSame(['plan_id', 'balance'], $payload['events'][0]['reward_types']);
        $this->assertStringNotContainsString('private-gift-data', json_encode($payload));
    }

    public function test_new_endpoints_are_get_only_and_inside_the_admin_guard(): void
    {
        $router = new Router(new Dispatcher(app()), app());
        (new AdminRoute())->map($router);
        $found = [];
        foreach ($router->getRoutes() as $route) {
            if (in_array($route->getActionName(), [UserInsightController::class . '@subscription', UserInsightController::class . '@timeline'], true)) {
                $this->assertContains('admin', $route->gatherMiddleware());
                $this->assertSame(['GET', 'HEAD'], $route->methods());
                $found[] = $route->getActionName();
            }
        }
        $this->assertCount(2, $found);
    }

    public function test_admin_audits_are_only_requests_and_invalid_or_other_user_records_are_not_exposed(): void
    {
        $user = $this->user();
        $this->database->schema()->create('v2_admin_audit_log', function (Blueprint $table) {
            $table->increments('id'); $table->string('action'); $table->text('request_data'); $table->integer('created_at');
        });
        foreach ([['id' => (string) $user->id, 'token' => 'private-audit-token'], ['id' => 999], null] as $index => $data) {
            $this->database->table('v2_admin_audit_log')->insert(['action' => 'user.update',
                'request_data' => $data === null ? '{broken json' : json_encode($data), 'created_at' => 5000 + $index]);
        }
        $payload = app(UserBusinessTimelineService::class)->inspect($user);
        $this->assertSame('admin_request', $payload['events'][0]['kind']);
        $this->assertSame('request_only', $payload['events'][0]['evidence']);
        $this->assertSame(2, $payload['pagination']['total']);
        $this->assertStringNotContainsString('private-audit-token', json_encode($payload));
        $this->assertFalse($payload['admin_adjustments_reconstructed']);
    }

    public function test_refunded_orders_are_not_listed_as_related_active_entitlements(): void
    {
        $user = $this->user();
        $this->order($user, 1, ['refund_amount' => 1300, 'refund_disposed_at' => 5000]);
        $payload = app(UserBusinessTimelineService::class)->inspect($user);
        $this->assertSame([], $payload['entitlement']['related_completed_orders']);
        $this->assertSame('refund_processed', $payload['events'][0]['kind']);
        $this->assertSame(5000, $payload['events'][0]['at']);
    }

    public function test_traffic_reset_dates_are_parsed_separately_from_unix_order_timestamps(): void
    {
        $user = $this->user();
        $this->database->schema()->create('v2_traffic_reset_logs', function (Blueprint $table) {
            $table->increments('id'); $table->integer('user_id'); $table->dateTime('reset_time');
            $table->string('trigger_source'); $table->string('reset_type'); $table->text('metadata')->nullable();
        });
        for ($id = 1; $id <= 12; $id++) {
            $this->database->table('v2_traffic_reset_logs')->insert(['user_id' => $user->id,
                'reset_time' => '2026-10-15 10:07:51', 'trigger_source' => 'order', 'reset_type' => 'purchase', 'metadata' => 'private-reset-data']);
        }
        $payload = app(UserBusinessTimelineService::class)->inspect($user);
        $this->assertCount(10, $payload['recent_traffic_resets']);
        $this->assertSame(strtotime('2026-10-15 10:07:51'), $payload['recent_traffic_resets'][0]['at']);
        $this->assertStringNotContainsString('private-reset-data', json_encode($payload));
    }

    public function test_diagnostic_node_scan_is_bounded_and_reports_truncation(): void
    {
        $user = $this->user();
        for ($id = 1; $id <= 501; $id++) $this->node($id);
        $payload = app(UserSubscriptionDiagnosticsService::class)->inspect($user, Request::create('/', 'GET', ['flag' => 'Shadowrocket/2698']));
        $this->assertSame(501, $payload['summary']['total']);
        $this->assertSame(500, $payload['summary']['inspected']);
        $this->assertTrue($payload['summary']['limited']);
    }

    public function test_controller_response_is_not_cacheable_and_reads_no_user_connection_credentials(): void
    {
        $user = $this->user();
        $this->database->getConnection()->enableQueryLog();
        $response = app(UserInsightController::class)->timeline(Request::create('/', 'GET', ['id' => $user->id]), app(UserBusinessTimelineService::class));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertTrue($response->getData(true)['data']['read_only']);
        foreach ($this->database->getConnection()->getQueryLog() as $query) {
            $this->assertStringNotContainsString('"uuid"', $query['query']);
            $this->assertStringNotContainsString('"token"', $query['query']);
            $this->assertStringNotContainsString('"password"', $query['query']);
        }
    }

    public function test_controller_rejects_invalid_flags_and_pagination(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(UserInsightController::class)->subscription(Request::create('/', 'GET', ['id' => 1, 'flag' => "Shadowrocket\r\nsecret"]),
            app(UserSubscriptionDiagnosticsService::class));
    }

    private function assertReadOnlyQueries(): void
    {
        foreach ($this->database->getConnection()->getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|drop)\b/i', $query['query']);
        }
    }
}

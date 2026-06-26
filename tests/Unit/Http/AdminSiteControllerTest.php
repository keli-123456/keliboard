<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\SiteController;
use App\Http\Routes\V2\AdminRoute;
use App\Models\AgentDomain;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePayment;
use App\Models\SitePlanOverride;
use App\Models\SitePlanPrice;
use App\Models\SiteSetting;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminSiteControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindRequestValidateMacro();
        $this->bindTestSettings(['secure_path' => 'admin']);
        config(['app.key' => 'testing-site-key']);
        $this->createUserTable();
        $this->createOrderTable();
        $this->createPlanTable();
        $this->createPaymentTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
    }

    public function test_admin_can_create_site_and_primary_domain_without_default_site_role(): void
    {
        $request = Request::create('/admin/site/save', 'POST', [
            'code' => 'cheap-main',
            'name' => 'Cheap Main',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => true,
            'domains' => [
                [
                    'domain' => 'https://Cheap.Example.Test:443/path',
                    'status' => SiteDomain::STATUS_ACTIVE,
                    'is_primary' => true,
                ],
            ],
        ]);

        $payload = $this->responsePayload(app(SiteController::class)->save($request));

        $this->assertSame('success', $payload['status']);
        $this->assertSame('cheap-main', $payload['data']['code']);
        $this->assertFalse($payload['data']['is_default']);
        $this->assertSame('cheap.example.test', $payload['data']['domains'][0]['domain']);
        $this->assertTrue($payload['data']['domains'][0]['is_primary']);
        $this->assertSame(0, Site::query()->where('is_default', true)->count());
    }

    public function test_duplicate_site_domain_is_rejected(): void
    {
        $site = Site::query()->create([
            'code' => 'first',
            'name' => 'First Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'taken.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = Request::create('/admin/site/save', 'POST', [
            'code' => 'second',
            'name' => 'Second Site',
            'domains' => [
                ['domain' => 'taken.example.test'],
            ],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain already assigned');

        app(SiteController::class)->save($request);
    }

    public function test_fetch_hides_legacy_default_site_placeholder(): void
    {
        Site::query()->create([
            'code' => 'default',
            'name' => 'Default Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Site::query()->create([
            'code' => 'branch',
            'name' => 'Branch Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $payload = $this->responsePayload(app(SiteController::class)->fetch());

        $this->assertSame('success', $payload['status']);
        $this->assertSame(['branch'], array_column($payload['data'], 'code'));
    }

    public function test_site_domain_cannot_reuse_agent_domain(): void
    {
        $this->createAgentCommerceTables();
        AgentDomain::query()->create([
            'agent_user_id' => 1001,
            'domain' => 'agent-owned.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = Request::create('/admin/site/save', 'POST', [
            'code' => 'second',
            'name' => 'Second Site',
            'domains' => [
                ['domain' => 'agent-owned.example.test'],
            ],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain already assigned');

        app(SiteController::class)->save($request);
    }

    public function test_admin_can_save_site_commerce_settings_prices_and_inherits_platform_payments(): void
    {
        $site = Site::query()->create([
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = Plan::query()->create([
            'name' => 'Starter',
            'prices' => ['monthly' => 20.00],
            'transfer_enable' => 100,
            'group_id' => 1,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $payment = Payment::query()->create([
            'uuid' => 'platform-pay-uuid',
            'payment' => 'dummy',
            'name' => 'Platform Pay',
            'icon' => '',
            'config' => [],
            'enable' => true,
            'owner_type' => Payment::OWNER_PLATFORM,
            'owner_id' => null,
            'owner_domain_id' => null,
            'sort' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $request = Request::create('/admin/site/commerce/save', 'POST', [
            'site_id' => $site->id,
            'setting' => [
                'site_name' => 'Cheap Brand',
                'landing_theme' => 'sakura',
                'customer_service_type' => 'chatra',
                'customer_service_id' => 'site-chatra-id',
                'telegram_discuss_link' => 'https://t.me/cheap_group',
                'enabled' => true,
            ],
            'prices' => [
                [
                    'plan_id' => $plan->id,
                    'period' => 'monthly',
                    'sale_price' => 1300,
                    'enabled' => true,
                ],
            ],
            'overrides' => [
                [
                    'plan_id' => $plan->id,
                    'display_name' => '光喵入门版',
                ],
            ],
            'payments' => [
                [
                    'payment_id' => $payment->id,
                    'enabled' => true,
                    'sort' => 3,
                ],
            ],
        ]);

        $payload = $this->responsePayload(app(SiteController::class)->saveCommerce($request));

        $this->assertSame('Cheap Brand', $payload['data']['setting']['site_name']);
        $this->assertSame('chatra', $payload['data']['setting']['customer_service_type']);
        $this->assertSame('site-chatra-id', $payload['data']['setting']['customer_service_id']);
        $this->assertSame('https://t.me/cheap_group', $payload['data']['setting']['telegram_discuss_link']);
        $this->assertSame('sakura', SiteSetting::query()->where('site_id', $site->id)->value('landing_theme'));
        $this->assertSame('chatra', SiteSetting::query()->where('site_id', $site->id)->value('customer_service_type'));
        $this->assertSame('site-chatra-id', SiteSetting::query()->where('site_id', $site->id)->value('customer_service_id'));
        $this->assertSame('https://t.me/cheap_group', SiteSetting::query()->where('site_id', $site->id)->value('telegram_discuss_link'));
        $this->assertSame(1300, SitePlanPrice::query()->where('site_id', $site->id)->where('plan_id', $plan->id)->value('sale_price'));
        $this->assertSame('光喵入门版', SitePlanOverride::query()->where('site_id', $site->id)->where('plan_id', $plan->id)->value('display_name'));
        $this->assertSame(0, SitePayment::query()->where('site_id', $site->id)->count());
        $this->assertSame('光喵入门版', $payload['data']['prices'][0]['display_name']);
        $this->assertSame(1300, $payload['data']['prices'][0]['periods'][0]['sale_price']);
        $this->assertSame([], $payload['data']['payments']);
        $this->assertSame($payment->id, $payload['data']['available_payments'][0]['id']);
        $this->assertSame('platform_inherited', $payload['data']['payment_policy']['mode']);
    }

    public function test_admin_route_registers_site_endpoints(): void
    {
        $registrar = new AdminSiteRouteRegistrar();

        (new AdminRoute())->map($registrar);

        $this->assertContains([
            'method' => 'GET',
            'uri' => '/admin/site/fetch',
            'action' => [SiteController::class, 'fetch'],
        ], $registrar->routes);
        $this->assertContains([
            'method' => 'POST',
            'uri' => '/admin/site/save',
            'action' => [SiteController::class, 'save'],
        ], $registrar->routes);
        $this->assertContains([
            'method' => 'GET',
            'uri' => '/admin/site/commerce',
            'action' => [SiteController::class, 'commerce'],
        ], $registrar->routes);
        $this->assertContains([
            'method' => 'POST',
            'uri' => '/admin/site/commerce/save',
            'action' => [SiteController::class, 'saveCommerce'],
        ], $registrar->routes);
    }

    private function bindRequestValidateMacro(): void
    {
        if (Request::hasMacro('validate')) {
            return;
        }

        Request::macro('validate', function (array $rules = [], ...$parameters): array {
            return $this->all();
        });
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}

final class AdminSiteRouteRegistrar implements Registrar
{
    /**
     * @var array<int, array{method: string, uri: string, action: mixed}>
     */
    public array $routes = [];

    /**
     * @var list<string>
     */
    private array $prefixes = [];

    public function get($uri, $action)
    {
        return $this->record('GET', $uri, $action);
    }

    public function post($uri, $action)
    {
        return $this->record('POST', $uri, $action);
    }

    public function put($uri, $action)
    {
        return $this->record('PUT', $uri, $action);
    }

    public function delete($uri, $action)
    {
        return $this->record('DELETE', $uri, $action);
    }

    public function patch($uri, $action)
    {
        return $this->record('PATCH', $uri, $action);
    }

    public function options($uri, $action)
    {
        return $this->record('OPTIONS', $uri, $action);
    }

    public function any($uri, $action)
    {
        return $this->record('ANY', $uri, $action);
    }

    public function match($methods, $uri, $action)
    {
        foreach ((array) $methods as $method) {
            $this->record(strtoupper((string) $method), $uri, $action);
        }
    }

    public function resource($name, $controller, array $options = [])
    {
        return null;
    }

    public function group(array $attributes, $routes)
    {
        $this->prefixes[] = (string) ($attributes['prefix'] ?? '');
        $routes($this);
        array_pop($this->prefixes);
    }

    public function substituteBindings($route)
    {
        return $route;
    }

    public function substituteImplicitBindings($route)
    {
        return null;
    }

    private function record(string $method, string $uri, $action): AdminSiteRecordedRoute
    {
        $this->routes[] = [
            'method' => $method,
            'uri' => '/' . trim(implode('/', array_filter($this->prefixes)) . '/' . ltrim($uri, '/'), '/'),
            'action' => $action,
        ];

        return new AdminSiteRecordedRoute();
    }
}

final class AdminSiteRecordedRoute
{
    public function whereNumber(string $parameter): self
    {
        return $this;
    }

    public function name(string $name): self
    {
        return $this;
    }
}

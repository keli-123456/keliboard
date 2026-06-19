<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\User\AgentCommerceController;
use App\Http\Routes\V1\UserRoute;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentDomainSelfService;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Http\Request as BaseRequest;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserAgentCommerceControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindRequestValidateMacro();
        $this->bindTestUrlGenerator('https://panel.example.test');
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createPaymentTable();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
        $this->createPlanTable();
        $this->bindTestSettings([
            'agent_center_domain_limit' => 3,
            'app_url' => 'https://panel.example.test',
        ]);
    }

    public function test_domains_and_commerce_summary_use_self_service_payload_and_limit(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'pending.example.test',
            'status' => AgentDomain::STATUS_PENDING,
            'is_primary' => false,
            'remark' => 'Pending storefront',
            'verification_token' => 'pending-token',
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'created_by_agent_id' => $agent->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $controller = app(AgentCommerceController::class);
        $request = $this->userRequest($agent, '/api/v1/user/agent/domains', 'GET');

        $domains = $this->responsePayload($controller->domains($request))['data'];
        $summary = $this->responsePayload($controller->commerceSummary($request))['data'];

        $this->assertSame('pending.example.test', $domains[0]['domain']);
        $this->assertSame($agent->id, $domains[0]['agent_user_id']);
        $this->assertSame('agent', $domains[0]['source']);
        $this->assertSame('_keli-agent.pending.example.test', $domains[0]['verification']['record_name']);
        $this->assertSame(
            AgentDomainSelfService::VALUE_PREFIX . 'pending-token',
            $domains[0]['verification']['record_value']
        );
        $this->assertSame(3, $summary['domain_limit']);
        $this->assertSame($domains, $summary['domains']);
        $this->assertSame([], $summary['site_settings']);
    }

    public function test_domains_rejects_inactive_agent_before_exposing_verification_payload(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'pending.example.test',
            'status' => AgentDomain::STATUS_PENDING,
            'is_primary' => false,
            'verification_token' => 'pending-token',
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'created_by_agent_id' => $agent->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentProfile::query()
            ->where('user_id', $agent->id)
            ->update([
                'status' => AgentCenterService::STATUS_DISABLED,
                'disabled_at' => time(),
                'updated_at' => time(),
            ]);
        $request = $this->userRequest($agent, '/api/v1/user/agent/domains', 'GET');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent permission is not active');

        app(AgentCommerceController::class)->domains($request);
    }

    public function test_commerce_summary_includes_active_only_payment_domains(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'active.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_by_agent_id' => $agent->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'pending.example.test',
            'status' => AgentDomain::STATUS_PENDING,
            'is_primary' => false,
            'verification_token' => 'pending-token',
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'created_by_agent_id' => $agent->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = $this->userRequest($agent, '/api/v1/user/agent/commerce/summary', 'GET');

        $summary = $this->responsePayload(app(AgentCommerceController::class)->commerceSummary($request))['data'];

        $this->assertSame(
            ['active.example.test', 'pending.example.test'],
            array_column($summary['domains'], 'domain')
        );
        $this->assertSame(
            ['active.example.test'],
            array_column($summary['payment_domains'], 'domain')
        );
        $this->assertSame(AgentDomain::STATUS_ACTIVE, $summary['payment_domains'][0]['status']);
    }

    public function test_commerce_diagnostics_endpoint_returns_agent_readiness(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'ready.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_by_agent_id' => $agent->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = $this->userRequest($agent, '/api/v1/user/agent/commerce/diagnostics', 'GET');

        $payload = $this->responsePayload(app(AgentCommerceController::class)->diagnostics($request));

        $this->assertSame('success', $payload['status']);
        $this->assertArrayHasKey('overall_status', $payload['data']);
        $this->assertArrayHasKey('checks', $payload['data']);
        $this->assertArrayHasKey('summary', $payload['data']);
        $this->assertArrayHasKey('payment_contexts', $payload['data']);
    }

    public function test_user_route_registers_commerce_diagnostics_endpoint(): void
    {
        $registrar = new UserAgentCommerceRouteRegistrar();

        (new UserRoute())->map($registrar);

        $this->assertContains([
            'method' => 'GET',
            'uri' => '/user/agent/commerce/diagnostics',
            'action' => [AgentCommerceController::class, 'diagnostics'],
        ], $registrar->routes);
    }

    public function test_site_settings_lists_and_saves_default_setting(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $controller = app(AgentCommerceController::class);
        $listRequest = $this->userRequest($agent, '/api/v1/user/agent/site-settings', 'GET');

        $initialPayload = $this->responsePayload($controller->siteSettings($listRequest));

        $this->assertSame('success', $initialPayload['status']);
        $this->assertSame([], $initialPayload['data']['settings']);

        $saveRequest = $this->userRequest($agent, '/api/v1/user/agent/site-settings', 'POST', [
            'site_name' => 'Agent Storefront',
            'logo_url' => 'https://assets.example.test/logo.png',
            'landing_theme' => 'spark',
            'accent_color' => '#AABBCC',
            'support_name' => 'Agent Support',
            'support_url' => 'https://support.example.test',
            'announcement' => 'Welcome to the agent storefront.',
            'enabled' => true,
        ]);

        $savedPayload = $this->responsePayload($controller->saveSiteSetting($saveRequest));

        $this->assertSame('success', $savedPayload['status']);
        $this->assertSame('Agent Storefront', $savedPayload['data']['site_name']);
        $this->assertSame('https://assets.example.test/logo.png', $savedPayload['data']['logo_url']);
        $this->assertSame('spark', $savedPayload['data']['landing_theme']);
        $this->assertSame('#aabbcc', $savedPayload['data']['accent_color']);
        $this->assertSame('Agent Support', $savedPayload['data']['support_name']);
        $this->assertSame('https://support.example.test', $savedPayload['data']['support_url']);
        $this->assertSame('Welcome to the agent storefront.', $savedPayload['data']['announcement']);
        $this->assertTrue($savedPayload['data']['enabled']);
        $this->assertNull($savedPayload['data']['agent_domain_id']);

        $listedPayload = $this->responsePayload($controller->siteSettings($listRequest));

        $this->assertSame('success', $listedPayload['status']);
        $this->assertCount(1, $listedPayload['data']['settings']);
        $this->assertSame($savedPayload['data'], $listedPayload['data']['settings'][0]);

        $summaryRequest = $this->userRequest($agent, '/api/v1/user/agent/commerce/summary', 'GET');
        $summary = $this->responsePayload($controller->commerceSummary($summaryRequest))['data'];

        $this->assertSame([$savedPayload['data']], $summary['site_settings']);
    }

    public function test_save_site_setting_with_null_id_creates_default_setting(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $request = $this->userRequest($agent, '/api/v1/user/agent/site-settings', 'POST', [
            'id' => null,
            'site_name' => 'Default Agent Site',
        ]);

        $payload = $this->responsePayload(app(AgentCommerceController::class)->saveSiteSetting($request));

        $this->assertSame('success', $payload['status']);
        $this->assertSame('Default Agent Site', $payload['data']['site_name']);
        $this->assertNull($payload['data']['agent_domain_id']);

        $listRequest = $this->userRequest($agent, '/api/v1/user/agent/site-settings', 'GET');
        $settings = $this->responsePayload(app(AgentCommerceController::class)->siteSettings($listRequest))['data']['settings'];

        $this->assertCount(1, $settings);
        $this->assertSame($payload['data']['id'], $settings[0]['id']);
    }

    public function test_save_domain_returns_pending_self_service_payload(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $request = $this->userRequest($agent, '/api/v1/user/agent/domains', 'POST', [
            'domain' => 'https://New.Example.Test/path',
            'remark' => 'New storefront',
        ]);

        $payload = $this->responsePayload(app(AgentCommerceController::class)->saveDomain($request));

        $this->assertSame('success', $payload['status']);
        $this->assertSame('new.example.test', $payload['data']['domain']);
        $this->assertSame(AgentDomain::STATUS_PENDING, $payload['data']['status']);
        $this->assertSame('New storefront', $payload['data']['remark']);
        $this->assertStringStartsWith(
            AgentDomainSelfService::VALUE_PREFIX,
            $payload['data']['verification']['record_value']
        );
    }

    public function test_verify_domain_returns_active_self_service_payload(): void
    {
        $txtRecords = [];
        app()->instance(AgentDomainSelfService::class, new AgentDomainSelfService(
            static function (string $name) use (&$txtRecords): array {
                return $txtRecords[$name] ?? [];
            }
        ));
        $agent = $this->createActiveAgent('agent@example.test');
        $pending = app(AgentDomainSelfService::class)->createPending($agent, 'verify.example.test', null);
        $txtRecords[$pending['verification']['record_name']] = [
            $pending['verification']['record_value'],
        ];
        $request = $this->userRequest($agent, '/api/v1/user/agent/domains/' . $pending['id'] . '/verify', 'POST');

        $payload = $this->responsePayload(
            app(AgentCommerceController::class)->verifyDomain($request, $pending['id'])
        );

        $this->assertSame('success', $payload['status']);
        $this->assertSame(AgentDomain::STATUS_ACTIVE, $payload['data']['status']);
        $this->assertSame('', $payload['data']['verification']['record_value']);
    }

    public function test_delete_domain_returns_success_true(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'delete.example.test',
            'status' => AgentDomain::STATUS_PENDING,
            'is_primary' => false,
            'verification_token' => 'delete-token',
            'verification_type' => AgentDomainSelfService::VERIFICATION_TYPE_TXT,
            'created_by_agent_id' => $agent->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = $this->userRequest($agent, '/api/v1/user/agent/domains/' . $domain->id . '/delete', 'POST');

        $payload = $this->responsePayload(app(AgentCommerceController::class)->deleteDomain($request, $domain->id));

        $this->assertSame('success', $payload['status']);
        $this->assertTrue($payload['data']);
        $this->assertSame(0, AgentDomain::query()->where('id', $domain->id)->count());
    }

    private function bindRequestValidateMacro(): void
    {
        if (BaseRequest::hasMacro('validate')) {
            return;
        }

        BaseRequest::macro('validate', function (array $rules = [], ...$parameters): array {
            return $this->all();
        });
    }

    private function userRequest(User $user, string $uri, string $method, array $parameters = []): BaseRequest
    {
        $request = BaseRequest::create($uri, $method, $parameters);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    private function createActiveAgent(string $email): User
    {
        $agent = User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 10000,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

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

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}

final class UserAgentCommerceRouteRegistrar implements Registrar
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

    private function record(string $method, string $uri, $action): void
    {
        $this->routes[] = [
            'method' => $method,
            'uri' => '/' . trim(implode('/', array_filter($this->prefixes)) . '/' . ltrim($uri, '/'), '/'),
            'action' => $action,
        ];
    }
}

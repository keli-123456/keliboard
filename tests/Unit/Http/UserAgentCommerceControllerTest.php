<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\User\AgentCommerceController;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentDomainSelfService;
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

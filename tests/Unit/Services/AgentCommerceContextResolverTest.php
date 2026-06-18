<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceContextResolver;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCommerceContextResolverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
    }

    public function test_user_binding_takes_priority_over_current_domain(): void
    {
        $firstAgent = $this->createActiveAgent('first-agent@example.test');
        $secondAgent = $this->createActiveAgent('second-agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        $domain = $this->assignDomain($secondAgent, 'second.example.test');
        AgentUser::query()->create([
            'agent_user_id' => $firstAgent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(AgentCommerceContextResolver::class)->resolveRequest(
            $this->requestForHost('second.example.test', $buyer)
        );

        $this->assertSame($firstAgent->id, $context['agent_user_id']);
        $this->assertNull($context['agent_domain_id']);
        $this->assertSame('', $context['domain']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_USER_BINDING, $context['source']);
        $this->assertNotSame($domain->id, $context['agent_domain_id']);
    }

    public function test_guest_request_uses_agent_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->assignDomain($agent, 'agent.example.test');

        $context = app(AgentCommerceContextResolver::class)->resolveRequest(
            $this->requestForHost('agent.example.test')
        );

        $this->assertSame($agent->id, $context['agent_user_id']);
        $this->assertSame($domain->id, $context['agent_domain_id']);
        $this->assertSame('agent.example.test', $context['domain']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_DOMAIN, $context['source']);
    }

    public function test_normal_request_returns_null(): void
    {
        $context = app(AgentCommerceContextResolver::class)->resolveRequest(
            $this->requestForHost('platform.example.test')
        );

        $this->assertNull($context);
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email);
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

    private function createUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function assignDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function requestForHost(string $host, ?User $user = null): Request
    {
        $request = Request::create('https://' . $host . '/user/plan/fetch', 'GET');
        $request->headers->set('host', $host);
        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }
}

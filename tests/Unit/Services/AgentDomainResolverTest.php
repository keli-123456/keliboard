<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\User;
use App\Services\AgentDomainResolver;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentDomainResolverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCommerceTables();
    }

    public function test_resolves_active_domain_ignoring_port_and_case(): void
    {
        $agent = $this->createUser('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'shop.example.com',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(AgentDomainResolver::class)->resolveHost('SHOP.EXAMPLE.COM:443');

        $this->assertNotNull($context);
        $this->assertSame($agent->id, $context['agent_user_id']);
        $this->assertSame('shop.example.com', $context['domain']);
    }

    public function test_disabled_domain_does_not_resolve(): void
    {
        $agent = $this->createUser('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'shop.example.com',
            'status' => AgentDomain::STATUS_DISABLED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertNull(app(AgentDomainResolver::class)->resolveHost('shop.example.com'));
    }

    public function test_normalize_host_strips_scheme_path_and_trailing_dot(): void
    {
        $resolver = app(AgentDomainResolver::class);

        $this->assertSame('agent.example.com', $resolver->normalizeHost('https://Agent.Example.COM:8443/path?x=1'));
        $this->assertSame('agent.example.com', $resolver->normalizeHost('agent.example.com.'));
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => bin2hex(random_bytes(16)),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

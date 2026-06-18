<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Database\QueryException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentSiteSettingServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
        $this->createTicketTables();
    }

    public function test_agent_site_setting_casts_enabled_and_resolves_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');

        $setting = AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'site_name' => 'Agent Site',
            'enabled' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertTrue($setting->enabled);
        $this->assertSame('agent.example.test', $setting->domain->domain);
    }

    public function test_agent_can_have_one_default_site_setting_and_one_per_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');

        $defaultSetting = $this->createSiteSetting($agent);
        $domainSetting = $this->createSiteSetting($agent, $domain);

        $this->assertSame('default', $defaultSetting->setting_scope);
        $this->assertSame('default', $defaultSetting->setting_key);
        $this->assertSame('domain', $domainSetting->setting_scope);
        $this->assertSame((string) $domain->id, $domainSetting->setting_key);

        $this->expectException(QueryException::class);

        $this->createSiteSetting($agent);
    }

    public function test_domain_resolves_its_site_setting(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');
        $setting = $this->createSiteSetting($agent, $domain);

        $this->assertSame($setting->id, $domain->siteSetting->id);
        $this->assertSame('Agent Site', $domain->siteSetting->site_name);
    }

    public function test_ticket_resolves_agent_and_agent_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $user = $this->createUser('customer@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');

        $ticket = Ticket::query()->create([
            'user_id' => $user->id,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'subject' => 'Help',
            'level' => 0,
            'status' => Ticket::STATUS_OPENING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertSame($agent->id, $ticket->agent->id);
        $this->assertSame('agent.example.test', $ticket->agentDomain->domain);
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

    private function createActiveDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createSiteSetting(User $agent, ?AgentDomain $domain = null): AgentSiteSetting
    {
        return AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain?->id,
            'site_name' => 'Agent Site',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

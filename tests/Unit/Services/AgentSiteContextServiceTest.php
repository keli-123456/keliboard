<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\AgentUser;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceContextResolver;
use App\Services\AgentSiteContextService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentSiteContextServiceTest extends TestCase
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
    }

    public function test_returns_null_without_agent_context(): void
    {
        $payload = app(AgentSiteContextService::class)->resolve(
            $this->requestForHost('platform.example.test')
        );

        $this->assertNull($payload);
    }

    public function test_returns_default_setting_for_bound_subordinate_with_all_fields_normalized(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $setting = $this->createSiteSetting($agent, null, [
            'site_name' => '  Agent Site  ',
            'logo_url' => '  https://example.test/logo.png  ',
            'landing_theme' => '  spark  ',
            'accent_color' => '  #aabbcc  ',
            'support_name' => '  Support Team  ',
            'support_url' => '  https://example.test/support  ',
            'announcement' => '  Welcome buyers  ',
            'seo_title' => '  Agent SEO  ',
            'seo_description' => '  Agent SEO description  ',
            'enabled' => 1,
            'created_at' => 1710000000,
            'updated_at' => 1710000100,
        ]);

        $payload = app(AgentSiteContextService::class)->resolve(
            $this->requestForHost('agent.example.test', $buyer)
        );

        $this->assertSame([
            'enabled' => true,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => null,
            'source' => AgentCommerceContextResolver::SOURCE_USER_BINDING,
            'domain' => '',
            'site_name' => 'Agent Site',
            'logo_url' => 'https://example.test/logo.png',
            'landing_theme' => 'spark',
            'accent_color' => '#aabbcc',
            'support_name' => 'Support Team',
            'support_url' => 'https://example.test/support',
            'announcement' => 'Welcome buyers',
            'seo_title' => 'Agent SEO',
            'seo_description' => 'Agent SEO description',
            'created_at' => 1710000000,
            'updated_at' => 1710000100,
        ], $payload);
        $this->assertSame($setting->id, AgentSiteSetting::query()->firstOrFail()->id);
    }

    public function test_returns_domain_setting_for_agent_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Default Site',
        ]);
        $this->createSiteSetting($agent, $domain, [
            'site_name' => 'Domain Site',
            'logo_url' => 'https://example.test/domain-logo.png',
        ]);

        $payload = app(AgentSiteContextService::class)->resolve(
            $this->requestForHost('agent.example.test')
        );

        $this->assertSame(true, $payload['enabled']);
        $this->assertSame($agent->id, $payload['agent_user_id']);
        $this->assertSame($domain->id, $payload['agent_domain_id']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_DOMAIN, $payload['source']);
        $this->assertSame('agent.example.test', $payload['domain']);
        $this->assertSame('Domain Site', $payload['site_name']);
        $this->assertSame('https://example.test/domain-logo.png', $payload['logo_url']);
    }

    public function test_disabled_domain_setting_falls_back_to_default_setting_while_preserving_context_source_and_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'agent.example.test');
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Default Site',
        ]);
        $this->createSiteSetting($agent, $domain, [
            'site_name' => 'Disabled Domain Site',
            'enabled' => false,
        ]);

        $payload = app(AgentSiteContextService::class)->resolve(
            $this->requestForHost('agent.example.test')
        );

        $this->assertSame('Default Site', $payload['site_name']);
        $this->assertNull($payload['agent_domain_id']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_DOMAIN, $payload['source']);
        $this->assertSame('agent.example.test', $payload['domain']);
    }

    public function test_disabled_default_setting_returns_null(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Disabled Default Site',
            'enabled' => false,
        ]);

        $payload = app(AgentSiteContextService::class)->resolve(
            $this->requestForHost('platform.example.test', $buyer)
        );

        $this->assertNull($payload);
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

    private function createSiteSetting(User $agent, ?AgentDomain $domain = null, array $attributes = []): AgentSiteSetting
    {
        return AgentSiteSetting::query()->create(array_merge([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain?->id,
            'site_name' => 'Agent Site',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
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

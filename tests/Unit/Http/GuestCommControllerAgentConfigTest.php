<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Guest\CommController;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceContextResolver;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class GuestCommControllerAgentConfigTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestThemeConfig();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
        $this->bindTestSettings([
            'app_name' => 'Main Site',
            'logo' => 'https://assets.example.test/main-logo.png',
            'app_url' => 'https://main.example.test',
            'currency_symbol' => '$',
        ]);
    }

    public function test_agent_domain_overlays_guest_config_with_agent_branding(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->createActiveDomain($agent, 'shop.example.test', true);
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Default Agent Site',
            'logo_url' => 'https://assets.example.test/default-logo.png',
            'announcement' => 'Default announcement',
        ]);
        $this->createSiteSetting($agent, $domain, [
            'site_name' => 'Agent Shop',
            'logo_url' => 'https://assets.example.test/agent-logo.png',
            'landing_theme' => 'spark',
            'accent_color' => '#12abef',
            'support_name' => 'Agent Support',
            'support_url' => 'https://support.example.test/help',
            'customer_service_type' => 'crisp',
            'customer_service_id' => 'agent-crisp-id',
            'announcement' => 'Agent announcement',
        ]);

        $data = $this->configForHost('shop.example.test');

        $this->assertSame('Agent Shop', $data['app_name']);
        $this->assertSame('https://assets.example.test/agent-logo.png', $data['logo']);
        $this->assertSame('spark', $data['landing_theme']);
        $this->assertSame('Agent announcement', $data['agent_announcement']);
        $this->assertSame('base-value', $data['theme_config']['existing_key']);
        $this->assertSame('spark', $data['theme_config']['landing_theme']);
        $this->assertSame('#12abef', $data['theme_config']['agent_accent_color']);
        $this->assertSame('Agent Support', $data['theme_config']['customer_service_name']);
        $this->assertSame('https://support.example.test/help', $data['theme_config']['customer_service_url']);
        $this->assertSame('crisp', $data['theme_config']['customer_service_type']);
        $this->assertSame('agent-crisp-id', $data['theme_config']['customer_service_id']);
        $this->assertSame($agent->id, $data['agent_context']['agent_user_id']);
        $this->assertSame($domain->id, $data['agent_context']['agent_domain_id']);
        $this->assertSame('shop.example.test', $data['agent_context']['domain']);
        $this->assertTrue($data['agent_context']['is_primary']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_DOMAIN, $data['agent_context']['source']);
    }

    public function test_non_agent_host_returns_base_guest_config_without_agent_context(): void
    {
        $data = $this->configForHost('main.example.test');

        $this->assertSame('Main Site', $data['app_name']);
        $this->assertSame('https://assets.example.test/main-logo.png', $data['logo']);
        $this->assertSame('base-theme', $data['landing_theme']);
        $this->assertSame('base-theme', $data['theme_config']['landing_theme']);
        $this->assertSame('base-value', $data['theme_config']['existing_key']);
        $this->assertArrayNotHasKey('agent_context', $data);
        $this->assertArrayNotHasKey('agent_announcement', $data);
    }

    public function test_agent_context_without_site_setting_rows_does_not_override_base_branding(): void
    {
        $agent = $this->createActiveAgent('empty-setting-agent@example.test');
        $domain = $this->createActiveDomain($agent, 'empty.example.test');

        $data = $this->configForHost('empty.example.test');

        $this->assertSame('Main Site', $data['app_name']);
        $this->assertSame('https://assets.example.test/main-logo.png', $data['logo']);
        $this->assertSame('base-theme', $data['landing_theme']);
        $this->assertSame('base-theme', $data['theme_config']['landing_theme']);
        $this->assertSame('base-value', $data['theme_config']['existing_key']);
        $this->assertArrayNotHasKey('agent_announcement', $data);
        $this->assertSame($agent->id, $data['agent_context']['agent_user_id']);
        $this->assertSame($domain->id, $data['agent_context']['agent_domain_id']);
        $this->assertSame('empty.example.test', $data['agent_context']['domain']);
        $this->assertFalse($data['agent_context']['is_primary']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_DOMAIN, $data['agent_context']['source']);
    }

    public function test_agent_context_with_disabled_setting_does_not_override_base_branding(): void
    {
        $agent = $this->createActiveAgent('disabled-setting-agent@example.test');
        $domain = $this->createActiveDomain($agent, 'disabled.example.test');
        $this->createSiteSetting($agent, $domain, [
            'site_name' => 'Disabled Agent Shop',
            'logo_url' => 'https://assets.example.test/disabled-logo.png',
            'landing_theme' => 'phantom',
            'enabled' => false,
        ]);

        $data = $this->configForHost('disabled.example.test');

        $this->assertSame('Main Site', $data['app_name']);
        $this->assertSame('https://assets.example.test/main-logo.png', $data['logo']);
        $this->assertSame('base-theme', $data['landing_theme']);
        $this->assertArrayNotHasKey('agent_announcement', $data);
        $this->assertSame($agent->id, $data['agent_context']['agent_user_id']);
        $this->assertSame($domain->id, $data['agent_context']['agent_domain_id']);
        $this->assertSame('disabled.example.test', $data['agent_context']['domain']);
        $this->assertFalse($data['agent_context']['is_primary']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_DOMAIN, $data['agent_context']['source']);
    }

    private function bindTestThemeConfig(): void
    {
        app()->instance(ThemeService::class, new class {
            public function exists(string $theme): bool
            {
                return $theme === 'Xboard';
            }

            public function getConfig(string $theme): array
            {
                return [
                    'landing_theme' => 'base-theme',
                    'existing_key' => 'base-value',
                ];
            }
        });
    }

    private function configForHost(string $host): array
    {
        $request = Request::create('https://' . $host . '/api/v1/guest/comm/config', 'GET');
        $request->headers->set('host', $host);

        return $this->responsePayload(app(CommController::class)->config($request))['data'];
    }

    private function createActiveAgent(string $email): User
    {
        $agent = User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
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

    private function createActiveDomain(User $agent, string $domain, bool $isPrimary = false): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => $isPrimary,
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

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}

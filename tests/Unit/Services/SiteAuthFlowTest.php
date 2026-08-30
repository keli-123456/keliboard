<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\AgentUser;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\RegisterService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteAuthFlowTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private Site $secondSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindTestHasher();
        $this->bindTestSettings([
            'captcha_enable' => 0,
            'email_gmail_limit_enable' => 0,
            'email_verify' => 0,
            'email_whitelist_enable' => 0,
            'invite_force' => 0,
            'password_limit_enable' => 0,
            'risk_center_enable' => 0,
            'stop_register' => 0,
            'try_out_plan_id' => 0,
        ]);
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();

        $this->secondSite = $this->siteWithDomain('second', 'second.example.test', false);
    }

    public function test_register_allows_same_email_on_different_sites(): void
    {
        $this->createUser('shared@example.test', 'secret-one', null);

        [$success, $result] = app(RegisterService::class)->register(
            $this->authRequest('second.example.test', 'register', [
                'email' => 'shared@example.test',
                'password' => 'secret-two',
            ])
        );

        $this->assertTrue($success);
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($this->secondSite->id, $result->site_id);
        $this->assertSame(2, User::query()->where('email', 'shared@example.test')->count());
    }

    public function test_register_on_agent_domain_allows_platform_duplicate_and_binds_only_new_user(): void
    {
        $platformUser = $this->createUser('shared@example.test', 'platform-secret', null);
        $agent = $this->createUser('agent@example.test', 'agent-password', null);
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        [$success, $result] = app(RegisterService::class)->register(
            $this->authRequest('agent.example.test', 'register', [
                'email' => 'shared@example.test',
                'password' => 'agent-secret',
            ])
        );

        $this->assertTrue($success);
        $this->assertInstanceOf(User::class, $result);
        $this->assertNotSame($platformUser->id, $result->id);
        $this->assertSame($agent->id, (int) $result->invite_user_id);
        $this->assertSame(2, User::query()->where('email', 'shared@example.test')->count());
        $this->assertSame($agent->id, (int) AgentUser::query()
            ->where('sub_user_id', $result->id)
            ->value('agent_user_id'));
        $this->assertFalse(AgentUser::query()->where('sub_user_id', $platformUser->id)->exists());
    }

    public function test_register_on_agent_domain_rejects_duplicate_owned_by_same_agent(): void
    {
        $agent = $this->createUser('agent@example.test', 'agent-password', null);
        $existing = $this->createUser('shared@example.test', 'agent-secret', $this->secondSite);
        $this->assignUserToAgentDomain($agent, $existing, 'agent.example.test');

        [$success, $result] = app(RegisterService::class)->register(
            $this->authRequest('agent.example.test', 'register', [
                'email' => 'shared@example.test',
                'password' => 'replacement-secret',
            ])
        );

        $this->assertFalse($success);
        $this->assertSame(400201, $result[0]);
        $this->assertSame(1, User::query()->where('email', 'shared@example.test')->count());
    }

    public function test_login_selects_user_from_current_site(): void
    {
        $this->createUser('shared@example.test', 'secret-one', null);
        $expected = $this->createUser('shared@example.test', 'secret-two', $this->secondSite);
        app()->instance('request', $this->authRequest('second.example.test', 'login'));

        [$success, $result] = app(LoginService::class)->login('shared@example.test', 'secret-two');

        $this->assertTrue($success);
        $this->assertSame($expected->id, $result->id);
        $this->assertSame($this->secondSite->id, $result->site_id);
    }

    public function test_login_on_platform_host_selects_platform_user(): void
    {
        $expected = $this->createUser('shared@example.test', 'secret-one', null);
        $this->createUser('shared@example.test', 'secret-two', $this->secondSite);
        app()->instance('request', $this->authRequest('main.example.test', 'login'));

        [$success, $result] = app(LoginService::class)->login('shared@example.test', 'secret-one');

        $this->assertTrue($success);
        $this->assertSame($expected->id, $result->id);
        $this->assertNull($result->site_id);
    }

    public function test_reset_password_updates_only_current_site_user(): void
    {
        $defaultUser = $this->createUser('shared@example.test', 'secret-one', null);
        $secondUser = $this->createUser('shared@example.test', 'secret-two', $this->secondSite);
        app()->instance('request', $this->authRequest('second.example.test', 'forget'));

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'site:' . $this->secondSite->id . ':shared@example.test'), '123456', 300);

        [$success, $result] = app(LoginService::class)->resetPassword(
            'shared@example.test',
            '123456',
            'new-secret'
        );

        $this->assertTrue($success);
        $this->assertTrue($result);
        $this->assertTrue(password_verify('secret-one', $defaultUser->fresh()->password));
        $this->assertTrue(password_verify('new-secret', $secondUser->fresh()->password));
    }

    public function test_login_on_agent_domain_selects_agent_owned_user_across_site_scope(): void
    {
        $this->createUser('shared@example.test', 'platform-secret', null);
        $expected = $this->createUser('shared@example.test', 'agent-secret', $this->secondSite);
        $agent = $this->createUser('agent@example.test', 'agent-password', null);
        $this->assignUserToAgentDomain($agent, $expected, 'agent.example.test');
        app()->instance('request', $this->authRequest('agent.example.test', 'login'));

        [$success, $result] = app(LoginService::class)->login('shared@example.test', 'agent-secret');

        $this->assertTrue($success);
        $this->assertSame($expected->id, $result->id);
        $this->assertSame($this->secondSite->id, $result->site_id);
    }

    public function test_reset_password_on_agent_domain_updates_only_agent_owned_user(): void
    {
        $platformUser = $this->createUser('shared@example.test', 'platform-secret', null);
        $agentUser = $this->createUser('shared@example.test', 'agent-secret', $this->secondSite);
        $agent = $this->createUser('agent@example.test', 'agent-password', null);
        $this->assignUserToAgentDomain($agent, $agentUser, 'agent.example.test');
        app()->instance('request', $this->authRequest('agent.example.test', 'forget'));

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'agent:' . $agent->id . ':shared@example.test'), '123456', 300);

        [$success, $result] = app(LoginService::class)->resetPassword(
            'shared@example.test',
            '123456',
            'new-agent-secret'
        );

        $this->assertTrue($success);
        $this->assertTrue($result);
        $this->assertTrue(password_verify('platform-secret', $platformUser->fresh()->password));
        $this->assertTrue(password_verify('new-agent-secret', $agentUser->fresh()->password));
    }

    public function test_platform_password_reset_never_updates_agent_owned_duplicate(): void
    {
        $platformUser = $this->createUser('shared@example.test', 'platform-secret', null);
        $agentUser = $this->createUser('shared@example.test', 'agent-secret', $this->secondSite);
        $agent = $this->createUser('agent@example.test', 'agent-password', null);
        $this->assignUserToAgentDomain($agent, $agentUser, 'agent.example.test');
        app()->instance('request', $this->authRequest('main.example.test', 'forget'));

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'site:platform:shared@example.test'), '123456', 300);

        [$success, $result] = app(LoginService::class)->resetPassword(
            'shared@example.test',
            '123456',
            'new-platform-secret'
        );

        $this->assertTrue($success);
        $this->assertTrue($result);
        $this->assertTrue(password_verify('new-platform-secret', $platformUser->fresh()->password));
        $this->assertTrue(password_verify('agent-secret', $agentUser->fresh()->password));
    }

    public function test_agent_domain_verification_cache_is_isolated_from_platform(): void
    {
        $agent = $this->createUser('agent@example.test', 'agent-password', null);
        $subordinate = $this->createUser('shared@example.test', 'agent-secret', $this->secondSite);
        $this->assignUserToAgentDomain($agent, $subordinate, 'agent.example.test');
        $scope = app(\App\Services\SiteUserScopeService::class);

        $this->assertSame(
            'agent:' . $agent->id . ':shared@example.test',
            $scope->cacheIdentity('shared@example.test', $this->authRequest('agent.example.test', 'forget'))
        );
        $this->assertSame(
            'site:platform:shared@example.test',
            $scope->cacheIdentity('shared@example.test', $this->authRequest('main.example.test', 'forget'))
        );
    }

    private function siteWithDomain(string $code, string $host, bool $default): Site
    {
        $site = Site::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'status' => Site::STATUS_ACTIVE,
            'is_default' => $default,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $site;
    }

    private function createUser(string $email, string $password, ?Site $site): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'site_id' => $site?->id,
            'uuid' => ($site?->code ?: 'platform') . '-uuid-' . str_replace('@', '-', $email),
            'token' => ($site?->code ?: 'platform') . '-token-' . str_replace('@', '-', $email),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function assignUserToAgentDomain(User $agent, User $subordinate, string $domain): void
    {
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $subordinate->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function authRequest(string $host, string $action, array $payload = []): Request
    {
        return Request::create('https://' . $host . '/api/v1/passport/auth/' . $action, 'POST', $payload);
    }
}

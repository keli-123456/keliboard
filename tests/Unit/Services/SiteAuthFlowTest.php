<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

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

    private Site $defaultSite;
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
        $this->createAgentCommerceTables();

        $this->defaultSite = $this->siteWithDomain('default', 'main.example.test', true);
        $this->secondSite = $this->siteWithDomain('second', 'second.example.test', false);
    }

    public function test_register_allows_same_email_on_different_sites(): void
    {
        $this->createUser('shared@example.test', 'secret-one', $this->defaultSite);

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

    public function test_login_selects_user_from_current_site(): void
    {
        $this->createUser('shared@example.test', 'secret-one', $this->defaultSite);
        $expected = $this->createUser('shared@example.test', 'secret-two', $this->secondSite);
        app()->instance('request', $this->authRequest('second.example.test', 'login'));

        [$success, $result] = app(LoginService::class)->login('shared@example.test', 'secret-two');

        $this->assertTrue($success);
        $this->assertSame($expected->id, $result->id);
        $this->assertSame($this->secondSite->id, $result->site_id);
    }

    public function test_reset_password_updates_only_current_site_user(): void
    {
        $defaultUser = $this->createUser('shared@example.test', 'secret-one', $this->defaultSite);
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

    private function createUser(string $email, string $password, Site $site): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'site_id' => $site->id,
            'uuid' => $site->code . '-uuid-' . str_replace('@', '-', $email),
            'token' => $site->code . '-token-' . str_replace('@', '-', $email),
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

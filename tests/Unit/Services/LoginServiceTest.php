<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\Auth\LoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class LoginServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    public function test_generate_quick_login_url_returns_null_for_unsaved_user(): void
    {
        $service = new LoginService();
        $user = new User();

        $url = $service->generateQuickLoginUrl($user, 'dashboard');

        $this->assertNull($url);
    }

    public function test_reset_password_rejects_empty_code_when_cached_code_is_missing(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createUserTable();

        $user = User::query()->create([
            'email' => 'user@example.com',
            'password' => 'old-password-hash',
        ]);

        [$success, $result] = (new LoginService())->resetPassword(
            'user@example.com',
            '',
            'new-password'
        );

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);
        $this->assertSame(__('Incorrect email verification code'), $result[1]);
        $this->assertSame('old-password-hash', $user->fresh()->password);
    }

    public function test_password_error_lock_is_scoped_to_email_and_ip(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->bindTestSettings([
            'password_limit_enable' => 1,
            'password_limit_count' => 2,
            'password_limit_expire' => 60,
            'login_ip_limit_count' => 100,
            'login_ip_limit_expire_seconds' => 60,
            'risk_center_enable' => 0,
        ]);
        Cache::flush();

        $user = User::query()->create([
            'email' => 'user@example.test',
            'password' => password_hash('correct-password', PASSWORD_DEFAULT),
            'token' => bin2hex(random_bytes(16)),
            'uuid' => bin2hex(random_bytes(16)),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $service = new LoginService();
        $blockedRequest = $this->loginRequest('198.51.100.10');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$success, $result] = $service->login(
                'user@example.test',
                'wrong-password',
                $blockedRequest
            );

            $this->assertFalse($success);
            $this->assertSame(400, $result[0]);
        }

        [$blocked, $blockedResult] = $service->login(
            'user@example.test',
            'correct-password',
            $blockedRequest
        );
        $this->assertFalse($blocked);
        $this->assertSame(429, $blockedResult[0]);

        [$success, $authenticatedUser] = $service->login(
            'user@example.test',
            'correct-password',
            $this->loginRequest('198.51.100.11')
        );

        $this->assertTrue($success);
        $this->assertSame($user->id, $authenticatedUser->id);
    }

    public function test_login_requests_are_rate_limited_per_ip(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->bindTestSettings([
            'password_limit_enable' => 0,
            'login_ip_limit_count' => 2,
            'login_ip_limit_expire_seconds' => 60,
            'risk_center_enable' => 0,
        ]);
        Cache::flush();

        $service = new LoginService();
        $request = $this->loginRequest('203.0.113.20');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$success, $result] = $service->login(
                'missing@example.test',
                'wrong-password',
                $request
            );

            $this->assertFalse($success);
            $this->assertSame(400, $result[0]);
        }

        [$success, $result] = $service->login(
            'missing@example.test',
            'wrong-password',
            $request
        );

        $this->assertFalse($success);
        $this->assertSame(429, $result[0]);
    }

    public function test_successful_logins_do_not_consume_the_failed_ip_budget(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->bindTestSettings([
            'password_limit_enable' => 0,
            'login_ip_limit_count' => 2,
            'login_ip_limit_expire_seconds' => 60,
            'risk_center_enable' => 0,
        ]);
        Cache::flush();

        User::query()->create([
            'email' => 'user@example.test',
            'password' => password_hash('correct-password', PASSWORD_DEFAULT),
            'token' => bin2hex(random_bytes(16)),
            'uuid' => bin2hex(random_bytes(16)),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $service = new LoginService();
        $request = $this->loginRequest('203.0.113.30');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            [$success] = $service->login(
                'user@example.test',
                'correct-password',
                $request
            );

            $this->assertTrue($success);
        }

        [$success, $result] = $service->login(
            'missing@example.test',
            'wrong-password',
            $request
        );

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);
    }

    private function loginRequest(string $ip): Request
    {
        return Request::create(
            'https://main.example.test/api/v1/passport/auth/login',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => $ip]
        );
    }
}


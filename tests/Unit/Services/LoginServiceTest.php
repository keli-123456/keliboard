<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\Auth\LoginService;
use Tests\TestCase;
use Tests\Support\InteractsWithInMemoryDatabase;

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
}


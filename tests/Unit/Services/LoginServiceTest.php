<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\Auth\LoginService;
use Tests\TestCase;

final class LoginServiceTest extends TestCase
{
    public function test_generate_quick_login_url_returns_null_for_unsaved_user(): void
    {
        $service = new LoginService();
        $user = new User();

        $url = $service->generateQuickLoginUrl($user, 'dashboard');

        $this->assertNull($url);
    }
}


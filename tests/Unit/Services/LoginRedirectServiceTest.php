<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Auth\LoginRedirectService;
use Tests\TestCase;

final class LoginRedirectServiceTest extends TestCase
{
    public function test_normalize_redirect_keeps_internal_routes(): void
    {
        $service = new LoginRedirectService();

        $this->assertSame('dashboard', $service->normalize(null));
        $this->assertSame('dashboard', $service->normalize('/dashboard'));
        $this->assertSame('order/detail?id=1', $service->normalize('/order///detail?id=1'));
    }

    public function test_normalize_redirect_rejects_external_or_scheme_urls(): void
    {
        $service = new LoginRedirectService();

        $this->assertSame('dashboard', $service->normalize('https://example.com'));
        $this->assertSame('dashboard', $service->normalize('http://example.com'));
        $this->assertSame('dashboard', $service->normalize('//example.com'));
        $this->assertSame('dashboard', $service->normalize('javascript:alert(1)'));
    }

    public function test_build_login_fragment_encodes_token_and_redirect(): void
    {
        $fragment = (new LoginRedirectService())->buildLoginFragment('token value', '/order/detail?id=1');

        $this->assertSame('/#/login?verify=token%20value&redirect=order%2Fdetail%3Fid%3D1', $fragment);
    }
}

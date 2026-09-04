<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\AuthService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AuthServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPersonalAccessTokenTable();
    }

    public function test_active_bearer_token_resolves_its_user(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('active-session', ['*'], now()->addMinute());
        $secret = explode('|', $token->plainTextToken, 2)[1];

        $resolved = AuthService::findUserByBearerToken('Bearer ' . $secret);

        $this->assertSame($user->id, $resolved?->id);
    }

    public function test_expired_bearer_token_is_rejected(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('expired-session', ['*'], now()->subMinute());
        $secret = explode('|', $token->plainTextToken, 2)[1];

        $this->assertNull(AuthService::findUserByBearerToken('Bearer ' . $secret));
    }

    public function test_global_sanctum_expiration_is_enforced(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = $this->createUser();
        $token = $user->createToken('old-session', ['*']);
        $token->accessToken->forceFill(['created_at' => now()->subMinutes(61)])->save();
        $secret = explode('|', $token->plainTextToken, 2)[1];

        $this->assertNull(AuthService::findUserByBearerToken('Bearer ' . $secret));
    }

    private function createUser(): User
    {
        return User::query()->create([
            'email' => 'staff@example.test',
            'password' => password_hash('secret', PASSWORD_DEFAULT),
            'uuid' => bin2hex(random_bytes(16)),
            'token' => bin2hex(random_bytes(16)),
            'is_staff' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

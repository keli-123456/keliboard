<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\SessionController;
use App\Http\Middleware\Admin;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use App\Services\AuthService;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Auth\Guard as AuthGuard;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\Concerns\InteractsWithTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Guard;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\TransientToken;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminSessionPolicyTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    use InteractsWithTime;

    private Guard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPersonalAccessTokenTable();
        config(['sanctum.guard' => [], 'sanctum.expiration' => null]);
        app()->instance('events', new Dispatcher(app()));
        (new AuthServiceProvider(app()))->boot();
        $this->guard = new Guard($this->createStub(Factory::class));
    }

    protected function tearDown(): void
    {
        Sanctum::$accessTokenAuthenticationCallback = null;
        $this->travelBack();
        parent::tearDown();
    }

    public function test_admin_tokens_expire_at_eight_hours_without_a_sliding_extension(): void
    {
        $this->freezeSecond();
        $user = $this->user(true);
        $token = $this->signIn($user);
        $stored = PersonalAccessToken::findToken(substr($token, 7));
        $this->assertTrue($stored->expires_at->equalTo(now()->addHours(8)));

        $this->travel(479)->minutes();
        $this->assertAccepted($token, $user);
        $this->travel(1)->minutes();
        $this->assertRejected($token);
    }

    public function test_sixth_login_evicts_only_the_oldest_active_session_for_the_same_admin(): void
    {
        $admin = $this->user(true);
        $otherAdmin = $this->user(true);
        $otherSession = $this->signIn($otherAdmin);
        $sessions = [];
        for ($index = 0; $index < 6; $index++) {
            $sessions[] = $this->signIn($admin);
        }

        $this->assertSame(5, $admin->tokens()->count());
        $this->assertRejected(array_shift($sessions));
        foreach ($sessions as $session) {
            $this->assertAccepted($session, $admin);
        }
        $this->assertAccepted($otherSession, $otherAdmin);
    }

    public function test_legacy_admin_tokens_stay_invalid_and_regular_sessions_and_credentials_are_unchanged(): void
    {
        $this->freezeSecond();
        $admin = $this->user(true);
        $legacy = 'Bearer ' . $admin->createToken('legacy', ['*'], now()->addYear())->plainTextToken;
        $this->assertRejected($legacy);
        $this->assertAccepted($this->signIn($admin), $admin);
        $this->assertRejected($legacy);

        $user = $this->user(false);
        $uuid = $user->uuid;
        $subscriptionToken = $user->token;
        $sessions = [];
        for ($index = 0; $index < 6; $index++) {
            $sessions[] = $this->signIn($user);
        }

        $this->assertSame(6, $user->tokens()->count());
        $this->assertTrue($user->tokens()->first()->expires_at->equalTo(now()->addYear()));
        $this->assertSame($uuid, $user->fresh()->uuid);
        $this->assertSame($subscriptionToken, $user->fresh()->token);
        $this->travel(8)->hours();
        $this->assertAccepted($sessions[0], $user);
    }

    public function test_logout_revokes_only_the_authenticated_admin_token(): void
    {
        $user = $this->user(true);
        $current = $this->signIn($user);
        $other = $this->signIn($user);
        $otherId = PersonalAccessToken::findToken(substr($other, 7))->getKey();
        $request = $this->request($current, ['id' => $otherId]);
        $actor = ($this->guard)($request);
        $request->setUserResolver(static fn () => $actor);
        $this->setAuthenticatedActor($actor);

        $response = (new Admin())->handle($request, fn () => (new SessionController())->logout($request));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertRejected($current);
        $this->assertAccepted($other, $user);
    }

    public function test_admin_logout_is_unavailable_to_regular_users(): void
    {
        $request = $this->request($this->signIn($this->user(false)));
        $this->setAuthenticatedActor(($this->guard)($request));

        $this->expectExceptionCode(403);
        (new Admin())->handle($request, fn () => $this->fail('Regular user reached the admin endpoint'));
    }

    public function test_logout_does_not_revoke_a_bearer_when_authenticated_by_cookie(): void
    {
        $user = $this->user(true);
        $token = $this->signIn($user);
        $request = $this->request($token);
        $request->setUserResolver(static fn () => $user->withAccessToken(new TransientToken()));

        try {
            (new SessionController())->logout($request);
            $this->fail('Expected a bearer session to be required');
        } catch (ApiException $error) {
            $this->assertSame(409, $error->getCode());
        }
        $this->assertAccepted($token, $user);
    }

    private function user(bool $admin): User
    {
        return User::query()->create([
            'email' => bin2hex(random_bytes(5)) . '@example.test',
            'password' => 'fixture',
            'uuid' => bin2hex(random_bytes(16)),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin,
        ]);
    }

    private function signIn(User $user): string
    {
        return (new AuthService($user))->generateAuthData()['auth_data'];
    }

    private function request(string $token, array $data = []): Request
    {
        return Request::create('/auth/logout', 'POST', $data, [], [], ['HTTP_AUTHORIZATION' => $token]);
    }

    private function setAuthenticatedActor(User $actor): void
    {
        $guard = $this->createStub(AuthGuard::class);
        $guard->method('user')->willReturn($actor);
        $factory = $this->createStub(Factory::class);
        $factory->method('guard')->willReturn($guard);
        Auth::swap($factory);
    }

    private function assertAccepted(string $token, User $user): void
    {
        $this->assertSame($user->id, AuthService::findUserByBearerToken($token)?->id);
        $this->assertSame($user->id, ($this->guard)($this->request($token))?->id);
    }

    private function assertRejected(string $token): void
    {
        $this->assertNull(AuthService::findUserByBearerToken($token));
        $this->assertNull(($this->guard)($this->request($token)));
    }
}

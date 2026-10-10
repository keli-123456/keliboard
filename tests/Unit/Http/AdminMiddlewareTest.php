<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Exceptions\Handler;
use App\Http\Middleware\Admin;
use App\Models\User;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Guard as SanctumGuard;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminMiddlewareTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPersonalAccessTokenTable();
        $this->bindJsonResponseFactory();
        app()->instance('events', new Dispatcher(app()));
        config([
            'auth.providers.users.model' => User::class,
            'sanctum.guard' => [],
        ]);
    }

    public function test_missing_bearer_token_returns_401(): void
    {
        $this->assertRejected(null, 401);
    }

    public function test_invalid_bearer_token_returns_401(): void
    {
        $this->assertRejected('invalid-session', 401);
    }

    public function test_expired_admin_token_returns_401(): void
    {
        $token = $this->createUser()->createToken('expired-session', ['*'], now()->subMinute());

        $this->assertRejected($token->plainTextToken, 401);
    }

    public function test_revoked_admin_token_returns_401(): void
    {
        $token = $this->createUser()->createToken('revoked-session', ['*']);
        $token->accessToken->delete();

        $this->assertRejected($token->plainTextToken, 401);
    }

    public function test_global_token_expiration_returns_401(): void
    {
        $token = $this->createUser()->createToken('old-session', ['*']);
        $token->accessToken->forceFill(['created_at' => now()->subMinutes(61)])->save();

        $this->assertRejected($token->plainTextToken, 401, 60);
    }

    public function test_authenticated_non_admin_returns_403(): void
    {
        $token = $this->createUser(false)->createToken('user-session', ['*']);

        $this->assertRejected($token->plainTextToken, 403);
    }

    public function test_authenticated_staff_without_admin_permission_returns_403(): void
    {
        $user = $this->createUser(false);
        $user->forceFill(['is_staff' => true])->save();
        $token = $user->createToken('staff-session', ['*']);

        $this->assertRejected($token->plainTextToken, 403);
    }

    public function test_authenticated_admin_can_continue(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('admin-session', ['*'], now()->addMinute());
        $request = $this->authenticatedRequest($token->plainTextToken);
        $response = new \Illuminate\Http\JsonResponse(['data' => true]);

        $result = (new Admin())->handle($request, function (Request $received) use ($request, $user, $response) {
            $this->assertSame($request, $received);
            $this->assertSame($user->id, Auth::guard()->user()?->id);

            return $response;
        });

        $this->assertSame($response, $result);
    }

    private function assertRejected(?string $token, int $status, ?int $expiration = null): void
    {
        $request = $this->authenticatedRequest($token, $expiration);
        $continued = false;

        try {
            (new Admin())->handle($request, function () use (&$continued) {
                $continued = true;
            });
            $this->fail('The protected request should have been rejected.');
        } catch (ApiException $exception) {
            $response = (new Handler(app()))->render($request, $exception);

            $this->assertSame($status, $exception->getCode());
            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame('fail', $response->getData(true)['status']);
            $this->assertSame('Unauthorized', $response->getData(true)['message']);
        }

        $this->assertFalse($continued);
    }

    private function authenticatedRequest(?string $token, ?int $expiration = null): Request
    {
        $request = Request::create('/api/v2/admin/config/fetch');
        if ($token !== null) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }
        $request->headers->set('Accept', 'application/json');
        $auth = $this->createMock(AuthFactory::class);
        $guard = new RequestGuard(new SanctumGuard($auth, $expiration, 'users'), $request);
        $auth->method('guard')->willReturn($guard);
        Auth::swap($auth);

        return $request;
    }

    private function createUser(bool $isAdmin = true): User
    {
        return User::query()->create([
            'email' => 'auth-test@example.test',
            'is_admin' => $isAdmin,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminUserControllerRegressionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createPersonalAccessTokenTable();
    }

    public function test_update_without_invite_user_email_preserves_existing_inviter(): void
    {
        $inviter = User::create([
            'email' => 'inviter@example.com',
            'password' => 'secret',
            'token' => 'inviter-token',
            'uuid' => 'inviter-uuid',
        ]);

        $user = User::create([
            'email' => 'customer@example.com',
            'password' => 'secret',
            'token' => 'customer-token',
            'uuid' => 'customer-uuid',
            'invite_user_id' => $inviter->id,
            'balance' => 500,
        ]);

        $response = (new UserController())->update($this->userUpdateRequest([
            'id' => $user->id,
            'email' => 'customer@example.com',
            'balance' => 5,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($inviter->id, (int) $user->fresh()->invite_user_id);
        $this->assertSame(500, (int) $user->fresh()->balance);
    }

    public function test_batch_ban_without_filters_or_confirmation_is_rejected(): void
    {
        $response = (new UserController())->ban(Request::create('/admin/user/ban', 'POST'));
        $payload = $response->getData(true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fail', $payload['status']);
        $this->assertSame('批量封禁全部用户需要确认', $payload['message']);
    }

    public function test_batch_ban_by_ids_skips_admins_and_clears_tokens(): void
    {
        $admin = User::create([
            'email' => 'admin@example.com',
            'password' => 'secret',
            'token' => 'admin-token',
            'uuid' => 'admin-uuid',
            'is_admin' => true,
        ]);
        $user = User::create([
            'email' => 'customer@example.com',
            'password' => 'secret',
            'token' => 'customer-token',
            'uuid' => 'customer-uuid',
            'is_admin' => false,
        ]);

        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'test',
            'token' => str_repeat('a', 64),
        ]);

        $request = Request::create('/admin/user/ban', 'POST', [
            'ids' => [$admin->id, $user->id],
        ]);
        $request->setUserResolver(fn () => $admin);

        $response = (new UserController())->ban($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $payload['data']['affected_count']);
        $this->assertFalse((bool) $admin->fresh()->banned);
        $this->assertTrue((bool) $user->fresh()->banned);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function userUpdateRequest(array $payload): UserUpdate
    {
        return new class($payload) extends UserUpdate {
            /**
             * @param array<string, mixed> $payload
             */
            public function __construct(private array $payload)
            {
                parent::__construct([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST']);
            }

            public function validated($key = null, $default = null)
            {
                return $key === null ? $this->payload : ($this->payload[$key] ?? $default);
            }
        };
    }
}

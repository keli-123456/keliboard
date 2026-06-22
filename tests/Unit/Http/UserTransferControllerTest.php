<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\UserController;
use App\Http\Requests\User\UserTransfer;
use App\Models\User;
use App\Services\Auth\LoginService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserTransferControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
    }

    public function test_transfer_moves_commission_to_account_balance_and_returns_new_balances(): void
    {
        $user = $this->createUser(balance: 2500, commissionBalance: 1500);

        $response = $this->controller()->transfer($this->transferRequest($user, 600));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $payload['status']);
        $this->assertSame([
            'transferred_amount' => 600,
            'commission_balance' => 900,
            'balance' => 3100,
        ], $payload['data']);

        $user->refresh();
        $this->assertSame(900, (int) $user->commission_balance);
        $this->assertSame(3100, (int) $user->balance);
    }

    public function test_transfer_rejects_amount_above_current_commission_and_returns_current_balances(): void
    {
        $user = $this->createUser(balance: 2500, commissionBalance: 500);

        $response = $this->controller()->transfer($this->transferRequest($user, 600));
        $payload = $response->getData(true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fail', $payload['status']);
        $this->assertSame('Insufficient commission balance', $payload['message']);
        $this->assertSame([
            'commission_balance' => 500,
            'balance' => 2500,
        ], $payload['data']);

        $user->refresh();
        $this->assertSame(500, (int) $user->commission_balance);
        $this->assertSame(2500, (int) $user->balance);
    }

    private function controller(): UserController
    {
        return new UserController(new LoginService());
    }

    private function createUser(int $balance, int $commissionBalance): User
    {
        return User::query()->create([
            'email' => uniqid('transfer-user-', true) . '@example.test',
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => uniqid('uuid-', true),
            'token' => uniqid('token-', true),
            'balance' => $balance,
            'commission_balance' => $commissionBalance,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function transferRequest(User $user, int $amount): UserTransfer
    {
        $request = UserTransfer::create('/api/v1/user/transfer', 'POST', [
            'transfer_amount' => $amount,
        ]);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}

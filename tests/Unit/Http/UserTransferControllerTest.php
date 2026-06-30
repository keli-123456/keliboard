<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\UserController;
use App\Http\Requests\User\UserTransfer;
use App\Models\AgentUser;
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
        $this->createAgentCenterTables();
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

    public function test_agent_subordinate_cannot_transfer_commission_to_account_balance(): void
    {
        $agent = $this->createUser(balance: 0, commissionBalance: 0);
        $subordinate = $this->createUser(balance: 2500, commissionBalance: 1500);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $subordinate->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $response = $this->controller()->transfer($this->transferRequest($subordinate, 600));
        $payload = $response->getData(true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('fail', $payload['status']);
        $this->assertSame('代理下级用户不参与普通邀请返利', $payload['message']);

        $subordinate->refresh();
        $this->assertSame(1500, (int) $subordinate->commission_balance);
        $this->assertSame(2500, (int) $subordinate->balance);
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

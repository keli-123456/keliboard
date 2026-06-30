<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentUser;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class OrderInviteAgentSubordinateTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
    }

    public function test_agent_subordinate_does_not_generate_regular_invite_commission(): void
    {
        $agent = $this->createUser('agent@example.test');
        $subordinate = $this->createUser('subordinate@example.test', [
            'invite_user_id' => $agent->id,
        ]);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $subordinate->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $order = new Order();
        $order->total_amount = 1200;
        $order->invite_user_id = $agent->id;
        $order->commission_balance = 999;

        (new OrderService($order))->setInvite($subordinate);

        $this->assertNull($order->invite_user_id);
        $this->assertSame(0, (int) $order->commission_balance);
    }

    private function createUser(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}

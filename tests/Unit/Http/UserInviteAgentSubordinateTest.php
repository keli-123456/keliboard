<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\InviteController;
use App\Models\AgentUser;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserInviteAgentSubordinateTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createOrderTable();
        $this->createInviteCodeTable();
        $this->createCommissionLogTable();
    }

    public function test_agent_subordinate_cannot_view_invite_rebate_center(): void
    {
        [$agent, $subordinate] = $this->createAgentBinding();

        $response = (new InviteController())->fetch($this->requestForUser($subordinate));
        $payload = $response->getData(true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('fail', $payload['status']);
        $this->assertSame('代理下级用户不参与普通邀请返利', $payload['message']);
        $this->assertSame($agent->id, AgentUser::query()->where('sub_user_id', $subordinate->id)->value('agent_user_id'));
    }

    public function test_agent_subordinate_cannot_create_invite_code(): void
    {
        [, $subordinate] = $this->createAgentBinding();

        $response = (new InviteController())->save($this->requestForUser($subordinate, 'POST'));
        $payload = $response->getData(true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('fail', $payload['status']);
        $this->assertSame('代理下级用户不参与普通邀请返利', $payload['message']);
    }

    private function createAgentBinding(): array
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

        return [$agent, $subordinate];
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

    private function requestForUser(User $user, string $method = 'GET'): Request
    {
        $request = Request::create('/api/v1/user/invite/fetch', $method);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function createInviteCodeTable(): void
    {
        $this->database->schema()->create('v2_invite_code', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->char('code', 32);
            $table->boolean('status')->default(false);
            $table->integer('pv')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createCommissionLogTable(): void
    {
        $this->database->schema()->create('v2_commission_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('invite_user_id')->index();
            $table->integer('user_id')->index();
            $table->string('trade_no', 64)->nullable();
            $table->integer('order_amount')->default(0);
            $table->integer('get_amount')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }
}

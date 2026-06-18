<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\User\OrderSave;
use App\Models\AgentDomain;
use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Http\Controllers\V1\User\OrderController;
use App\Services\AgentCenterService;
use App\Services\Auth\RegisterService;
use Illuminate\Http\Request as BaseRequest;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentDomainOrderFlowTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindRequestValidateMacro();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->bindTestHasher();
        $this->bindTestSettings([
            'captcha_enable' => 0,
            'email_whitelist_enable' => 0,
            'email_gmail_limit_enable' => 0,
            'stop_register' => 0,
            'invite_force' => 0,
            'email_verify' => 0,
            'register_limit_by_ip_enable' => 0,
            'try_out_plan_id' => 0,
            'default_remind_expire' => 1,
            'default_remind_traffic' => 1,
            'agent_center_discount_percent' => 50,
            'plan_change_enable' => 1,
        ]);
    }

    private function bindRequestValidateMacro(): void
    {
        if (BaseRequest::hasMacro('validate')) {
            return;
        }

        BaseRequest::macro('validate', function (array $rules = [], ...$parameters): array {
            return $this->all();
        });
    }

    public function test_order_save_through_agent_domain_creates_agent_order(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer = $this->createUser('buyer@example.test');
        $request = OrderSave::create('/api/v1/user/order/save', 'POST', [
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
        ], [], [], [
            'HTTP_HOST' => 'agent.example.test',
        ]);
        $request->setUserResolver(static fn (): User => $buyer);

        $response = app(OrderController::class)->save($request);
        $payload = $response->getData(true);
        $order = Order::query()->where('trade_no', $payload['data'])->first();

        $this->assertSame('success', $payload['status']);
        $this->assertNotNull($order);
        $this->assertSame(1300, (int) $order->total_amount);
        $this->assertSame(1, AgentBalanceHold::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, AgentOrderContext::query()->where('order_id', $order->id)->count());
    }

    public function test_registration_through_agent_domain_binds_user_to_agent(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = AuthRegister::create('/api/v1/passport/auth/register', 'POST', [
            'email' => 'buyer@example.test',
            'password' => 'secret123',
        ], [], [], [
            'HTTP_HOST' => 'agent.example.test',
        ]);

        [$success, $result] = app(RegisterService::class)->register($request);

        $this->assertTrue($success);
        $this->assertInstanceOf(User::class, $result);
        $registeredUser = $result->fresh();
        $this->assertSame($agent->id, (int) $registeredUser->invite_user_id);
        $this->assertSame(1, DB::table('v2_agent_user')
            ->where('agent_user_id', $agent->id)
            ->where('sub_user_id', $registeredUser->id)
            ->count());
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email, 10000);

        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createUser(string $email, int $balance = 0): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => $balance,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPlan(string $name, array $prices): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'prices' => $prices,
            'transfer_enable' => 100,
            'group_id' => 1,
            'speed_limit' => 100,
            'device_limit' => 3,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

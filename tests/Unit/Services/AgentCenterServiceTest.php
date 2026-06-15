<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCenterServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPlanTable();
        $this->createAgentTables();
        $this->bindAgentSettings();
    }

    public function test_unlock_creates_active_profile_when_balance_meets_threshold(): void
    {
        $agent = $this->createUser('agent@example.test', 10000);

        $result = app(AgentCenterService::class)->unlock($agent);

        $this->assertSame('active', $result['profile']['status']);
        $this->assertSame(1, $this->tableCount('v2_agent_profile'));
        $this->assertSame(0, $this->ledgerCount('unlock'));
    }

    public function test_unlock_rejects_user_below_balance_threshold(): void
    {
        $agent = $this->createUser('agent@example.test', 100);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent unlock threshold has not been reached');

        app(AgentCenterService::class)->unlock($agent);
    }

    public function test_create_subordinate_assigns_unique_agent_ownership(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);

        $created = app(AgentCenterService::class)->createSubordinate($agent, [
            'email' => 'buyer@example.test',
            'password' => 'secret123',
            'remark' => 'first customer',
        ]);

        $this->assertSame('buyer@example.test', $created['user']['email']);
        $this->assertSame('first customer', $created['user']['remark']);
        $this->assertSame(1, $this->tableCount('v2_agent_user'));
    }

    public function test_assign_plan_deducts_agent_balance_updates_subordinate_and_writes_ledger(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);

        $result = app(AgentCenterService::class)->assignPlan($agent, $subordinate->id, [
            'plan_id' => $plan->id,
            'period' => 'monthly',
        ]);

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(8000, (int) $agent->balance);
        $this->assertSame($plan->id, (int) $subordinate->plan_id);
        $this->assertSame($plan->group_id, (int) $subordinate->group_id);
        $this->assertSame(128 * 1073741824, (int) $subordinate->transfer_enable);
        $this->assertSame(0, (int) $subordinate->u);
        $this->assertSame(0, (int) $subordinate->d);
        $this->assertSame(-2000, (int) $result['ledger']['amount']);
        $this->assertSame(1, $this->ledgerCount('assign_plan'));
    }

    public function test_assign_plan_rolls_back_when_balance_is_insufficient(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 100);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);

        try {
            app(AgentCenterService::class)->assignPlan($agent, $subordinate->id, [
                'plan_id' => $plan->id,
                'period' => 'monthly',
            ]);
            $this->fail('Expected insufficient balance exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Insufficient balance', $exception->getMessage());
        }

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(100, (int) $agent->balance);
        $this->assertNull($subordinate->plan_id);
        $this->assertSame(0, $this->ledgerCount('assign_plan'));
    }

    public function test_reset_traffic_deducts_reset_price_and_clears_usage(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $plan = $this->createPlan('Starter', ['monthly' => 20.00, 'reset_traffic' => 3.50], 128, 2);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'plan_id' => $plan->id,
            'u' => 1024,
            'd' => 2048,
        ]);

        $result = app(AgentCenterService::class)->resetTraffic($agent, $subordinate->id);

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(9650, (int) $agent->balance);
        $this->assertSame(0, (int) $subordinate->u);
        $this->assertSame(0, (int) $subordinate->d);
        $this->assertSame(-350, (int) $result['ledger']['amount']);
        $this->assertSame(1, $this->ledgerCount('reset_traffic'));
    }

    private function createPlanTable(): void
    {
        $this->database->schema()->create('v2_plan', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id')->nullable();
            $table->integer('transfer_enable')->default(0);
            $table->string('name');
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('renew')->default(true);
            $table->boolean('sell')->default(true);
            $table->integer('sort')->default(0);
            $table->text('content')->nullable();
            $table->json('prices')->nullable();
            $table->json('tags')->nullable();
            $table->json('upgrade_to_plan_ids')->nullable();
            $table->integer('reset_traffic_method')->nullable();
            $table->integer('capacity_limit')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createAgentTables(): void
    {
        $this->database->schema()->create('v2_agent_profile', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->unique();
            $table->string('status', 32)->default('pending');
            $table->string('level', 64)->default('default');
            $table->string('remark')->nullable();
            $table->integer('enabled_at')->nullable();
            $table->integer('disabled_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_agent_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('sub_user_id')->unique();
            $table->string('remark')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_agent_ledger', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('target_user_id')->nullable()->index();
            $table->string('type', 64)->index();
            $table->integer('amount')->default(0);
            $table->integer('balance_before')->default(0);
            $table->integer('balance_after')->default(0);
            $table->integer('plan_id')->nullable();
            $table->string('period', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
        });
    }

    private function bindAgentSettings(array $overrides = []): void
    {
        $settings = array_merge([
            'agent_center_enable' => 1,
            'agent_center_unlock_mode' => 'balance_threshold',
            'agent_center_unlock_balance' => 5000,
            'agent_center_auto_activate' => 1,
            'agent_center_allowed_plan_ids' => '',
            'agent_center_discount_percent' => 100,
            'agent_center_daily_create_limit' => 20,
            'agent_center_allow_traffic_reset' => 1,
            'agent_center_reset_price_mode' => 'plan_reset_price',
        ], $overrides);

        app()->instance(Setting::class, new class($settings) {
            public function __construct(private array $settings) {}

            public function get(string $key): mixed
            {
                return $this->settings[$key] ?? null;
            }

            public function save(array $settings): void
            {
                $this->settings = array_merge($this->settings, $settings);
            }

            public function toArray(): array
            {
                return $this->settings;
            }

            public function getBatch(array $keys): array
            {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->get($key);
                }
                return $result;
            }
        });
    }

    private function createUser(string $email, int $balance = 0, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => 'hashed',
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => $balance,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'expired_at' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }

    private function createActiveAgent(string $email, int $balance): User
    {
        $agent = $this->createUser($email, $balance);
        $this->database->table('v2_agent_profile')->insert([
            'user_id' => $agent->id,
            'status' => 'active',
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $agent;
    }

    private function createOwnedSubordinate(User $agent, string $email, array $attributes = []): User
    {
        $subordinate = $this->createUser($email, 0, $attributes);
        $this->database->table('v2_agent_user')->insert([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $subordinate->id,
            'remark' => $attributes['remark'] ?? null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $subordinate;
    }

    private function createPlan(string $name, array $prices, int $transferEnable, int $groupId): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'prices' => $prices,
            'transfer_enable' => $transferEnable,
            'group_id' => $groupId,
            'speed_limit' => 100,
            'device_limit' => 3,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function ledgerCount(string $type): int
    {
        return (int) $this->database->table('v2_agent_ledger')->where('type', $type)->count();
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->table($table)->count();
    }
}

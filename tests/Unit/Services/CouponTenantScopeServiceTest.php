<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentUser;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class CouponTenantScopeServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createAgentCenterTables();
        $this->createCouponTable();
    }

    public function test_global_coupon_remains_usable_for_any_site_user(): void
    {
        $user = $this->createUser('buyer@example.test', ['site_id' => 2]);
        $coupon = $this->createCoupon([
            'code' => 'GLOBAL10',
            'scope_type' => 'global',
            'type' => 1,
            'value' => 1000,
            'limit_use' => 3,
        ]);
        $order = $this->orderForUser($user);

        $result = (new CouponService('GLOBAL10'))->use($order);

        $this->assertTrue($result);
        $this->assertSame($coupon->id, (new CouponService('GLOBAL10'))->getId());
        $this->assertSame(1000, (int) $order->discount_amount);
        $this->assertSame(2, (int) $coupon->fresh()->limit_use);
    }

    public function test_site_coupon_rejects_user_from_another_site(): void
    {
        $user = $this->createUser('buyer@example.test', ['site_id' => 2]);
        $this->createCoupon([
            'code' => 'SITEONLY',
            'scope_type' => 'site',
            'site_id' => 1,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This coupon is not available for the current site');

        (new CouponService('SITEONLY'))->use($this->orderForUser($user));
    }

    public function test_same_code_prefers_matching_site_coupon_over_global_coupon(): void
    {
        $user = $this->createUser('buyer@example.test', ['site_id' => 8]);
        $globalCoupon = $this->createCoupon([
            'code' => 'SHARED',
            'scope_type' => 'global',
            'value' => 100,
            'limit_use' => 5,
        ]);
        $siteCoupon = $this->createCoupon([
            'code' => 'SHARED',
            'scope_type' => 'site',
            'site_id' => 8,
            'value' => 300,
            'limit_use' => 5,
        ]);
        $order = $this->orderForUser($user);

        $result = (new CouponService('SHARED'))->use($order);

        $this->assertTrue($result);
        $this->assertSame(300, (int) $order->discount_amount);
        $this->assertSame(5, (int) $globalCoupon->fresh()->limit_use);
        $this->assertSame(4, (int) $siteCoupon->fresh()->limit_use);
    }

    public function test_agent_coupon_rejects_unowned_user(): void
    {
        $agent = $this->createUser('agent@example.test');
        $user = $this->createUser('buyer@example.test');
        $this->createCoupon([
            'code' => 'AGENTONLY',
            'scope_type' => 'agent',
            'agent_user_id' => $agent->id,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This coupon is only available for the specified agent users');

        (new CouponService('AGENTONLY'))->use($this->orderForUser($user));
    }

    public function test_agent_coupon_applies_for_owned_user_and_decrements_usage(): void
    {
        $agent = $this->createUser('agent@example.test');
        $user = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $user->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $coupon = $this->createCoupon([
            'code' => 'AGENTOK',
            'scope_type' => 'agent',
            'agent_user_id' => $agent->id,
            'type' => 2,
            'value' => 25,
            'limit_use' => 1,
        ]);
        $order = $this->orderForUser($user, ['total_amount' => 2000]);

        $result = (new CouponService('AGENTOK'))->use($order);

        $this->assertTrue($result);
        $this->assertSame(500, (int) $order->discount_amount);
        $this->assertSame(0, (int) $coupon->fresh()->limit_use);
    }

    private function createCouponTable(): void
    {
        $this->database->schema()->create('v2_coupon', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code');
            $table->string('name');
            $table->integer('type');
            $table->integer('value');
            $table->boolean('show')->default(true);
            $table->integer('limit_use')->nullable();
            $table->integer('limit_use_with_user')->nullable();
            $table->string('limit_plan_ids')->nullable();
            $table->string('limit_period')->nullable();
            $table->string('scope_type', 16)->default('global')->index();
            $table->integer('site_id')->nullable()->index();
            $table->integer('agent_user_id')->nullable()->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->integer('started_at');
            $table->integer('ended_at');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    private function createCoupon(array $overrides = []): Coupon
    {
        return Coupon::query()->create(array_merge([
            'code' => 'CP' . strtoupper(substr(md5((string) microtime(true)), 0, 8)),
            'name' => 'Scoped coupon',
            'type' => 1,
            'value' => 500,
            'show' => true,
            'limit_use' => null,
            'limit_use_with_user' => null,
            'limit_plan_ids' => null,
            'limit_period' => null,
            'scope_type' => 'global',
            'site_id' => null,
            'agent_user_id' => null,
            'agent_domain_id' => null,
            'started_at' => time() - 3600,
            'ended_at' => time() + 3600,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function createUser(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => 'hashed',
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function orderForUser(User $user, array $overrides = []): Order
    {
        $order = new Order(array_merge([
            'user_id' => $user->id,
            'site_id' => $user->site_id,
            'plan_id' => 1,
            'period' => 'month_price',
            'total_amount' => 1000,
            'discount_amount' => 0,
        ], $overrides));

        return $order;
    }
}

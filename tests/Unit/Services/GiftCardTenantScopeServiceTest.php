<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentLedger;
use App\Models\AgentUser;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\GiftCardService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class GiftCardTenantScopeServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPlanTable();
        $this->createAgentCenterTables();
        $this->createGiftCardTables();
        $this->bindTestSettings([
            'agent_center_enable' => 1,
            'agent_center_discount_percent' => 100,
            'agent_center_bonus_day_price' => 100,
            'agent_center_allow_traffic_reset' => 1,
            'agent_center_reset_price_mode' => 'plan_reset_price',
            'agent_center_gift_card_traffic_gb_price' => 0,
            'agent_center_gift_card_device_price' => 0,
        ]);
    }

    public function test_site_scoped_card_rejects_user_from_another_site(): void
    {
        $user = $this->createUser('buyer@example.test', ['site_id' => 2]);
        $code = $this->createGiftCardCode([
            'scope_type' => GiftCardTemplate::SCOPE_SITE,
            'site_id' => 1,
        ]);

        $service = (new GiftCardService($code->code))->setUser($user);

        $eligibility = $service->checkUserEligibility();

        $this->assertFalse($eligibility['can_redeem']);
        $this->assertSame('site_scope_mismatch', $eligibility['reason_code']);
    }

    public function test_agent_scoped_card_rejects_unowned_user(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $user = $this->createUser('buyer@example.test');
        $code = $this->createGiftCardCode([
            'scope_type' => GiftCardTemplate::SCOPE_AGENT,
            'agent_user_id' => $agent->id,
        ]);

        $service = (new GiftCardService($code->code))->setUser($user);

        $eligibility = $service->checkUserEligibility();

        $this->assertFalse($eligibility['can_redeem']);
        $this->assertSame('agent_scope_mismatch', $eligibility['reason_code']);
    }

    public function test_agent_scoped_card_deducts_balance_and_records_scope(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $user = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $code = $this->createGiftCardCode([
            'scope_type' => GiftCardTemplate::SCOPE_AGENT,
            'agent_user_id' => $agent->id,
            'rewards' => [
                'balance' => 200,
                'expire_days' => 3,
            ],
        ]);
        $beforeExpiry = (int) $user->expired_at;

        $result = (new GiftCardService($code->code))
            ->setUser($user)
            ->redeem(['user_agent' => 'UnitTest']);

        $agent->refresh();
        $user->refresh();
        $usage = GiftCardUsage::query()->firstOrFail();
        $ledger = AgentLedger::query()->firstOrFail();

        $this->assertSame(9500, (int) $agent->balance);
        $this->assertSame(200, (int) $user->balance);
        $this->assertGreaterThanOrEqual($beforeExpiry + 3 * 86400, (int) $user->expired_at);
        $this->assertSame(GiftCardTemplate::SCOPE_AGENT, $usage->scope_type);
        $this->assertSame($agent->id, (int) $usage->agent_user_id);
        $this->assertSame('gift_card_redeem', (string) $ledger->type);
        $this->assertSame(-500, (int) $ledger->amount);
        $this->assertSame($user->id, (int) $ledger->target_user_id);
        $this->assertSame(500, (int) data_get($result, 'agent_charge.amount'));
    }

    public function test_agent_scoped_card_rolls_back_when_agent_balance_is_insufficient(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 200);
        $user = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $code = $this->createGiftCardCode([
            'scope_type' => GiftCardTemplate::SCOPE_AGENT,
            'agent_user_id' => $agent->id,
            'rewards' => [
                'balance' => 200,
                'expire_days' => 3,
            ],
        ]);

        try {
            (new GiftCardService($code->code))->setUser($user)->redeem();
            $this->fail('Expected insufficient balance exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Insufficient balance', $exception->getMessage());
        }

        $agent->refresh();
        $user->refresh();
        $code->refresh();

        $this->assertSame(200, (int) $agent->balance);
        $this->assertSame(0, (int) $user->balance);
        $this->assertSame(GiftCardCode::STATUS_UNUSED, (int) $code->status);
        $this->assertSame(0, GiftCardUsage::query()->count());
        $this->assertSame(0, AgentLedger::query()->count());
    }

    private function createGiftCardTables(): void
    {
        $this->database->schema()->create('v2_gift_card_template', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->tinyInteger('type');
            $table->tinyInteger('status')->default(1);
            $table->string('scope_type', 16)->default(GiftCardTemplate::SCOPE_GLOBAL)->index();
            $table->integer('site_id')->nullable()->index();
            $table->integer('agent_user_id')->nullable()->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->json('conditions')->nullable();
            $table->json('rewards');
            $table->json('limits')->nullable();
            $table->json('special_config')->nullable();
            $table->string('icon')->nullable();
            $table->string('background_image')->nullable();
            $table->string('theme_color', 7)->default('#1890ff');
            $table->integer('sort')->default(0);
            $table->integer('admin_id');
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        $this->database->schema()->create('v2_gift_card_code', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('template_id')->index();
            $table->string('code', 32)->unique();
            $table->string('batch_id', 32)->nullable();
            $table->tinyInteger('status')->default(GiftCardCode::STATUS_UNUSED);
            $table->integer('user_id')->nullable();
            $table->integer('used_at')->nullable();
            $table->integer('expires_at')->nullable();
            $table->json('actual_rewards')->nullable();
            $table->integer('usage_count')->default(0);
            $table->integer('max_usage')->default(1);
            $table->json('metadata')->nullable();
            $table->string('scope_type', 16)->default(GiftCardTemplate::SCOPE_GLOBAL)->index();
            $table->integer('site_id')->nullable()->index();
            $table->integer('agent_user_id')->nullable()->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        $this->database->schema()->create('v2_gift_card_usage', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('code_id')->index();
            $table->integer('template_id')->index();
            $table->integer('user_id')->index();
            $table->integer('invite_user_id')->nullable();
            $table->json('rewards_given');
            $table->json('invite_rewards')->nullable();
            $table->integer('user_level_at_use')->nullable();
            $table->integer('plan_id_at_use')->nullable();
            $table->decimal('multiplier_applied', 3, 2)->default(1.00);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->string('scope_type', 16)->default(GiftCardTemplate::SCOPE_GLOBAL)->index();
            $table->integer('site_id')->nullable()->index();
            $table->integer('agent_user_id')->nullable()->index();
            $table->integer('agent_domain_id')->nullable()->index();
            $table->integer('created_at');
        });
    }

    private function createGiftCardCode(array $overrides = []): GiftCardCode
    {
        $scope = [
            'scope_type' => $overrides['scope_type'] ?? GiftCardTemplate::SCOPE_GLOBAL,
            'site_id' => $overrides['site_id'] ?? null,
            'agent_user_id' => $overrides['agent_user_id'] ?? null,
            'agent_domain_id' => $overrides['agent_domain_id'] ?? null,
        ];
        $rewards = $overrides['rewards'] ?? ['balance' => 100];

        $template = GiftCardTemplate::query()->create(array_merge([
            'name' => 'Scoped card',
            'description' => null,
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => 1,
            'conditions' => null,
            'rewards' => $rewards,
            'limits' => null,
            'special_config' => null,
            'icon' => null,
            'background_image' => null,
            'theme_color' => '#1890ff',
            'sort' => 0,
            'admin_id' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $scope));

        return GiftCardCode::query()->create(array_merge([
            'template_id' => $template->id,
            'code' => 'GC' . strtoupper(substr(md5((string) microtime(true)), 0, 12)),
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0,
            'max_usage' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $scope));
    }

    private function createUser(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => 'hashed',
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'expired_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function createActiveAgent(string $email, int $balance): User
    {
        $agent = $this->createUser($email, ['balance' => $balance]);
        $this->database->table('v2_agent_profile')->insert([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createOwnedSubordinate(User $agent, string $email, array $overrides = []): User
    {
        $user = $this->createUser($email, $overrides);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $user->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $user;
    }
}

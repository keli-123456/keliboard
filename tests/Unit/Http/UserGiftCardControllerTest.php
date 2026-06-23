<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\GiftCardController;
use App\Http\Requests\User\GiftCardCheckRequest;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\User;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserGiftCardControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createPlanTable();
        $this->createGiftCardTables();
    }

    public function test_check_does_not_expose_reward_preview_when_scope_rejects_user(): void
    {
        $user = $this->createUser('buyer@example.test', ['site_id' => 2]);
        $code = $this->createGiftCardCode([
            'scope_type' => GiftCardTemplate::SCOPE_SITE,
            'site_id' => 1,
            'rewards' => [
                'balance' => 777,
                'expire_days' => 30,
            ],
        ]);

        $response = app(GiftCardController::class)->check($this->checkRequest($user, $code->code));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $payload['status']);
        $this->assertFalse($payload['data']['can_redeem']);
        $this->assertSame('site_scope_mismatch', $payload['data']['reason_code']);
        $this->assertNull($payload['data']['reward_preview']);
        $this->assertNull($payload['data']['plan_operation']);
    }

    public function test_check_keeps_reward_preview_for_eligible_user(): void
    {
        $user = $this->createUser('buyer@example.test', ['site_id' => 1]);
        $code = $this->createGiftCardCode([
            'scope_type' => GiftCardTemplate::SCOPE_SITE,
            'site_id' => 1,
            'rewards' => [
                'balance' => 777,
            ],
        ]);

        $response = app(GiftCardController::class)->check($this->checkRequest($user, $code->code));
        $payload = $response->getData(true);

        $this->assertTrue($payload['data']['can_redeem']);
        $this->assertSame(777, $payload['data']['reward_preview']['raw']['balance']);
    }

    private function checkRequest(User $user, string $code): GiftCardCheckRequest
    {
        $request = GiftCardCheckRequest::create('/api/v1/user/gift-card/check', 'POST', [
            'code' => $code,
        ]);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    private function createGiftCardCode(array $overrides = []): GiftCardCode
    {
        $scope = [
            'scope_type' => $overrides['scope_type'] ?? GiftCardTemplate::SCOPE_GLOBAL,
            'site_id' => $overrides['site_id'] ?? null,
            'agent_user_id' => $overrides['agent_user_id'] ?? null,
            'agent_domain_id' => $overrides['agent_domain_id'] ?? null,
        ];

        $template = GiftCardTemplate::query()->create(array_merge([
            'name' => 'Scoped card',
            'description' => null,
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => 1,
            'conditions' => null,
            'rewards' => $overrides['rewards'] ?? ['balance' => 100],
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
}

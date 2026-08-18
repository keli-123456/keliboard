<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use App\Services\TenantPlanCatalogService;
use App\Services\TicketAiBusinessContextService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Tests\TestCase;

final class TicketAiBusinessContextServiceTest extends TestCase
{
    public function test_builds_verified_traffic_expiry_and_order_reply(): void
    {
        $service = $this->service([]);
        $context = $service->build(
            new User(),
            ['type' => 'platform', 'domain' => 'main.example.com'],
            [
                'transfer_enable_bytes' => 100 * 1024 ** 3,
                'used_bytes' => 25 * 1024 ** 3,
                'remaining_bytes' => 75 * 1024 ** 3,
                'expired_at' => 1788163200,
            ],
            [[
                'plan_name' => '标准套餐',
                'status' => Order::STATUS_COMPLETED,
                'total_amount' => 1300,
                'created_at' => 1788076800,
            ]],
            [[
                'role' => 'user',
                'content' => '我的剩余流量和套餐到期时间是多少？顺便查询订单状态',
            ]]
        );

        $this->assertSame(['traffic', 'expiry', 'order_status'], $context['intents']);
        $this->assertFalse($context['requires_human']);
        $this->assertStringContainsString('剩余 75.00 GB', $context['grounded_reply']);
        $this->assertStringContainsString('标准套餐，状态：已完成，金额：¥13.00', $context['grounded_reply']);
    }

    public function test_catalog_uses_tenant_decorated_names_prices_and_periods(): void
    {
        $plan = new Plan([
            'name' => '平台套餐',
            'transfer_enable' => 80,
            'prices' => [
                Plan::PERIOD_MONTHLY => 13,
                Plan::PERIOD_YEARLY => 100,
                Plan::PERIOD_RESET_TRAFFIC => 3,
            ],
        ]);
        $plan->setAttribute('agent_display_name', '代理专属套餐');
        $plan->setAttribute('agent_context', [
            'agent_user_id' => 9,
            'domain' => 'agent.example.com',
        ]);
        $service = $this->service([$plan]);
        $context = $service->build(
            new User(),
            ['type' => 'agent', 'agent_user_id' => 9, 'domain' => 'agent.example.com'],
            [],
            [],
            [['role' => 'user', 'content' => '你们有什么套餐，价格是多少？']]
        );

        $this->assertSame(['plan_catalog'], $context['intents']);
        $this->assertSame('代理专属套餐', $context['catalog'][0]['name']);
        $this->assertSame(1300, $context['catalog'][0]['periods'][0]['amount_cents']);
        $this->assertCount(2, $context['catalog'][0]['periods']);
        $this->assertStringContainsString('月付 ¥13.00', $context['grounded_reply']);
        $this->assertStringNotContainsString('重置流量', $context['grounded_reply']);
    }

    public function test_sensitive_intent_is_forced_to_human_review(): void
    {
        $service = $this->service([]);
        $business = $service->build(
            new User(),
            ['type' => 'platform'],
            [],
            [],
            [['role' => 'user', 'content' => '支付失败了，请帮我退款并修改邮箱']]
        );
        $result = $service->applyGuardrails([
            'risk' => 'low',
            'needs_human' => false,
            'draft' => '已经处理完成',
            'confidence' => 0.99,
            'category' => '其他',
        ], $business);

        $this->assertTrue($business['requires_human']);
        $this->assertSame('', $business['grounded_reply']);
        $this->assertTrue($result['needs_human']);
        $this->assertSame('medium', $result['risk']);
        $this->assertFalse($result['system_grounded']);
    }

    public function test_backend_verified_reply_replaces_model_invented_values(): void
    {
        $service = $this->service([]);
        $business = $service->build(
            new User(),
            ['type' => 'platform'],
            [
                'transfer_enable_bytes' => 10 * 1024 ** 3,
                'used_bytes' => 2 * 1024 ** 3,
                'remaining_bytes' => 8 * 1024 ** 3,
            ],
            [],
            [['role' => 'user', 'content' => '还有多少流量']]
        );
        $result = $service->applyGuardrails([
            'risk' => 'low',
            'needs_human' => false,
            'draft' => '您还有 999 GB',
            'confidence' => 0.95,
            'category' => '其他',
        ], $business);

        $this->assertTrue($result['system_grounded']);
        $this->assertSame('订阅与节点', $result['category']);
        $this->assertStringContainsString('剩余 8.00 GB', $result['draft']);
        $this->assertStringNotContainsString('999 GB', $result['draft']);
    }

    public function test_churn_signal_supplies_real_catalog_without_becoming_an_automatic_fact_reply(): void
    {
        $plan = new Plan([
            'name' => '轻量套餐',
            'transfer_enable' => 50,
            'prices' => [Plan::PERIOD_MONTHLY => 9],
        ]);
        $plan->setAttribute('site_context', [
            'site_id' => 3,
            'domain' => 'site.example.com',
        ]);
        $context = $this->service([$plan])->build(
            new User(),
            ['type' => 'site', 'site_id' => 3, 'domain' => 'site.example.com'],
            [],
            [],
            [['role' => 'user', 'content' => '现在的套餐太贵了，有没有便宜一点的？']]
        );

        $this->assertTrue($context['retention_signal']);
        $this->assertSame('price', $context['retention_reason']);
        $this->assertSame('轻量套餐', $context['catalog'][0]['name']);
        $this->assertSame('', $context['grounded_reply']);
    }

    public function test_tenant_catalog_rejects_an_unscoped_platform_fallback(): void
    {
        $plan = new Plan([
            'name' => '主站套餐',
            'transfer_enable' => 100,
            'prices' => [Plan::PERIOD_MONTHLY => 20],
        ]);
        $context = $this->service([$plan])->build(
            new User(),
            ['type' => 'site', 'site_id' => 99, 'domain' => 'missing.example.com'],
            [],
            [],
            [['role' => 'user', 'content' => '有什么套餐']]
        );

        $this->assertSame([], $context['catalog']);
        $this->assertStringContainsString('当前站点暂未查询到可售套餐', $context['grounded_reply']);
        $this->assertStringNotContainsString('主站套餐', $context['grounded_reply']);
    }

    /** @param array<int, Plan> $plans */
    private function service(array $plans): TicketAiBusinessContextService
    {
        $catalog = $this->createMock(TenantPlanCatalogService::class);
        $catalog->method('plansForRequest')
            ->willReturnCallback(static fn (Request $request, iterable $platformPlans, ?User $user = null): array => $plans);
        $planService = $this->createMock(PlanService::class);
        $planService->method('getAvailablePlans')->willReturn(new Collection($plans));

        return new TicketAiBusinessContextService(null, $catalog, $planService);
    }
}

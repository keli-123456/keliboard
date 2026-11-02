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

    public function test_subject_only_and_english_sensitive_intents_require_human_review(): void
    {
        $service = $this->service([]);
        $context = $service->build(new User(), ['type' => 'platform'], [], [],
            [['role' => 'user', 'content' => '请尽快处理']], [], '申请退款');
        $this->assertTrue($context['requires_human']);
        foreach (['Refund my payment', 'Please UNBAN my account', 'change email', '验证码是多少'] as $message) {
            $this->assertTrue($service->requiresHumanReview($message));
        }
        $this->assertFalse($service->requiresHumanReview('How to import a subscription?'));
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

    public function test_operational_failure_replaces_vague_model_reply_and_forces_human_review(): void
    {
        $service = $this->service([]);
        $business = $service->build(
            new User(),
            ['type' => 'platform'],
            [],
            [],
            [['role' => 'user', 'content' => '订阅链接一直 Network Error']],
            [
                'requires_human' => true,
                'customer_safe_summary' => '订阅加速通道当前未通过健康检查，需要人工排查。',
            ]
        );
        $result = $service->applyGuardrails([
            'risk' => 'low',
            'needs_human' => false,
            'draft' => '请检查网络后稍后重试。',
            'confidence' => 0.99,
            'category' => '客户端连接',
        ], $business);

        $this->assertTrue($business['operational_requires_human']);
        $this->assertTrue($result['needs_human']);
        $this->assertSame('服务器故障', $result['category']);
        $this->assertSame('medium', $result['risk']);
        $this->assertStringContainsString('订阅加速通道当前未通过健康检查', $result['draft']);
        $this->assertStringNotContainsString('检查网络', $result['draft']);
        $this->assertFalse($result['system_grounded']);
    }
    public function test_follow_up_retains_verified_traffic_when_a_closure_dispute_requires_human_review(): void
    {
        $service = $this->service([]);
        $business = $service->build(new User(), ['type' => 'platform'], [
            'transfer_enable_bytes' => 100 * 1024 ** 3, 'used_bytes' => 60 * 1024 ** 3, 'remaining_bytes' => 40 * 1024 ** 3,
        ], [], [
            ['role' => 'user', 'content' => '流量一下就没了，我想知道去哪了'],
            ['role' => 'assistant', 'content' => '请联系人工'],
            ['role' => 'user', 'content' => '前两个工单我也没有关啊'],
        ], [], '流量一下就没了', [
            'intents' => ['traffic_history', 'ticket_history'], 'status' => 'available', 'requires_human' => true,
            'facts' => [
                ['label' => '较高用量日 2026-11-01', 'value' => '记录的计费用量 30.00 GB'],
                ['label' => '历史工单 #7', 'value' => '已关闭，关闭操作人未提供'],
            ],
        ]);
        $result = $service->applyGuardrails(['draft' => '无法查看流量记录，两个工单均由您关闭。', 'risk' => 'low', 'needs_human' => false], $business);

        $this->assertTrue($result['needs_human']);
        $this->assertFalse($result['system_grounded']);
        foreach (['剩余 40.00 GB', '30.00 GB', '历史工单 #7', '关闭操作人未提供', '需要人工核查'] as $fact) {
            $this->assertStringContainsString($fact, $result['draft']);
        }
        $this->assertStringNotContainsString('均由您关闭', $result['draft']);
        $this->assertStringNotContainsString('无法查看流量记录', $result['draft']);
    }

    public function test_sensitive_follow_up_cannot_use_read_only_evidence_to_authorize_compensation(): void
    {
        $service = $this->service([]);
        $business = $service->build(new User(), ['type' => 'platform'], [], [], [
            ['role' => 'user', 'content' => '扣流量不对，请退款或补偿'],
            ['role' => 'user', 'content' => '查到了吗'],
        ], [], '', [
            'intents' => ['traffic_history'], 'status' => 'available', 'requires_human' => false,
            'facts' => [['label' => '节点 #27', 'value' => '计费用量 30.00 GB']],
        ]);
        $result = $service->applyGuardrails(['draft' => '已赠送100GB并退款', 'risk' => 'low'], $business);
        $this->assertTrue($result['needs_human']);
        $this->assertStringContainsString('30.00 GB', $result['draft']);
        $this->assertStringContainsString('未修改账户或补偿流量', $result['draft']);
        $this->assertStringNotContainsString('已赠送', $result['draft']);
        $this->assertStringNotContainsString('并退款', $result['draft']);
    }

    public function test_traffic_evidence_includes_existing_plan_reset_and_typed_order_facts_without_claiming_execution(): void
    {
        $service = $this->service([]);
        $business = $service->build(new User(), ['type' => 'platform'], [
            'plan_name' => '标准套餐', 'transfer_enable_bytes' => 100 * 1024 ** 3,
            'last_reset_at' => time() - 86400, 'next_reset_at' => time() + 86400,
        ], [[
            'plan_name' => '标准套餐', 'type' => Order::TYPE_RESET_TRAFFIC, 'status' => Order::STATUS_CANCELLED,
            'created_at' => time(),
        ]], [['role' => 'user', 'content' => '流量去哪了']], [], '', [
            'intents' => ['traffic_history'], 'status' => 'partial', 'requires_human' => false,
            'facts' => [['label' => '节点用量', 'value' => '节点明细暂不可读取。']],
        ]);
        $result = $service->applyGuardrails(['draft' => '已经执行了流量重置，剩余999GB', 'confidence' => 0.9], $business);
        $this->assertFalse($result['needs_human']);
        $this->assertSame('订阅与节点', $result['category']);
        foreach (['标准套餐', '类型：流量重置', '已取消', '计划时间，不代表已执行', '节点明细暂不可读取', '不是完整余额流水'] as $fact) {
            $this->assertStringContainsString($fact, $result['draft']);
        }
        $this->assertStringNotContainsString('已经执行了流量重置', $result['draft']);
        $this->assertStringNotContainsString('999GB', $result['draft']);
    }

    public function test_scope_mismatch_does_not_turn_a_foreign_subscription_into_verified_facts(): void
    {
        $business = $this->service([])->build(new User(), [], ['transfer_enable_bytes' => 999 * 1024 ** 3], [],
            [['role' => 'user', 'content' => '剩余流量']], [], '', [
                'intents' => ['traffic_history'], 'status' => 'scope_mismatch', 'requires_human' => true,
                'facts' => [['label' => '查询范围', 'value' => '账户归属无法核验，未读取历史数据。']],
            ]);
        $this->assertTrue($business['requires_human']);
        $this->assertStringNotContainsString('999', json_encode($business));
    }

    public function test_missing_account_does_not_become_a_verified_zero_allowance_or_non_expiring_plan(): void
    {
        $business = $this->service([])->build(null, [], [], [],
            [['role' => 'user', 'content' => '剩余流量和套餐到期时间是多少']]);
        $this->assertTrue($business['requires_human']);
        $this->assertSame([], $business['verified_facts']);
        $this->assertSame('', $business['grounded_reply']);
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

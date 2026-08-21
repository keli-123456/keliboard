<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;

class TicketAiBusinessContextService
{
    private const SENSITIVE_PATTERNS = [
        '退款', '退钱', '退费', '拒付', '争议', '盗刷', '重复扣款', '重复支付',
        '支付失败', '付款失败', '未到账', '没到账', '补偿', '赔偿',
        '密码', '验证码', '修改邮箱', '更换邮箱', '注销账号', '删除账号',
        '封禁', '解封', '封号', '解除风控', '重置订阅', '重置凭证',
        'token', 'uuid', '隐私', '起诉', '律师', '报警',
    ];

    public function __construct(
        private ?TicketAiContentSanitizer $sanitizer = null,
        private ?TenantPlanCatalogService $catalogService = null,
        private ?PlanService $planService = null
    ) {
        $this->sanitizer ??= new TicketAiContentSanitizer();
        $this->catalogService ??= app(TenantPlanCatalogService::class);
        $this->planService ??= new PlanService(new Plan());
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $subscription
     * @param array<int, array<string, mixed>> $orders
     * @param array<int, array<string, mixed>> $conversation
     * @param array<string, mixed> $operations
     * @return array<string, mixed>
     */
    public function build(
        ?User $user,
        array $scope,
        array $subscription,
        array $orders,
        array $conversation,
        array $operations = []
    ): array {
        $latestMessage = $this->latestUserMessage($conversation);
        $operationalRequiresHuman = (bool) ($operations['requires_human'] ?? false);
        $requiresHuman = $this->containsSensitiveIntent($latestMessage) || $operationalRequiresHuman;
        $intents = $this->detectReadOnlyIntents($latestMessage);
        $retentionSignal = $this->retentionSignal($latestMessage);
        $catalog = in_array('plan_catalog', $intents, true) || $retentionSignal !== null
            ? $this->catalog($user, $scope)
            : [];
        $verifiedFacts = $this->verifiedFacts($intents, $subscription, $orders, $catalog);

        return [
            'mode' => 'backend_verified_read_only',
            'intents' => $intents,
            'requires_human' => $requiresHuman,
            'verified_facts' => $verifiedFacts,
            'retention_signal' => $retentionSignal !== null,
            'retention_reason' => $retentionSignal,
            'catalog' => $catalog,
            'operational_requires_human' => $operationalRequiresHuman,
            'operational_reply' => $operationalRequiresHuman
                ? trim((string) ($operations['customer_safe_summary'] ?? ''))
                : '',
            'grounded_reply' => !$requiresHuman && $verifiedFacts !== []
                ? $this->groundedReply($verifiedFacts)
                : '',
            'grounding_type' => $verifiedFacts === [] ? null : implode('+', $intents),
        ];
    }

    /**
     * Make sensitive operations impossible to auto-send and make exact read-only
     * answers independent from model wording or invented values.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $business
     * @return array<string, mixed>
     */
    public function applyGuardrails(array $result, array $business): array
    {
        if ((bool) ($business['requires_human'] ?? false)) {
            $operationalReply = trim((string) ($business['operational_reply'] ?? ''));
            if ((bool) ($business['operational_requires_human'] ?? false) && $operationalReply !== '') {
                $result['draft'] = $this->sanitizer->sanitize(
                    $operationalReply . "\n客服将结合实时运行记录继续处理，请勿反复重置订阅或重复提交订单。",
                    5000
                );
                $result['category'] = '服务器故障';
            }
            $result['needs_human'] = true;
            if (($result['risk'] ?? 'low') === 'low') {
                $result['risk'] = 'medium';
            }
            $result['system_grounded'] = false;
            $result['grounding_type'] = null;

            return $result;
        }

        $groundedReply = trim((string) ($business['grounded_reply'] ?? ''));
        if ($groundedReply === '') {
            $result['system_grounded'] = false;
            $result['grounding_type'] = null;

            return $result;
        }

        $intents = array_values((array) ($business['intents'] ?? []));
        $result['draft'] = $this->sanitizer->sanitize($groundedReply, 5000);
        $result['needs_human'] = false;
        $result['risk'] = 'low';
        $result['confidence'] = max(0.99, (float) ($result['confidence'] ?? 0));
        $result['category'] = in_array('plan_catalog', $intents, true)
            || in_array('order_status', $intents, true)
            ? '套餐订单'
            : '订阅与节点';
        $result['system_grounded'] = true;
        $result['grounding_type'] = $business['grounding_type'] ?? null;

        return $result;
    }

    /** @param array<int, array<string, mixed>> $conversation */
    private function latestUserMessage(array $conversation): string
    {
        for ($index = count($conversation) - 1; $index >= 0; $index--) {
            $message = $conversation[$index] ?? null;
            if (!is_array($message) || ($message['role'] ?? null) !== 'user') {
                continue;
            }

            return mb_strtolower(trim((string) ($message['content'] ?? '')));
        }

        return '';
    }

    private function containsSensitiveIntent(string $message): bool
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function detectReadOnlyIntents(string $message): array
    {
        $intents = [];
        if ($this->matches($message, [
            '剩余流量', '流量剩余', '流量还剩', '还有多少流量', '已用流量', '用了多少流量',
        ])) {
            $intents[] = 'traffic';
        }
        if ($this->matches($message, [
            '什么时候到期', '多久到期', '到期时间', '套餐到期', '订阅到期', '有效期', '过期时间',
        ])) {
            $intents[] = 'expiry';
        }
        if ($this->matches($message, [
            '订单状态', '支付状态', '订单进度', '订单完成了吗', '订单成功了吗', '查询订单',
        ])) {
            $intents[] = 'order_status';
        }
        if ($this->matches($message, [
            '推荐套餐', '套餐推荐', '有什么套餐', '有哪些套餐', '套餐价格', '哪个套餐',
            '怎么买套餐', '购买套餐', '可售套餐',
        ])) {
            $intents[] = 'plan_catalog';
        }

        return array_values(array_unique($intents));
    }

    private function retentionSignal(string $message): ?string
    {
        $groups = [
            'price' => ['太贵', '价格高', '便宜一点', '有没有便宜', '负担不起'],
            'performance' => ['速度太慢', '太卡', '不稳定', '经常断', '不好用'],
            'leaving' => ['不想用了', '不用了', '不续费', '取消续费', '准备换', '换一家'],
        ];
        foreach ($groups as $reason => $needles) {
            if ($this->matches($message, $needles)) {
                return $reason;
            }
        }

        return null;
    }

    /** @param array<int, string> $needles */
    private function matches(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $intents
     * @param array<string, mixed> $subscription
     * @param array<int, array<string, mixed>> $orders
     * @param array<int, array<string, mixed>> $catalog
     * @return array<int, array{label:string,value:string}>
     */
    private function verifiedFacts(array $intents, array $subscription, array $orders, array $catalog): array
    {
        $facts = [];
        if (in_array('traffic', $intents, true)) {
            $total = max(0, (int) ($subscription['transfer_enable_bytes'] ?? 0));
            $used = max(0, (int) ($subscription['used_bytes'] ?? 0));
            $remaining = max(0, (int) ($subscription['remaining_bytes'] ?? 0));
            $facts[] = [
                'label' => '流量',
                'value' => $total > 0
                    ? sprintf('总量 %s，已用 %s，剩余 %s', $this->formatBytes($total), $this->formatBytes($used), $this->formatBytes($remaining))
                    : '当前账号未查询到有效流量额度',
            ];
        }
        if (in_array('expiry', $intents, true)) {
            $expiredAt = (int) ($subscription['expired_at'] ?? 0);
            $facts[] = [
                'label' => '到期时间',
                'value' => $expiredAt > 0 ? date('Y-m-d H:i:s', $expiredAt) : '当前套餐未设置固定到期时间',
            ];
        }
        if (in_array('order_status', $intents, true)) {
            $order = $orders[0] ?? null;
            if (is_array($order)) {
                $status = Order::$statusMap[(int) ($order['status'] ?? -1)] ?? '未知状态';
                $planName = trim((string) ($order['plan_name'] ?? '')) ?: '订单';
                $facts[] = [
                    'label' => '最近订单',
                    'value' => sprintf(
                        '%s，状态：%s，金额：¥%s，创建于：%s',
                        $planName,
                        $status,
                        number_format(max(0, (int) ($order['total_amount'] ?? 0)) / 100, 2, '.', ''),
                        $this->formatTimestamp($order['created_at'] ?? null)
                    ),
                ];
            } else {
                $facts[] = ['label' => '最近订单', 'value' => '当前站点范围内未查询到近期订单'];
            }
        }
        if (in_array('plan_catalog', $intents, true)) {
            $facts[] = [
                'label' => '可售套餐',
                'value' => $catalog === []
                    ? '当前站点暂未查询到可售套餐，请由人工客服核查'
                    : implode('；', array_map(fn (array $plan): string => $this->catalogLine($plan), array_slice($catalog, 0, 6))),
            ];
        }

        return $facts;
    }

    /** @param array<int, array{label:string,value:string}> $facts */
    private function groundedReply(array $facts): string
    {
        $lines = ['已为您查询当前账号信息：'];
        foreach ($facts as $fact) {
            $lines[] = $fact['label'] . '：' . $fact['value'];
        }
        $lines[] = '以上结果以当前页面实际可用状态为准。';

        return implode("\n", $lines);
    }

    /** @return array<int, array<string, mixed>> */
    private function catalog(?User $user, array $scope): array
    {
        if (!$user) {
            return [];
        }

        try {
            $domain = $this->validDomain((string) ($scope['domain'] ?? ''));
            $request = Request::create($domain !== '' ? 'https://' . $domain . '/' : 'https://localhost/', 'GET');
            $catalogUser = $this->catalogUser($user, $scope, $domain);
            $request->setUserResolver(static fn (): User => $catalogUser);
            $plans = $this->catalogService->plansForRequest(
                $request,
                $this->planService->getAvailablePlans(),
                $catalogUser
            );
            $plans = array_values(array_filter(
                array_values($plans),
                fn (mixed $plan): bool => $plan instanceof Plan && $this->planMatchesScope($plan, $scope)
            ));

            return array_values(array_filter(array_map(
                fn (mixed $plan): ?array => $plan instanceof Plan ? $this->planPayload($plan) : null,
                array_slice($plans, 0, 12)
            )));
        } catch (\Throwable) {
            return [];
        }
    }

    private function catalogUser(User $user, array $scope, string $domain): User
    {
        if (($scope['type'] ?? 'platform') === 'agent' && $domain === '') {
            return $user;
        }

        $catalogUser = new User();
        if (($scope['type'] ?? 'platform') === 'site') {
            $catalogUser->site_id = (int) ($scope['site_id'] ?? 0) ?: null;
        }

        return $catalogUser;
    }

    /** @param array<string, mixed> $scope */
    private function planMatchesScope(Plan $plan, array $scope): bool
    {
        $type = (string) ($scope['type'] ?? 'platform');
        if ($type === 'site') {
            $siteContext = (array) $plan->getAttribute('site_context');

            return (int) ($scope['site_id'] ?? 0) > 0
                && (int) ($siteContext['site_id'] ?? 0) === (int) $scope['site_id'];
        }
        if ($type === 'agent') {
            $agentContext = (array) $plan->getAttribute('agent_context');

            return (int) ($scope['agent_user_id'] ?? 0) > 0
                && (int) ($agentContext['agent_user_id'] ?? 0) === (int) $scope['agent_user_id'];
        }

        return $plan->getAttribute('site_context') === null
            && $plan->getAttribute('agent_context') === null;
    }

    /** @return array<string, mixed>|null */
    private function planPayload(Plan $plan): ?array
    {
        $periodLabels = Plan::getAvailablePeriods();
        $periods = [];
        foreach ((array) $plan->prices as $period => $price) {
            if ($period === Plan::PERIOD_RESET_TRAFFIC || !is_numeric($price) || (float) $price <= 0) {
                continue;
            }
            $periods[] = [
                'period' => (string) $period,
                'label' => (string) ($periodLabels[$period]['name'] ?? $period),
                'amount_cents' => OrderService::amountToCents($price),
                'price' => '¥' . number_format((float) $price, 2, '.', ''),
            ];
        }
        if ($periods === []) {
            return null;
        }

        $name = (string) (
            $plan->getAttribute('agent_display_name')
            ?: $plan->getAttribute('site_display_name')
            ?: $plan->getAttribute('display_name')
            ?: $plan->name
        );

        return [
            'name' => $this->sanitizer->sanitize($name, 120),
            'traffic_gb' => max(0, (int) $plan->transfer_enable),
            'speed_limit_mbps' => $plan->speed_limit !== null ? (int) $plan->speed_limit : null,
            'device_limit' => $plan->device_limit !== null ? (int) $plan->device_limit : null,
            'description' => $this->sanitizer->sanitize(strip_tags((string) $plan->content), 300),
            'periods' => $periods,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function catalogLine(array $plan): string
    {
        $periods = array_slice((array) ($plan['periods'] ?? []), 0, 4);
        $prices = implode('、', array_map(
            static fn (array $period): string => (string) ($period['label'] ?? '') . ' ' . (string) ($period['price'] ?? ''),
            $periods
        ));
        $traffic = (int) ($plan['traffic_gb'] ?? 0);

        return (string) ($plan['name'] ?? '套餐')
            . ($traffic > 0 ? '（' . $traffic . ' GB）' : '')
            . '：' . $prices;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = max(0, $bytes);
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $index === 0 ? 0 : 2, '.', '') . ' ' . $units[$index];
    }

    private function formatTimestamp(mixed $value): string
    {
        return is_numeric($value) && (int) $value > 0
            ? date('Y-m-d H:i:s', (int) $value)
            : '未知时间';
    }

    private function validDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1
            ? $domain
            : '';
    }
}

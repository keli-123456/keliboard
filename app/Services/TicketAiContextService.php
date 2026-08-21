<?php

namespace App\Services;

use App\Models\AgentSiteSetting;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Plugin\SubscriptionControl\Services\SubscriptionControlEventStore;

class TicketAiContextService
{
    private TicketAiContentSanitizer $sanitizer;
    private TicketAiBusinessContextService $businessContext;
    private TicketAiOperationalContextService $operationalContext;

    public function __construct(
        ?TicketAiContentSanitizer $sanitizer = null,
        ?TicketAiBusinessContextService $businessContext = null,
        ?TicketAiOperationalContextService $operationalContext = null
    ) {
        $this->sanitizer = $sanitizer ?? new TicketAiContentSanitizer();
        $this->businessContext = $businessContext ?? new TicketAiBusinessContextService($this->sanitizer);
        $this->operationalContext = $operationalContext ?? new TicketAiOperationalContextService();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Ticket $ticket, int $maxMessages, ?string $instruction): array
    {
        $this->loadContextRelations($ticket);
        $user = $ticket->user instanceof User ? $ticket->user : null;
        $scope = $this->scope($ticket);
        $orders = $this->recentOrders($user, $scope);
        unset($scope['_orders_allowed']);
        $subscription = $this->subscriptionSummary($user);
        $conversation = $this->conversation($ticket, $maxMessages);
        $operations = $this->operationalContext->build(
            $user,
            $scope,
            $conversation,
            (string) $ticket->subject
        );

        return [
            'scope' => $scope,
            'ticket' => [
                'subject' => $this->sanitizer->sanitize((string) $ticket->subject, 300),
                'level' => (int) $ticket->level,
                'status' => (int) $ticket->status,
                'created_at' => $this->timestamp($ticket->created_at),
            ],
            'user' => $this->userSummary($user),
            'subscription' => $subscription,
            'orders' => $orders,
            'risk' => $this->riskSummary($user),
            'conversation' => $conversation,
            'operations' => $operations,
            'business' => $this->businessContext->build(
                $user,
                $scope,
                $subscription,
                $orders,
                $conversation,
                $operations
            ),
            'instruction' => $this->sanitizer->sanitize((string) ($instruction ?? ''), 1000),
        ];
    }

    private function loadContextRelations(Ticket $ticket): void
    {
        try {
            $ticket->loadMissing([
                'messages' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'user.plan',
                'site.setting',
                'site.domains',
                'agentDomain.siteSetting',
            ]);
        } catch (\Throwable) {
            try {
                $ticket->loadMissing(['messages', 'user']);
            } catch (\Throwable) {
                // Context generation must degrade to the fields already loaded on the ticket.
            }
        }
    }

    /** @return array<string, mixed> */
    private function scope(Ticket $ticket): array
    {
        $siteId = $this->positiveInt($ticket->site_id);
        $agentUserId = $this->positiveInt($ticket->agent_user_id);
        $agentDomainId = $this->positiveInt($ticket->agent_domain_id);

        if ($agentUserId !== null) {
            $agentDomain = $ticket->agentDomain;
            $domainBelongsToAgent = $agentDomain
                && $this->positiveInt($agentDomain->agent_user_id) === $agentUserId;
            $domain = $domainBelongsToAgent ? trim((string) $agentDomain->domain) : '';
            $setting = $this->agentSetting($agentUserId, $domainBelongsToAgent ? $agentDomainId : null);
            $brand = trim((string) ($setting?->site_name ?? ''));
            if ($brand === '') {
                $brand = $domain !== '' ? $domain : '代理站点';
            }

            return [
                'type' => 'agent',
                'site_id' => $siteId,
                'agent_user_id' => $agentUserId,
                'agent_domain_id' => $agentDomainId,
                'brand_name' => $this->sanitizer->sanitize($brand, 120),
                'domain' => $this->sanitizer->sanitize($domain, 255),
            ];
        }

        if ($siteId !== null) {
            if (!$ticket->site) {
                return [
                    'type' => 'site',
                    'site_id' => $siteId,
                    'agent_user_id' => null,
                    'agent_domain_id' => null,
                    'brand_name' => '',
                    'domain' => '',
                    '_orders_allowed' => false,
                ];
            }
            $setting = $ticket->site->setting;
            $setting = $setting instanceof SiteSetting && $setting->enabled ? $setting : null;
            $brand = trim((string) ($setting?->site_name ?: $ticket->site->name));
            $domainModel = $ticket->site->domains
                ->where('status', 'active')
                ->sort(function ($left, $right): int {
                    $primaryOrder = (int) $right->is_primary <=> (int) $left->is_primary;
                    if ($primaryOrder !== 0) {
                        return $primaryOrder;
                    }

                    return (int) $left->id <=> (int) $right->id;
                })
                ->first();

            return [
                'type' => 'site',
                'site_id' => $siteId,
                'agent_user_id' => null,
                'agent_domain_id' => null,
                'brand_name' => $this->sanitizer->sanitize($brand, 120),
                'domain' => $this->sanitizer->sanitize((string) ($domainModel?->domain ?? ''), 255),
            ];
        }

        return [
            'type' => 'platform',
            'site_id' => null,
            'agent_user_id' => null,
            'agent_domain_id' => null,
            'brand_name' => $this->sanitizer->sanitize((string) admin_setting('app_name', 'XBoard'), 120),
            'domain' => $this->host((string) admin_setting('app_url', '')),
        ];
    }

    private function agentSetting(int $agentUserId, ?int $agentDomainId): ?AgentSiteSetting
    {
        if (!$this->hasTable('v2_agent_site_setting')) {
            return null;
        }

        try {
            if ($agentDomainId !== null) {
                $domainSetting = AgentSiteSetting::query()
                    ->where('agent_user_id', $agentUserId)
                    ->where('agent_domain_id', $agentDomainId)
                    ->where('enabled', true)
                    ->first();
                if ($domainSetting) {
                    return $domainSetting;
                }
            }

            return AgentSiteSetting::query()
                ->where('agent_user_id', $agentUserId)
                ->whereNull('agent_domain_id')
                ->where('enabled', true)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function userSummary(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return [
            'user_ref' => 'user-' . (int) $user->id,
            'banned' => (bool) $user->banned,
            'registered_at' => $this->timestamp($user->created_at),
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionSummary(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $total = max(0, (int) $user->transfer_enable);
        $used = max(0, (int) $user->u + (int) $user->d);

        return [
            'plan_name' => $this->sanitizer->sanitize((string) ($user->plan?->name ?? ''), 120),
            'expired_at' => $this->timestamp($user->expired_at),
            'transfer_enable_bytes' => $total,
            'used_bytes' => $used,
            'remaining_bytes' => max(0, $total - $used),
            'speed_limit_mbps' => $user->speed_limit !== null ? (int) $user->speed_limit : null,
            'device_limit' => $user->device_limit !== null ? (int) $user->device_limit : null,
            'auto_renew_enabled' => (bool) $user->auto_renew_enable,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function recentOrders(?User $user, array $scope): array
    {
        if (!$user || !$this->hasTable('v2_order')) {
            return [];
        }

        try {
            $query = Order::query()
                ->with($this->hasTable('v2_plan') ? ['plan:id,name'] : [])
                ->where('user_id', $user->id);
            $this->restrictOrdersToScope($query, $scope);

            return $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(3)
                ->get()
                ->map(fn (Order $order): array => [
                    'plan_name' => $this->sanitizer->sanitize((string) ($order->plan?->name ?? ''), 120),
                    'period' => $this->sanitizer->sanitize((string) $order->period, 40),
                    'type' => (int) $order->type,
                    'status' => (int) $order->status,
                    'total_amount' => (int) $order->total_amount,
                    'created_at' => $this->timestamp($order->created_at),
                    'paid_at' => $this->timestamp($order->paid_at),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $scope */
    private function restrictOrdersToScope(Builder $query, array $scope): void
    {
        if (($scope['_orders_allowed'] ?? true) === false) {
            $query->whereRaw('1 = 0');
            return;
        }
        $type = (string) ($scope['type'] ?? 'platform');
        $hasAgentContext = $this->hasTable('v2_agent_order_context');
        $hasSiteContext = $this->hasTable('v2_site_order_context');
        $hasDirectSite = $this->hasColumn('v2_order', 'site_id');

        if ($type === 'agent') {
            $agentUserId = $this->positiveInt($scope['agent_user_id'] ?? null);
            $agentDomainId = $this->positiveInt($scope['agent_domain_id'] ?? null);
            if (!$hasAgentContext || $agentUserId === null) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->whereHas('agentOrderContext', function (Builder $context) use ($agentUserId, $agentDomainId): void {
                $context->where('agent_user_id', $agentUserId);
                if ($agentDomainId !== null) {
                    $context->where('agent_domain_id', $agentDomainId);
                }
            });
            return;
        }

        if ($type === 'site') {
            $siteId = $this->positiveInt($scope['site_id'] ?? null);
            if ($siteId === null || (!$hasDirectSite && !$hasSiteContext)) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->where(function (Builder $siteQuery) use ($siteId, $hasDirectSite, $hasSiteContext): void {
                if ($hasDirectSite) {
                    $siteQuery->where('site_id', $siteId);
                }
                if ($hasSiteContext) {
                    $method = $hasDirectSite ? 'orWhereHas' : 'whereHas';
                    $siteQuery->{$method}('siteOrderContext', fn (Builder $context) => $context->where('site_id', $siteId));
                }
            });
            if ($hasAgentContext) {
                $query->whereDoesntHave('agentOrderContext');
            }
            return;
        }

        if ($hasDirectSite) {
            $query->whereNull('site_id');
        }
        if ($hasSiteContext) {
            $query->whereDoesntHave('siteOrderContext');
        }
        if ($hasAgentContext) {
            $query->whereDoesntHave('agentOrderContext');
        }
    }

    /** @return array<string, mixed> */
    private function riskSummary(?User $user): array
    {
        $empty = [
            'available' => false,
            'risk_level' => 'none',
            'risk_score' => 0,
            'event_count' => 0,
            'reset_count' => 0,
            'last_trigger_at' => null,
            'event_codes' => [],
            'actions' => [],
            'evidence' => [],
        ];
        if (!$user) {
            return $empty;
        }

        try {
            $store = new SubscriptionControlEventStore();
            if (!$store->available()) {
                return $empty;
            }
            $events = array_values(array_filter(
                $store->recent(100, (string) $user->email),
                fn (array $event): bool => (int) ($event['user_id'] ?? 0) === (int) $user->id
            ));
        } catch (\Throwable) {
            return $empty;
        }

        if ($events === []) {
            return array_merge($empty, ['available' => true]);
        }

        $maxScore = max(array_map(fn (array $event): int => (int) ($event['risk_score'] ?? 0), $events));
        $resets = count(array_filter(
            $events,
            fn (array $event): bool => in_array((string) ($event['action'] ?? ''), ['reset_token', 'reset_token_uuid'], true)
        ));

        return [
            'available' => true,
            'risk_level' => $maxScore >= 60 ? 'high' : ($maxScore >= 20 ? 'medium' : 'low'),
            'risk_score' => $maxScore,
            'event_count' => count($events),
            'reset_count' => $resets,
            'last_trigger_at' => (int) ($events[0]['created_at'] ?? 0) ?: null,
            'event_codes' => $this->uniqueStrings(array_column($events, 'code')),
            'actions' => $this->uniqueStrings(array_column($events, 'action')),
            'evidence' => $this->riskEvidence($events),
        ];
    }

    /**
     * Build support evidence without exposing raw IPs, user agents, credentials,
     * deny-list entries, or exact rule thresholds.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function riskEvidence(array $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            $code = trim((string) ($event['code'] ?? '')) ?: 'unknown';
            if (!isset($groups[$code])) {
                $groups[$code] = [
                    'code' => $this->sanitizer->sanitize($code, 80),
                    'label' => $this->riskEventLabel($code),
                    'description' => $this->riskEventDescription($code),
                    'event_count' => 0,
                    'last_trigger_at' => null,
                    'actions' => [],
                    'action_labels' => [],
                    'ua_categories' => [],
                    'regions' => [],
                    '_online_ip_count' => 0,
                    '_source_user_count' => 0,
                    '_ip_count' => 0,
                    '_hit_count' => 0,
                    '_risk_score' => 0,
                ];
            }

            $group = &$groups[$code];
            $group['event_count']++;
            $createdAt = (int) ($event['created_at'] ?? 0);
            $group['last_trigger_at'] = max((int) ($group['last_trigger_at'] ?? 0), $createdAt) ?: null;

            $action = trim((string) ($event['action'] ?? ''));
            $this->appendUniqueValue($group['actions'], $action, 6);
            $this->appendUniqueValue($group['action_labels'], $this->riskActionLabel($action), 6);
            foreach ($this->eventValues($event, 'ua_category', 'ua_categories') as $value) {
                $this->appendUniqueValue($group['ua_categories'], $value, 6);
            }
            foreach ($this->eventValues($event, 'region', 'regions') as $value) {
                $this->appendUniqueValue($group['regions'], $value, 6);
            }

            foreach ([
                '_online_ip_count' => 'online_ip_count',
                '_source_user_count' => 'source_user_count',
                '_ip_count' => 'ip_count',
                '_hit_count' => 'hit_count',
                '_risk_score' => 'risk_score',
            ] as $target => $source) {
                $group[$target] = max((int) $group[$target], max(0, (int) ($event[$source] ?? 0)));
            }
            unset($group);
        }

        return array_values(array_map(function (array $group): array {
            $facts = [];
            if ($group['_online_ip_count'] > 0) {
                $facts[] = '观察到最多 ' . $group['_online_ip_count'] . ' 个在线地址';
            }
            if ($group['_source_user_count'] > 0) {
                $facts[] = '同一来源关联 ' . $group['_source_user_count'] . ' 个账号';
            }
            if ($group['_ip_count'] > 0) {
                $facts[] = '短时拉取涉及 ' . $group['_ip_count'] . ' 个地址';
            }
            if (count($group['ua_categories']) > 0) {
                $facts[] = '涉及 ' . count($group['ua_categories']) . ' 类客户端';
            }
            if (count($group['regions']) > 0) {
                $facts[] = '涉及 ' . count($group['regions']) . ' 个地区';
            }
            if ($group['_hit_count'] > 0) {
                $facts[] = '规则累计命中 ' . $group['_hit_count'] . ' 次';
            }
            if ($group['_risk_score'] > 0) {
                $facts[] = '最高风险分 ' . $group['_risk_score'];
            }

            return [
                'code' => $group['code'],
                'label' => $group['label'],
                'description' => $group['description'],
                'event_count' => $group['event_count'],
                'last_trigger_at' => $group['last_trigger_at'],
                'actions' => $group['actions'],
                'action_labels' => $group['action_labels'],
                'facts' => array_slice($facts, 0, 6),
            ];
        }, array_slice($groups, 0, 8, true)));
    }

    private function riskEventLabel(string $code): string
    {
        return match ($code) {
            'source_ip_denylist' => '订阅来源命中风险名单',
            'ua_blacklist' => '恶意或扫描客户端特征',
            'ua_block_only' => '禁止的客户端特征',
            'client_ua_not_allowed' => '客户端类型不在允许范围',
            'multi_ua_pull' => '短时使用多类客户端拉取',
            'multi_region_pull' => '短时从多个地区拉取订阅',
            'multi_region_online' => '多个地区同时在线',
            'online_ip_threshold' => '在线地址数量异常',
            'subscription_leak_guard' => '订阅泄露综合风险',
            'same_source_batch_pull', 'source_batch_pull' => '同一来源批量拉取多个账号',
            'behavior_baseline_observation' => '行为偏离历史基线',
            'multi_ip' => '多地址访问异常',
            default => '订阅风控事件',
        };
    }

    private function riskEventDescription(string $code): string
    {
        return match ($code) {
            'source_ip_denylist' => '订阅请求来源被风险网段、云厂商或已维护的来源名单识别。',
            'ua_blacklist' => '订阅请求的客户端特征命中明确的恶意扫描名单。',
            'ua_block_only' => '订阅请求使用了当前策略明确禁止访问的客户端类型。',
            'client_ua_not_allowed' => '订阅请求使用了当前站点未允许的客户端类型。',
            'multi_ua_pull' => '同一订阅在短时间内被多种客户端类别重复拉取。',
            'multi_region_pull' => '同一订阅在短时间内从多个地区重复拉取。',
            'multi_region_online' => '同一账号在观察窗口内从多个地区同时在线。',
            'online_ip_threshold' => '同一账号在观察窗口内出现较多在线地址。',
            'subscription_leak_guard' => '多个异常信号共同达到订阅泄露保护条件。',
            'same_source_batch_pull', 'source_batch_pull' => '同一网络来源在短时间内拉取了多个账号的订阅。',
            'behavior_baseline_observation' => '近期订阅行为与该账号的历史使用习惯存在明显差异。',
            'multi_ip' => '同一订阅在观察窗口内从多个地址访问。',
            default => '系统记录到与该规则相关的异常订阅行为。',
        };
    }

    private function riskActionLabel(string $action): string
    {
        return match ($action) {
            'block' => '已拦截本次请求',
            'reset_token', 'reset_token_uuid' => '已重置订阅凭证',
            'notify' => '已发送风险通知',
            'observe', 'empty', 'none', '' => '仅记录观察',
            default => '已记录处理动作',
        };
    }

    /** @return array<int, string> */
    private function eventValues(array $event, string $single, string $multiple): array
    {
        $values = [];
        $this->appendUniqueValue($values, (string) ($event[$single] ?? ''), 6);
        $items = $event[$multiple] ?? [];
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [$items];
        }
        if (is_array($items)) {
            foreach ($items as $item) {
                $this->appendUniqueValue($values, (string) $item, 6);
            }
        }

        return $values;
    }

    /** @param array<int, string> $values */
    private function appendUniqueValue(array &$values, string $value, int $limit): void
    {
        $value = $this->sanitizer->sanitize(trim($value), 80);
        if ($value === '' || in_array($value, $values, true) || count($values) >= $limit) {
            return;
        }
        $values[] = $value;
    }

    /** @return array<int, array{role:string, content:string}> */
    private function conversation(Ticket $ticket, int $maxMessages): array
    {
        $messages = $ticket->messages->map(fn ($message): array => [
            'role' => (int) $message->user_id === (int) $ticket->user_id ? 'user' : 'assistant',
            'content' => (string) $message->message,
        ]);

        return $this->sanitizer->sanitizeConversation($messages, max(1, $maxMessages));
    }

    /** @return array<int, string> */
    private function uniqueStrings(array $values): array
    {
        $values = array_map(
            fn ($value): string => $this->sanitizer->sanitize(trim((string) $value), 80),
            $values
        );

        return array_slice(array_values(array_unique(array_filter($values))), 0, 8);
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function host(string $url): string
    {
        return $this->sanitizer->sanitize((string) (parse_url($url, PHP_URL_HOST) ?: ''), 255);
    }

    private function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}

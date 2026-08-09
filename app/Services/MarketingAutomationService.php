<?php

namespace App\Services;

use App\Models\AgentUser;
use App\Models\MarketingRule;
use App\Models\MarketingTemplate;
use App\Models\MessageDispatchLog;
use App\Models\MessageDispatchTask;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class MarketingAutomationService
{
    private MessageDispatchService $dispatchService;

    public function __construct(MessageDispatchService $dispatchService)
    {
        $this->dispatchService = $dispatchService;
    }

    public function seedDefaults(): void
    {
        Cache::lock('marketing:seed-defaults', 30)->block(10, function (): void {
            $this->seedDefaultRecords();
        });
    }

    private function seedDefaultRecords(): void
    {
        $templates = [
            'registered_no_purchase_1d_email' => [
                'name' => '注册后 1 天未购买',
                'channel' => MarketingTemplate::CHANNEL_EMAIL,
                'message_type' => MarketingRule::TYPE_MARKETING,
                'subject' => '欢迎回来，{{app_name}} 有适合你的套餐',
                'content' => "您好，{{user_email}}\n\n你已经完成注册，但还没有开始使用 {{app_name}}。\n现在登录即可查看当前可用套餐与节点信息：{{app_url}}\n\n如果你在选择套餐时有疑问，可以直接回复此邮件或联系站点支持。",
                'variables' => ['app_name', 'app_url', 'user_email'],
            ],
            'order_pending_unpaid_email' => [
                'name' => '订单创建后未支付',
                'channel' => MarketingTemplate::CHANNEL_EMAIL,
                'message_type' => MarketingRule::TYPE_LIFECYCLE,
                'subject' => '你的订单 {{order_trade_no}} 仍待支付',
                'content' => "你好，{{user_email}}\n\n你创建的订单 {{order_trade_no}} 仍未完成支付，订单金额为 {{order_total}}。\n如果仍需开通服务，请尽快完成支付：{{app_url}}\n\n如果这笔订单已经不再需要，可以忽略此邮件。",
                'variables' => ['app_name', 'app_url', 'user_email', 'order_trade_no', 'order_total'],
            ],
            'plan_expiring_3d_email' => [
                'name' => '套餐到期前 3 天',
                'channel' => MarketingTemplate::CHANNEL_EMAIL,
                'message_type' => MarketingRule::TYPE_LIFECYCLE,
                'subject' => '你的套餐 {{plan_name}} 将在 3 天后到期',
                'content' => "你好，{{user_email}}\n\n你当前的套餐 {{plan_name}} 将于 {{expire_at}} 到期。\n为了避免服务中断，请提前安排续费：{{app_url}}",
                'variables' => ['app_name', 'app_url', 'user_email', 'plan_name', 'expire_at'],
            ],
            'plan_expired_1d_email' => [
                'name' => '套餐到期后 1 天未续费',
                'channel' => MarketingTemplate::CHANNEL_EMAIL,
                'message_type' => MarketingRule::TYPE_LIFECYCLE,
                'subject' => '你的套餐 {{plan_name}} 已过期 1 天',
                'content' => "你好，{{user_email}}\n\n你的套餐 {{plan_name}} 已于 {{expire_at}} 到期，目前还未续费。\n如需继续使用，请前往站点完成续费：{{app_url}}",
                'variables' => ['app_name', 'app_url', 'user_email', 'plan_name', 'expire_at'],
            ],
            'inactive_7d_email' => [
                'name' => '7 天未活跃',
                'channel' => MarketingTemplate::CHANNEL_EMAIL,
                'message_type' => MarketingRule::TYPE_MARKETING,
                'subject' => '你已经 {{inactive_days}} 天没有回来看看了',
                'content' => "你好，{{user_email}}\n\n我们注意到你已经 {{inactive_days}} 天没有登录 {{app_name}}。\n如果你最近没有查看新的套餐、节点或通知，可以现在回来看看：{{app_url}}",
                'variables' => ['app_name', 'app_url', 'user_email', 'inactive_days'],
            ],
        ];

        $templateModels = [];
        foreach ($templates as $code => $template) {
            $templateModels[$code] = $this->firstOrCreateDefaultTemplate(
                $code,
                array_merge($template, [
                    'enabled' => true,
                    'is_system' => true,
                ])
            );
        }

        $rules = [
            [
                'code' => 'registered_no_purchase_1d',
                'scene' => MarketingRule::SCENE_REGISTERED_NO_PURCHASE_1D,
                'name' => '注册后 1 天未购买',
                'description' => '注册满 1 天且无订单历史时，创建营销触达任务',
                'message_type' => MarketingRule::TYPE_MARKETING,
                'priority' => 90,
                'cooldown_hours' => 24,
                'daily_user_limit' => 1,
                'trigger_config' => ['delay_days' => 1],
                'email_template_id' => $templateModels['registered_no_purchase_1d_email']->id,
            ],
            [
                'code' => 'order_pending_unpaid',
                'scene' => MarketingRule::SCENE_ORDER_PENDING_UNPAID,
                'name' => '订单创建后未支付',
                'description' => '订单创建超过阈值仍未支付时，创建生命周期提醒任务',
                'message_type' => MarketingRule::TYPE_LIFECYCLE,
                'priority' => 30,
                'cooldown_hours' => 24,
                'daily_user_limit' => 1,
                'trigger_config' => ['delay_minutes' => 30],
                'email_template_id' => $templateModels['order_pending_unpaid_email']->id,
            ],
            [
                'code' => 'plan_expiring_3d',
                'scene' => MarketingRule::SCENE_PLAN_EXPIRING_3D,
                'name' => '套餐到期前 3 天',
                'description' => '套餐到期前 3 天提醒续费',
                'message_type' => MarketingRule::TYPE_LIFECYCLE,
                'priority' => 20,
                'cooldown_hours' => 72,
                'daily_user_limit' => 1,
                'trigger_config' => ['days_before' => 3],
                'email_template_id' => $templateModels['plan_expiring_3d_email']->id,
            ],
            [
                'code' => 'plan_expired_1d',
                'scene' => MarketingRule::SCENE_PLAN_EXPIRED_1D,
                'name' => '套餐到期后 1 天未续费',
                'description' => '套餐到期后 1 天仍未续费时提醒',
                'message_type' => MarketingRule::TYPE_LIFECYCLE,
                'priority' => 25,
                'cooldown_hours' => 48,
                'daily_user_limit' => 1,
                'trigger_config' => ['days_after' => 1],
                'email_template_id' => $templateModels['plan_expired_1d_email']->id,
            ],
            [
                'code' => 'inactive_7d',
                'scene' => MarketingRule::SCENE_INACTIVE_7D,
                'name' => '7 天未活跃',
                'description' => '7 天未登录时创建营销召回任务',
                'message_type' => MarketingRule::TYPE_MARKETING,
                'priority' => 95,
                'cooldown_hours' => 168,
                'daily_user_limit' => 1,
                'trigger_config' => ['inactive_days' => 7],
                'email_template_id' => $templateModels['inactive_7d_email']->id,
            ],
        ];

        foreach ($rules as $rule) {
            MarketingRule::query()->firstOrCreate(
                ['code' => $rule['code']],
                array_merge($rule, [
                    'enabled' => true,
                    'email_enabled' => true,
                    'telegram_enabled' => false,
                ])
            );
        }
    }

    private function firstOrCreateDefaultTemplate(string $code, array $attributes): MarketingTemplate
    {
        $query = MarketingTemplate::query()->where('code', $code);

        if ($this->hasColumn('v2_marketing_template', 'scope_type')) {
            $query
                ->where('channel', (string) $attributes['channel'])
                ->where('message_type', (string) $attributes['message_type'])
                ->where(function (Builder $scope): void {
                    $scope->whereNull('scope_type')
                        ->orWhere('scope_type', '')
                        ->orWhere('scope_type', MarketingTemplate::SCOPE_GLOBAL);
                });

            foreach (['site_id', 'agent_user_id', 'agent_domain_id'] as $column) {
                if ($this->hasColumn('v2_marketing_template', $column)) {
                    $query->whereNull($column);
                }
            }
        }

        $existing = $query->orderBy('id')->first();
        if ($existing) {
            return $existing;
        }

        $payload = array_merge(['code' => $code], $attributes);
        if ($this->hasColumn('v2_marketing_template', 'scope_type')) {
            $payload = array_merge($payload, [
                'scope_type' => MarketingTemplate::SCOPE_GLOBAL,
                'site_id' => null,
                'agent_user_id' => null,
                'agent_domain_id' => null,
            ]);
        }

        return MarketingTemplate::query()->create($payload);
    }

    public function scanEnabledRules(): array
    {
        $this->seedDefaults();

        $summary = [
            'matched' => 0,
            'queued' => 0,
            'skipped' => 0,
            'rules' => [],
        ];

        $rules = MarketingRule::query()
            ->with(['emailTemplate', 'telegramTemplate'])
            ->where('enabled', true)
            ->orderBy('priority')
            ->get();

        foreach ($rules as $rule) {
            $result = match ($rule->scene) {
                MarketingRule::SCENE_REGISTERED_NO_PURCHASE_1D => $this->scanRegisteredNoPurchaseRule($rule),
                MarketingRule::SCENE_ORDER_PENDING_UNPAID => $this->scanPendingOrderRule($rule),
                MarketingRule::SCENE_PLAN_EXPIRING_3D => $this->scanPlanExpiringRule($rule),
                MarketingRule::SCENE_PLAN_EXPIRED_1D => $this->scanPlanExpiredRule($rule),
                MarketingRule::SCENE_INACTIVE_7D => $this->scanInactiveRule($rule),
                default => ['matched' => 0, 'queued' => 0, 'skipped' => 0],
            };

            $summary['matched'] += $result['matched'];
            $summary['queued'] += $result['queued'];
            $summary['skipped'] += $result['skipped'];
            $summary['rules'][] = array_merge(['code' => $rule->code, 'scene' => $rule->scene], $result);
        }

        return $summary;
    }

    private function scanRegisteredNoPurchaseRule(MarketingRule $rule): array
    {
        $delayDays = max(1, (int) ($rule->trigger_config['delay_days'] ?? 1));
        [$start, $end] = $this->dayRange(CarbonImmutable::today()->subDays($delayDays));

        $matched = 0;
        $queued = 0;
        $skipped = 0;

        $this->withoutAgentSubordinates(User::query())
            ->with('plan:id,name')
            ->whereBetween('created_at', [$start, $end])
            ->where('banned', false)
            ->whereNull('plan_id')
            ->whereNotNull('email')
            ->whereDoesntHave('orders')
            ->chunkById(200, function ($users) use ($rule, &$matched, &$queued, &$skipped): void {
                foreach ($users as $user) {
                    $matched++;
                    $result = $this->queueForUserRule(
                        $rule,
                        $user,
                        'rule:' . $rule->code . ':user:' . $user->id . ':day:' . date('Ymd', $user->created_at),
                        ['matched_scene' => $rule->scene]
                    );
                    $result ? $queued++ : $skipped++;
                }
            });

        return compact('matched', 'queued', 'skipped');
    }

    private function scanPendingOrderRule(MarketingRule $rule): array
    {
        $delayMinutes = max(5, (int) ($rule->trigger_config['delay_minutes'] ?? 30));
        $deadline = time() - ($delayMinutes * 60);
        $matched = 0;
        $queued = 0;
        $skipped = 0;

        Order::query()
            ->with(['user.plan:id,name'])
            ->where('status', Order::STATUS_PENDING)
            ->where('created_at', '<=', $deadline)
            ->chunkById(200, function ($orders) use ($rule, &$matched, &$queued, &$skipped): void {
                foreach ($orders as $order) {
                    $user = $order->user;
                    if (!$user || $user->banned || !$user->email) {
                        continue;
                    }
                    if ($this->isAgentSubordinate((int) $user->id)) {
                        continue;
                    }

                    $matched++;
                    $result = $this->queueForUserRule(
                        $rule,
                        $user,
                        'rule:' . $rule->code . ':order:' . $order->id,
                        [
                            'matched_scene' => $rule->scene,
                            'order_id' => $order->id,
                            'order_trade_no' => $order->trade_no,
                            'order_total_amount' => $order->total_amount,
                            'order_created_at' => $order->created_at,
                        ]
                    );
                    $result ? $queued++ : $skipped++;
                }
            });

        return compact('matched', 'queued', 'skipped');
    }

    private function scanPlanExpiringRule(MarketingRule $rule): array
    {
        $daysBefore = max(1, (int) ($rule->trigger_config['days_before'] ?? 3));
        [$start, $end] = $this->dayRange(CarbonImmutable::today()->addDays($daysBefore));

        $matched = 0;
        $queued = 0;
        $skipped = 0;

        $this->withoutAgentSubordinates(User::query())
            ->with('plan:id,name')
            ->where('banned', false)
            ->whereNotNull('email')
            ->whereNotNull('plan_id')
            ->whereBetween('expired_at', [$start, $end])
            ->chunkById(200, function ($users) use ($rule, &$matched, &$queued, &$skipped): void {
                foreach ($users as $user) {
                    $matched++;
                    $result = $this->queueForUserRule(
                        $rule,
                        $user,
                        'rule:' . $rule->code . ':user:' . $user->id . ':expire:' . $user->expired_at,
                        [
                            'matched_scene' => $rule->scene,
                            'expire_at' => $user->expired_at,
                            'plan_name' => $user->plan?->name,
                        ]
                    );
                    $result ? $queued++ : $skipped++;
                }
            });

        return compact('matched', 'queued', 'skipped');
    }

    private function scanPlanExpiredRule(MarketingRule $rule): array
    {
        $daysAfter = max(1, (int) ($rule->trigger_config['days_after'] ?? 1));
        [$start, $end] = $this->dayRange(CarbonImmutable::today()->subDays($daysAfter));

        $matched = 0;
        $queued = 0;
        $skipped = 0;

        $this->withoutAgentSubordinates(User::query())
            ->with('plan:id,name')
            ->where('banned', false)
            ->whereNotNull('email')
            ->whereNotNull('plan_id')
            ->whereBetween('expired_at', [$start, $end])
            ->chunkById(200, function ($users) use ($rule, &$matched, &$queued, &$skipped): void {
                foreach ($users as $user) {
                    $matched++;
                    $result = $this->queueForUserRule(
                        $rule,
                        $user,
                        'rule:' . $rule->code . ':user:' . $user->id . ':expired:' . $user->expired_at,
                        [
                            'matched_scene' => $rule->scene,
                            'expire_at' => $user->expired_at,
                            'plan_name' => $user->plan?->name,
                        ]
                    );
                    $result ? $queued++ : $skipped++;
                }
            });

        return compact('matched', 'queued', 'skipped');
    }

    private function scanInactiveRule(MarketingRule $rule): array
    {
        $inactiveDays = max(1, (int) ($rule->trigger_config['inactive_days'] ?? 7));
        [$start, $end] = $this->dayRange(CarbonImmutable::today()->subDays($inactiveDays));

        $matched = 0;
        $queued = 0;
        $skipped = 0;

        $this->withoutAgentSubordinates(User::query())
            ->with('plan:id,name')
            ->where('banned', false)
            ->whereNotNull('email')
            ->whereRaw('COALESCE(last_login_at, created_at) BETWEEN ? AND ?', [$start, $end])
            ->chunkById(200, function ($users) use ($rule, $inactiveDays, &$matched, &$queued, &$skipped): void {
                foreach ($users as $user) {
                    $matched++;
                    $result = $this->queueForUserRule(
                        $rule,
                        $user,
                        'rule:' . $rule->code . ':user:' . $user->id . ':inactive-anchor:' . ($user->last_login_at ?: $user->created_at),
                        [
                            'matched_scene' => $rule->scene,
                            'inactive_days' => $inactiveDays,
                        ]
                    );
                    $result ? $queued++ : $skipped++;
                }
            });

        return compact('matched', 'queued', 'skipped');
    }

    private function queueForUserRule(MarketingRule $rule, User $user, string $dedupeKey, array $context = []): bool
    {
        $now = time();
        $queued = false;
        $notificationContext = app(NotificationSiteContextService::class)->forUser($user);
        $baseContext = array_merge($context, [
            'rule_code' => $rule->code,
            'scene' => $rule->scene,
            'user_email' => $user->email,
        ], app(NotificationSiteContextService::class)->dispatchContext($notificationContext));

        if (
            $rule->email_enabled &&
            $rule->emailTemplate &&
            $user->email
        ) {
            $emailTemplate = $this->resolveEffectiveTemplate($rule->emailTemplate, $baseContext, MarketingTemplate::CHANNEL_EMAIL);
            if (
                $emailTemplate &&
                $this->canCreateRuleTask($rule, $user->id, MarketingTemplate::CHANNEL_EMAIL, $now)
            ) {
                $variables = $this->buildTemplateVariables($user, $baseContext);
                $rendered = $this->renderTemplate($emailTemplate, $variables);
                $task = $this->dispatchService->enqueueTask([
                    'user_id' => $user->id,
                    'rule_id' => $rule->id,
                    'template_id' => $emailTemplate->id,
                    'channel' => MarketingTemplate::CHANNEL_EMAIL,
                    'message_type' => $rule->message_type,
                    'priority' => $rule->priority,
                    'dedupe_key' => $dedupeKey . ':email',
                    'to_address' => $user->email,
                    'subject' => $rendered['subject'],
                    'payload' => [
                        'template_name' => 'notify',
                        'template_value' => [
                            'name' => $variables['app_name'],
                            'url' => $variables['app_url'],
                            'content' => $rendered['content'],
                        ],
                    ],
                    'context' => $baseContext,
                ]);

                if ($task) {
                    $queued = true;
                }
            }
        }

        if (
            $rule->telegram_enabled &&
            $rule->telegramTemplate &&
            $user->telegram_id
        ) {
            $telegramTemplate = $this->resolveEffectiveTemplate($rule->telegramTemplate, $baseContext, MarketingTemplate::CHANNEL_TELEGRAM);
            if (
                $telegramTemplate &&
                $this->canCreateRuleTask($rule, $user->id, MarketingTemplate::CHANNEL_TELEGRAM, $now)
            ) {
                $variables = $this->buildTemplateVariables($user, $baseContext);
                $rendered = $this->renderTemplate($telegramTemplate, $variables);
                $task = $this->dispatchService->enqueueTask([
                    'user_id' => $user->id,
                    'rule_id' => $rule->id,
                    'template_id' => $telegramTemplate->id,
                    'channel' => MarketingTemplate::CHANNEL_TELEGRAM,
                    'message_type' => $rule->message_type,
                    'priority' => $rule->priority,
                    'dedupe_key' => $dedupeKey . ':telegram',
                    'to_address' => (string) $user->telegram_id,
                    'subject' => null,
                    'payload' => [
                        'telegram_id' => (int) $user->telegram_id,
                        'text' => $rendered['content'],
                    ],
                    'context' => $baseContext,
                ]);

                if ($task) {
                    $queued = true;
                }
            }
        }

        return $queued;
    }

    private function resolveEffectiveTemplate(?MarketingTemplate $baseTemplate, array $context, string $channel): ?MarketingTemplate
    {
        if (!$baseTemplate) {
            return null;
        }

        if (!$this->hasColumn('v2_marketing_template', 'scope_type')) {
            return $baseTemplate->enabled ? $baseTemplate : null;
        }

        $baseQuery = MarketingTemplate::query()
            ->where('code', $baseTemplate->code)
            ->where('channel', $channel)
            ->where('message_type', $baseTemplate->message_type)
            ->where('enabled', true)
            ->orderByDesc('id');

        $agentUserId = $this->positiveIntOrNull($context['agent_user_id'] ?? null);
        if ($agentUserId) {
            $agentQuery = (clone $baseQuery)
                ->where('scope_type', MarketingTemplate::SCOPE_AGENT)
                ->where('agent_user_id', $agentUserId);

            $agentDomainId = $this->positiveIntOrNull($context['agent_domain_id'] ?? null);
            if ($agentDomainId) {
                $exactAgentTemplate = (clone $agentQuery)
                    ->where('agent_domain_id', $agentDomainId)
                    ->first();
                if ($exactAgentTemplate) {
                    return $exactAgentTemplate;
                }
            }

            $genericAgentTemplate = (clone $agentQuery)
                ->whereNull('agent_domain_id')
                ->first();
            if ($genericAgentTemplate) {
                return $genericAgentTemplate;
            }
        }

        $siteId = $this->positiveIntOrNull($context['site_id'] ?? null);
        if ($siteId) {
            $siteTemplate = (clone $baseQuery)
                ->where('scope_type', MarketingTemplate::SCOPE_SITE)
                ->where('site_id', $siteId)
                ->first();
            if ($siteTemplate) {
                return $siteTemplate;
            }
        }

        $globalTemplate = (clone $baseQuery)
            ->where(function ($query): void {
                $query->whereNull('scope_type')
                    ->orWhere('scope_type', '')
                    ->orWhere('scope_type', MarketingTemplate::SCOPE_GLOBAL);
            })
            ->first();
        if ($globalTemplate) {
            return $globalTemplate;
        }

        return $this->templateMatchesContext($baseTemplate, $context) && $baseTemplate->enabled
            ? $baseTemplate
            : null;
    }

    private function templateMatchesContext(MarketingTemplate $template, array $context): bool
    {
        $scope = $template->scopePayload();
        if ($scope['scope_type'] === MarketingTemplate::SCOPE_GLOBAL) {
            return true;
        }

        $siteId = $this->positiveIntOrNull($context['site_id'] ?? null);
        if ($scope['scope_type'] === MarketingTemplate::SCOPE_SITE) {
            return $siteId !== null && (int) $scope['site_id'] === $siteId;
        }

        $agentUserId = $this->positiveIntOrNull($context['agent_user_id'] ?? null);
        if ($agentUserId === null || (int) $scope['agent_user_id'] !== $agentUserId) {
            return false;
        }

        $agentDomainId = $this->positiveIntOrNull($context['agent_domain_id'] ?? null);
        return empty($scope['agent_domain_id']) || $agentDomainId === (int) $scope['agent_domain_id'];
    }

    private function canCreateRuleTask(MarketingRule $rule, int $userId, string $channel, int $now): bool
    {
        $hasPending = MessageDispatchTask::query()
            ->where('rule_id', $rule->id)
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->whereIn('state', [MessageDispatchTask::STATE_PENDING, MessageDispatchTask::STATE_SENDING])
            ->exists();

        if ($hasPending) {
            return false;
        }

        $cooldownCutoff = $now - (max(1, (int) $rule->cooldown_hours) * 3600);
        $hasRecentSuccess = MessageDispatchLog::query()
            ->where('rule_id', $rule->id)
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->where('status', MessageDispatchLog::STATUS_SUCCESS)
            ->where('created_at', '>=', $cooldownCutoff)
            ->exists();

        if ($hasRecentSuccess) {
            return false;
        }

        $dailyCount = MessageDispatchLog::query()
            ->where('rule_id', $rule->id)
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->where('status', MessageDispatchLog::STATUS_SUCCESS)
            ->where('created_at', '>=', $now - 86400)
            ->count();

        return $dailyCount < max(1, (int) $rule->daily_user_limit);
    }

    private function buildTemplateVariables(User $user, array $context): array
    {
        $notificationContext = app(NotificationSiteContextService::class)->forUser($user);
        $appUrl = (string) ($context['app_url'] ?? $notificationContext['app_url'] ?? admin_setting('app_url', ''));
        $appName = (string) ($context['app_name'] ?? $notificationContext['app_name'] ?? admin_setting('app_name', 'XBoard'));
        $planName = (string) ($context['plan_name'] ?? $user->plan?->name ?? '当前套餐');
        $orderAmount = isset($context['order_total_amount'])
            ? $this->formatAmount((int) $context['order_total_amount'])
            : null;

        return [
            'app_name' => $appName,
            'app_url' => $appUrl,
            'user_email' => (string) $user->email,
            'user_id' => (string) $user->id,
            'plan_name' => $planName,
            'expire_at' => !empty($context['expire_at']) ? date('Y-m-d H:i:s', (int) $context['expire_at']) : '',
            'order_trade_no' => (string) ($context['order_trade_no'] ?? ''),
            'order_total' => $orderAmount ?? '',
            'inactive_days' => (string) ($context['inactive_days'] ?? ''),
        ];
    }

    private function renderTemplate(MarketingTemplate $template, array $variables): array
    {
        return [
            'subject' => $this->applyVariables((string) ($template->subject ?? ''), $variables),
            'content' => $this->applyVariables((string) $template->content, $variables),
        ];
    }

    private function applyVariables(string $content, array $variables): string
    {
        $replace = [];
        foreach ($variables as $key => $value) {
            $replace['{{' . $key . '}}'] = (string) $value;
            $replace['{{ ' . $key . ' }}'] = (string) $value;
        }
        return strtr($content, $replace);
    }

    private function formatAmount(int $amount): string
    {
        $symbol = (string) admin_setting('currency_symbol', '¥');
        return $symbol . number_format($amount / 100, 2);
    }

    private function dayRange(CarbonImmutable $day): array
    {
        return [
            $day->startOfDay()->timestamp,
            $day->endOfDay()->timestamp,
        ];
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function withoutAgentSubordinates(Builder $query): Builder
    {
        if (!$this->hasTable('v2_agent_user')) {
            return $query;
        }

        return $query->whereNotIn('id', AgentUser::query()->select('sub_user_id'));
    }

    private function isAgentSubordinate(int $userId): bool
    {
        if ($userId <= 0 || !$this->hasTable('v2_agent_user')) {
            return false;
        }

        return AgentUser::query()->where('sub_user_id', $userId)->exists();
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

<?php

namespace App\Services;

use App\Exceptions\TicketAiProviderException;
use App\Models\SubscriptionControlAiReview;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SubscriptionControlAiAdvisorService
{
    private const PLUGIN = 'subscription_control';
    private const EVENT_TABLE = 'v2_subscription_control_event';
    private const REVIEW_TABLE = 'v2_subscription_control_ai_review';

    /** @var array<string, array{label:string,min:int,max:int,code:string,field:string,operator:string}> */
    private const RULES = [
        'leak_guard_score_threshold' => ['label' => '泄露保护触发分数', 'min' => 40, 'max' => 100, 'code' => 'subscription_leak_guard', 'field' => 'risk_score', 'operator' => 'gte'],
        'leak_guard_allowed_ip_count' => ['label' => '泄露保护允许拉取 IP 数', 'min' => 1, 'max' => 10, 'code' => 'subscription_leak_guard', 'field' => 'ip_count', 'operator' => 'gt'],
        'leak_guard_allowed_ua_count' => ['label' => '泄露保护允许 UA 类别数', 'min' => 1, 'max' => 8, 'code' => 'subscription_leak_guard', 'field' => 'ua_categories', 'operator' => 'count_gt'],
        'leak_guard_allowed_region_count' => ['label' => '泄露保护允许拉取地区数', 'min' => 1, 'max' => 8, 'code' => 'subscription_leak_guard', 'field' => 'regions', 'operator' => 'count_gt'],
        'source_batch_user_threshold' => ['label' => '同源批量用户阈值', 'min' => 2, 'max' => 20, 'code' => 'source_batch_pull', 'field' => 'source_user_count', 'operator' => 'gte'],
        'multi_ua_allowed_count' => ['label' => '允许的客户端类别数', 'min' => 1, 'max' => 8, 'code' => 'multi_ua_pull', 'field' => 'ua_categories', 'operator' => 'count_gt'],
        'multi_region_pull_allowed_count' => ['label' => '允许的拉取地区数', 'min' => 1, 'max' => 8, 'code' => 'multi_region_pull', 'field' => 'regions', 'operator' => 'count_gt'],
        'multi_region_online_allowed_count' => ['label' => '允许的在线地区数', 'min' => 1, 'max' => 8, 'code' => 'multi_region_online', 'field' => 'online_regions', 'operator' => 'count_gt'],
        'online_ip_threshold' => ['label' => '在线唯一 IP 数阈值', 'min' => 2, 'max' => 100, 'code' => 'online_ip_threshold', 'field' => 'online_ip_count', 'operator' => 'gt'],
    ];

    public function __construct(
        private readonly ?TicketAiProviderClient $providerClient = null,
        private readonly ?TicketAiAssistantService $aiSettings = null,
        private readonly ?PluginConfigService $pluginConfig = null
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $settings = $this->ai()->publicSettings();
        $latest = $this->tableAvailable()
            ? SubscriptionControlAiReview::query()->latest('id')->first()
            : null;

        return [
            'available' => $this->tableAvailable(),
            'ai_ready' => (bool) ($settings['ticket_ai_enable'] ?? false)
                && (bool) ($settings['ticket_ai_api_key_set'] ?? false)
                && trim((string) ($settings['ticket_ai_base_url'] ?? '')) !== ''
                && trim((string) ($settings['ticket_ai_model'] ?? '')) !== '',
            'managed_rules' => $this->catalog(),
            'latest_review' => $latest ? $this->serialize($latest) : null,
            'safety' => [
                'direct_enforcement' => false,
                'manual_approval_required' => true,
                'replay_scope' => 'triggered_events_only',
            ],
        ];
    }

    public function create(int $adminId, int $days): SubscriptionControlAiReview
    {
        if (!$this->tableAvailable()) {
            throw new RuntimeException('migration_required');
        }

        return SubscriptionControlAiReview::query()->create([
            'status' => 'pending',
            'window_days' => max(3, min(30, $days)),
            'admin_id' => $adminId > 0 ? $adminId : null,
        ]);
    }

    public function generate(int $reviewId): void
    {
        $review = SubscriptionControlAiReview::query()->find($reviewId);
        if (!$review || $review->status !== 'pending') {
            return;
        }

        try {
            $config = $this->currentConfig();
            $events = $this->events((int) $review->window_days);
            $populationMetrics = (new SubscriptionControlPopulationMetricsService())->collect((int) $review->window_days);
            $metrics = $this->metrics($events, (int) $review->window_days, $populationMetrics);
            $providerSettings = $this->ai()->providerSettingsForInternalUse();
            if (
                !(bool) ($providerSettings['enabled'] ?? false)
                || trim((string) ($providerSettings['base_url'] ?? '')) === ''
                || trim((string) ($providerSettings['model'] ?? '')) === ''
                || trim((string) ($providerSettings['api_key'] ?? '')) === ''
            ) {
                throw new RuntimeException('ai_disabled');
            }

            $decoded = $this->requestReview(
                $providerSettings,
                $this->messages($config, $metrics)
            );
            $suggestions = $this->suggestions((array) ($decoded['suggestions'] ?? []), $config);

            $review->forceFill([
                'status' => 'completed',
                'event_count' => count($events),
                'health_score' => max(0, min(100, (int) ($decoded['health_score'] ?? 0))),
                'summary' => mb_substr(trim((string) ($decoded['summary'] ?? '')), 0, 2000),
                'current_config' => $config,
                'metrics' => $metrics,
                'findings' => $this->findings((array) ($decoded['findings'] ?? []), $metrics),
                'suggestions' => $suggestions,
                'replay' => $this->replay($suggestions, $events, $config),
                'error_code' => null,
                'generated_at' => time(),
            ])->save();
        } catch (TicketAiProviderException $exception) {
            $this->fail($review, $exception->errorCode());
        } catch (\Throwable $exception) {
            $code = $exception instanceof RuntimeException ? $exception->getMessage() : 'generation_failed';
            $this->fail($review, $code ?: 'generation_failed');
            Log::warning('[SubscriptionControlAiAdvisor] review failed', [
                'review_id' => $reviewId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<int, string> $suggestionIds */
    public function apply(int $reviewId, array $suggestionIds): SubscriptionControlAiReview
    {
        return DB::transaction(function () use ($reviewId, $suggestionIds): SubscriptionControlAiReview {
            $review = SubscriptionControlAiReview::query()->lockForUpdate()->find($reviewId);
            if (!$review || $review->status !== 'completed') {
                throw new RuntimeException('review_not_applicable');
            }

            $ids = array_fill_keys(array_map('strval', $suggestionIds), true);
            $selected = array_values(array_filter(
                (array) $review->suggestions,
                static fn (array $item): bool => isset($ids[(string) ($item['id'] ?? '')])
            ));
            if ($selected === []) {
                throw new RuntimeException('suggestion_required');
            }

            $dbConfig = $this->configService()->getDbConfig(self::PLUGIN);
            $live = $this->currentConfig();
            $changes = [];
            foreach ($selected as $item) {
                $key = (string) ($item['key'] ?? '');
                $before = (int) ($item['current_value'] ?? 0);
                $after = (int) ($item['suggested_value'] ?? 0);
                if (!$this->allowed($key, $after)) {
                    throw new RuntimeException('unsafe_suggestion');
                }
                if ((int) ($live[$key] ?? PHP_INT_MIN) !== $before) {
                    throw new RuntimeException('config_changed');
                }
                $dbConfig[$key] = $after;
                $live[$key] = $after;
                $changes[] = [
                    'suggestion_id' => (string) $item['id'],
                    'key' => $key,
                    'before' => $before,
                    'after' => $after,
                    'applied_at' => time(),
                ];
            }

            $this->configService()->updateConfig(self::PLUGIN, $dbConfig);
            $review->forceFill([
                'status' => 'applied',
                'applied_changes' => $changes,
                'applied_at' => time(),
            ])->save();

            Log::notice('[SubscriptionControlAiAdvisor] suggestions applied', [
                'review_id' => $reviewId,
                'admin_id' => $review->admin_id,
                'rule_keys' => array_column($changes, 'key'),
            ]);

            return $review->fresh();
        });
    }

    public function rollback(int $reviewId): SubscriptionControlAiReview
    {
        return DB::transaction(function () use ($reviewId): SubscriptionControlAiReview {
            $review = SubscriptionControlAiReview::query()->lockForUpdate()->find($reviewId);
            $changes = (array) ($review?->applied_changes ?? []);
            if (!$review || $review->status !== 'applied' || $changes === []) {
                throw new RuntimeException('review_not_rollbackable');
            }

            $dbConfig = $this->configService()->getDbConfig(self::PLUGIN);
            $live = $this->currentConfig();
            foreach (array_reverse($changes) as $change) {
                $key = (string) ($change['key'] ?? '');
                if (!isset(self::RULES[$key])) {
                    continue;
                }
                if ((int) ($live[$key] ?? PHP_INT_MIN) !== (int) ($change['after'] ?? PHP_INT_MAX)) {
                    throw new RuntimeException('config_changed');
                }
                $dbConfig[$key] = (int) $change['before'];
                $live[$key] = (int) $change['before'];
            }

            $this->configService()->updateConfig(self::PLUGIN, $dbConfig);
            $review->forceFill([
                'status' => 'rolled_back',
                'rolled_back_at' => time(),
            ])->save();

            Log::notice('[SubscriptionControlAiAdvisor] suggestions rolled back', [
                'review_id' => $reviewId,
                'admin_id' => $review->admin_id,
                'rule_keys' => array_column($changes, 'key'),
            ]);

            return $review->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function serialize(SubscriptionControlAiReview $review): array
    {
        return [
            'id' => (int) $review->id,
            'status' => (string) $review->status,
            'window_days' => (int) $review->window_days,
            'event_count' => (int) $review->event_count,
            'health_score' => $review->health_score === null ? null : (int) $review->health_score,
            'summary' => $review->summary,
            'metrics' => $review->metrics ?: [],
            'findings' => $review->findings ?: [],
            'suggestions' => $review->suggestions ?: [],
            'replay' => $review->replay ?: [],
            'applied_changes' => $review->applied_changes ?: [],
            'error_code' => $review->error_code,
            'generated_at' => $review->generated_at,
            'applied_at' => $review->applied_at,
            'rolled_back_at' => $review->rolled_back_at,
            'created_at' => $this->timestamp($review->created_at),
        ];
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function catalog(): array
    {
        $current = $this->currentConfig();
        $result = [];
        foreach (self::RULES as $key => $rule) {
            $result[$key] = [
                'key' => $key,
                'label' => $rule['label'],
                'min' => $rule['min'],
                'max' => $rule['max'],
                'current_value' => $current[$key],
            ];
        }

        return $result;
    }

    /** @return array<string, int> */
    private function currentConfig(): array
    {
        $fields = $this->configService()->getConfig(self::PLUGIN);
        $result = [];
        foreach (self::RULES as $key => $rule) {
            $result[$key] = max(
                $rule['min'],
                min($rule['max'], (int) ($fields[$key]['value'] ?? $rule['min']))
            );
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function events(int $days): array
    {
        if (!Schema::hasTable(self::EVENT_TABLE)) {
            return [];
        }

        return DB::table(self::EVENT_TABLE)
            ->where('created_at', '>=', time() - (max(3, min(30, $days)) * 86400))
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get()
            ->map(function ($row): array {
                $event = (array) $row;
                foreach (['ua_categories', 'regions', 'online_regions', 'signals', 'ip_risk_tags'] as $field) {
                    $decoded = json_decode((string) ($event[$field] ?? ''), true);
                    $event[$field] = is_array($decoded) ? array_values($decoded) : [];
                }

                return $event;
            })->all();
    }

    /** @param array<int, array<string, mixed>> $events */
    private function metrics(array $events, int $days, array $aggregate = []): array
    {
        $codes = [];
        $actions = [];
        $signals = [];
        $users = [];
        $userHits = [];
        $riskScores = [];
        $hosting = 0;
        $proxy = 0;

        foreach ($events as $event) {
            $code = trim((string) ($event['code'] ?? 'unknown')) ?: 'unknown';
            $action = trim((string) ($event['action'] ?? 'unknown')) ?: 'unknown';
            $codes[$code] = ($codes[$code] ?? 0) + 1;
            $actions[$action] = ($actions[$action] ?? 0) + 1;
            $userId = (int) ($event['user_id'] ?? 0);
            if ($userId > 0) {
                $users[$userId] = true;
                $userHits[$userId] = ($userHits[$userId] ?? 0) + 1;
            }
            if (is_numeric($event['risk_score'] ?? null)) {
                $riskScores[] = (int) $event['risk_score'];
            }
            foreach ((array) ($event['signals'] ?? []) as $signal) {
                $signal = mb_substr(trim((string) $signal), 0, 80);
                if ($signal !== '') {
                    $signals[$signal] = ($signals[$signal] ?? 0) + 1;
                }
            }
            $hosting += strtolower((string) ($event['ip_type'] ?? '')) === 'hosting' ? 1 : 0;
            $proxy += strtolower((string) ($event['ip_type'] ?? '')) === 'proxy' ? 1 : 0;
        }

        arsort($codes);
        arsort($actions);
        arsort($signals);

        $daily = [];
        $pulls = 0;
        $allowed = 0;
        $blocked = 0;
        for ($offset = max(0, $days - 1); $offset >= 0; $offset--) {
            $date = date('Y-m-d', strtotime("-{$offset} days"));
            $dayPulls = (int) cache()->get("subscription_control:telemetry:pulls:{$date}", 0);
            $dayAllowed = (int) cache()->get("subscription_control:telemetry:allowed:{$date}", 0);
            $dayBlocked = (int) cache()->get("subscription_control:blocked_count:{$date}", 0);
            $pulls += $dayPulls;
            $allowed += $dayAllowed;
            $blocked += $dayBlocked;
            $daily[] = [
                'date' => $date,
                'pulls' => $dayPulls,
                'allowed' => $dayAllowed,
                'blocked' => $dayBlocked,
            ];
        }

        $population = is_array($aggregate['population'] ?? null) ? $aggregate['population'] : [];
        $evidence = is_array($aggregate['event_evidence'] ?? null) ? $aggregate['event_evidence'] : [];
        $eventCount = (int) ($evidence['total_event_count'] ?? count($events));
        $affectedUsers = (int) ($evidence['unique_affected_users'] ?? count($users));
        $repeatUsers = (int) ($evidence['repeat_affected_users']
            ?? count(array_filter($userHits, static fn (int $count): bool => $count > 1)));
        $totalUsers = (int) ($population['total_users'] ?? 0);
        $activeUsers = (int) ($population['active_users'] ?? 0);
        if ($population !== []) {
            $population['affected_user_rate'] = $totalUsers > 0
                ? round($affectedUsers / $totalUsers, 6)
                : 0.0;
            $population['active_affected_upper_bound_rate'] = $activeUsers > 0
                ? round(min($affectedUsers, $activeUsers) / $activeUsers, 6)
                : 0.0;
        }

        $sampleSignals = array_slice($signals, 0, 12, true);
        $fullCodeCounts = is_array($evidence['code_counts'] ?? null)
            ? $evidence['code_counts']
            : array_slice($codes, 0, 12, true);
        $fullActionCounts = is_array($evidence['action_counts'] ?? null)
            ? $evidence['action_counts']
            : $actions;
        $codeBreakdown = is_array($evidence['code_breakdown'] ?? null)
            ? $evidence['code_breakdown']
            : [];
        $ruleEvidence = $this->ruleEvidence($codeBreakdown);
        $behaviorBaseline = is_array($codeBreakdown['behavior_baseline_observation'] ?? null)
            ? $codeBreakdown['behavior_baseline_observation']
            : [];

        return [
            'window_days' => $days,
            'event_count' => $eventCount,
            'sample_event_count' => count($events),
            'unique_affected_users' => $affectedUsers,
            'repeat_user_count' => $repeatUsers,
            'code_counts' => $fullCodeCounts,
            'action_counts' => $fullActionCounts,
            'code_breakdown' => $codeBreakdown,
            'rule_evidence' => $ruleEvidence,
            'behavior_baseline' => [
                'mode' => 'observe_only',
                'event_count' => (int) ($behaviorBaseline['event_count'] ?? 0),
                'affected_users' => (int) ($behaviorBaseline['affected_users'] ?? 0),
                'repeat_affected_users' => (int) ($behaviorBaseline['repeat_affected_users'] ?? 0),
                'enforcement_count' => 0,
            ],
            'top_signals' => $sampleSignals,
            'average_risk_score' => $evidence['average_risk_score']
                ?? ($riskScores === [] ? null : round(array_sum($riskScores) / count($riskScores), 2)),
            'maximum_risk_score' => $evidence['maximum_risk_score']
                ?? ($riskScores === [] ? null : max($riskScores)),
            'hosting_source_count' => (int) ($evidence['hosting_source_count'] ?? $hosting),
            'proxy_source_count' => (int) ($evidence['proxy_source_count'] ?? $proxy),
            'population' => $population,
            'event_evidence' => array_merge($evidence, [
                'total_event_count' => $eventCount,
                'unique_affected_users' => $affectedUsers,
                'repeat_affected_users' => $repeatUsers,
                'code_counts' => $fullCodeCounts,
                'action_counts' => $fullActionCounts,
                'replay_sample_count' => count($events),
                'top_signals_sample' => $sampleSignals,
            ]),
            'operational_telemetry' => [
                'pulls' => $pulls,
                'allowed' => $allowed,
                'blocked' => $blocked,
                'block_rate' => $pulls > 0 ? round($blocked / $pulls, 4) : null,
                'daily' => $daily,
                'quality' => 'informational_only',
                'comparable_to_event_evidence' => false,
            ],
            'data_limits' => [
                'all_consumer_users_aggregated' => ($population['available'] ?? false) === true,
                'event_totals_cover_full_window' => ($evidence['full_window_aggregated'] ?? false) === true,
                'events_are_triggered_only' => true,
                'behavior_baseline_is_observe_only' => true,
                'behavior_baseline_never_enforces' => true,
                'replay_sample_limit' => 5000,
                'replay_sample_count' => count($events),
                'top_signals_are_sampled' => true,
                'missing_rule_fields_are_scoped_per_rule' => true,
                'risk_scores_only_apply_to_scored_rules' => true,
                'operational_telemetry_is_non_comparable' => true,
                'personal_data_sent' => false,
                'excluded_accounts' => 'administrators_and_staff',
            ],
        ];
    }

    /** @param array<string, array<string, mixed>> $codeBreakdown */
    private function ruleEvidence(array $codeBreakdown): array
    {
        $result = [];
        foreach (self::RULES as $key => $rule) {
            $stats = is_array($codeBreakdown[$rule['code']] ?? null)
                ? $codeBreakdown[$rule['code']]
                : [];
            $eventCount = (int) ($stats['event_count'] ?? 0);
            $fieldCounts = is_array($stats['field_event_counts'] ?? null)
                ? $stats['field_event_counts']
                : [];
            $fieldEventCount = (int) ($fieldCounts[$rule['field']] ?? 0);
            $status = $eventCount === 0
                ? 'not_triggered_in_window'
                : ($fieldEventCount === 0 ? 'triggered_without_rule_field' : 'triggered_evidence_available');

            $result[$key] = [
                'label' => $rule['label'],
                'code' => $rule['code'],
                'field' => $rule['field'],
                'status' => $status,
                'triggered_event_count' => $eventCount,
                'affected_users' => (int) ($stats['affected_users'] ?? 0),
                'repeat_affected_users' => (int) ($stats['repeat_affected_users'] ?? 0),
                'field_evidence_count' => $fieldEventCount,
                'field_coverage_rate' => $eventCount > 0 ? round($fieldEventCount / $eventCount, 6) : 0.0,
                'evidence_scope' => 'triggered_events_only',
            ];
        }

        return $result;
    }

    /** @return array<int, array{role:string,content:string}> */
    private function messages(array $config, array $metrics): array
    {
        $catalog = [];
        foreach (self::RULES as $key => $rule) {
            $catalog[] = [
                'key' => $key,
                'label' => $rule['label'],
                'current_value' => $config[$key],
                'min' => $rule['min'],
                'max' => $rule['max'],
            ];
        }

        $payload = json_encode([
            'rule_catalog' => $catalog,
            'aggregated_metrics' => $metrics,
            'constraints' => [
                'manual_approval_required' => true,
                'population_is_full_consumer_baseline' => true,
                'event_evidence_is_full_window_aggregate' => true,
                'historical_events_are_triggered_only' => true,
                'replay_sample_does_not_limit_population_or_event_totals' => true,
                'operational_telemetry_must_not_be_compared_with_event_evidence' => true,
                'prefer_no_change_when_evidence_is_weak' => true,
                'not_triggered_rule_is_neutral' => true,
                'optional_field_gaps_are_not_findings' => true,
                'behavior_baseline_is_supporting_evidence_only' => true,
                'behavior_baseline_must_not_be_reported_as_enforcement' => true,
                'user_facing_chinese_only' => true,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return [
            [
                'role' => 'system',
                'content' => '你是订阅风控规则顾问。只分析匿名聚合统计，不直接执行封禁。population 是所有普通用户的全量基线，event_evidence 是时间窗口内全部已触发事件的聚合证据，code_breakdown 是按触发类型拆分的完整统计，rule_evidence 明确每个可调阈值是否有对应字段证据。behavior_baseline 是逐个订阅用户的匿名习惯偏离聚合，只用于辅助判断；其事件固定为 observe_only，不会拦截、重置凭证或通知用户，不得把它描述为已经处罚。rule_evidence.status=not_triggered_in_window 表示该规则本周期没有触发，是中性状态，不是数据故障，不得因此降低健康分或生成问题；风险分只属于需要评分的规则，其他固定拦截事件没有风险分属于正常现象。replay_sample_count 只限制事件回放和抽样信号，不限制用户总体、事件总数或按类型统计。operational_telemetry 仅供运行参考，不得与 event_evidence 直接对比。当 population.available=true 时不得声称缺少全量用户基线。findings 只写有完整聚合证据支持、管理员能够处理的异常，不要把回放上限、没有对照组、可选字段为空、某规则未触发、全量基线可用等分析边界列为问题。summary、findings、suggestions 必须使用面向管理员的自然中文，不得暴露 JSON 键名、英文字段名或内部状态码。只能从 rule_catalog 建议整数阈值，不得建议关闭规则、修改动作、名单、代码或访问外部地址；证据不足时保持当前阈值。只输出 JSON：summary, health_score, findings[{severity,title,evidence,recommendation}], suggestions[{key,suggested_value,reason,confidence,risk,expected_impact}]。severity/risk 只能是 low/medium/high，confidence 为 0-1，最多 6 条建议。',
            ],
            ['role' => 'user', 'content' => $payload],
        ];
    }

    /**
     * @param array<string, mixed> $providerSettings
     * @param array<int, array{role:string, content:string}> $messages
     * @return array<string, mixed>
     */
    private function requestReview(array $providerSettings, array $messages): array
    {
        foreach ([3200, 4096] as $attempt => $maxTokens) {
            $attemptMessages = $messages;
            if ($attempt > 0 && isset($attemptMessages[0]['content'])) {
                $attemptMessages[0]['content'] .= ' 上一次响应无法解析。请重新生成一个完整 JSON 对象，不要使用 Markdown 代码块，不要添加任何解释文字，所有数组和字符串必须正确闭合。';
            }

            try {
                $completion = $this->provider()->complete(
                    array_merge($providerSettings, [
                        'json_mode' => true,
                        'temperature' => 0.1,
                        'max_tokens' => $maxTokens,
                    ]),
                    $attemptMessages,
                    false
                );
            } catch (TicketAiProviderException $exception) {
                if ($exception->errorCode() !== 'invalid_response' || $attempt === 1) {
                    throw $exception;
                }

                continue;
            }
            $decoded = $this->decode((string) ($completion['content'] ?? ''));
            if ($decoded !== null) {
                return $decoded;
            }
        }

        throw new RuntimeException('invalid_response');
    }

    /** @return array<string, mixed>|null */
    private function decode(string $content): ?array
    {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);
        $decoded = $this->decodeReviewObject($content);
        if ($decoded !== null) {
            return $decoded;
        }

        $wrapped = json_decode($content, true);
        if (is_string($wrapped)) {
            $decoded = $this->decodeReviewObject($wrapped);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        if (preg_match('/\x60{3}(?:json)?\s*(.*?)\s*\x60{3}/isu', $content, $matches) === 1) {
            $decoded = $this->decodeReviewObject((string) $matches[1]);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        $candidate = $this->firstBalancedObject($content);

        return $candidate === null ? null : $this->decodeReviewObject($candidate);
    }

    /** @return array<string, mixed>|null */
    private function decodeReviewObject(string $content): ?array
    {
        $decoded = json_decode(trim($content), true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        if (
            !is_string($decoded['summary'] ?? null)
            || trim((string) $decoded['summary']) === ''
            || !is_numeric($decoded['health_score'] ?? null)
        ) {
            return null;
        }
        foreach (['findings', 'suggestions'] as $field) {
            if (array_key_exists($field, $decoded) && !is_array($decoded[$field])) {
                return null;
            }
            $decoded[$field] ??= [];
        }

        return $decoded;
    }

    private function firstBalancedObject(string $content): ?string
    {
        $length = strlen($content);
        for ($start = 0; $start < $length; $start++) {
            if ($content[$start] !== '{') {
                continue;
            }

            $depth = 0;
            $quoted = false;
            $escaped = false;
            for ($index = $start; $index < $length; $index++) {
                $character = $content[$index];
                if ($quoted) {
                    if ($escaped) {
                        $escaped = false;
                        continue;
                    }
                    if ($character === '\\') {
                        $escaped = true;
                        continue;
                    }
                    if ($character === '"') {
                        $quoted = false;
                    }
                    continue;
                }

                if ($character === '"') {
                    $quoted = true;
                } elseif ($character === '{') {
                    $depth++;
                } elseif ($character === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($content, $start, $index - $start + 1);
                    }
                }
            }
        }

        return null;
    }
    /** @param array<int, mixed> $items @param array<string, mixed> $metrics */
    private function findings(array $items, array $metrics = []): array
    {
        $result = [];
        foreach (array_slice($items, 0, 8) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $finding = [
                'severity' => in_array($item['severity'] ?? null, ['low', 'medium', 'high'], true)
                    ? $item['severity']
                    : 'medium',
                'title' => mb_substr(trim((string) ($item['title'] ?? '')), 0, 160),
                'evidence' => mb_substr(trim((string) ($item['evidence'] ?? '')), 0, 800),
                'recommendation' => mb_substr(trim((string) ($item['recommendation'] ?? '')), 0, 800),
            ];
            if ($finding['title'] !== '' && !$this->isExpectedEvidenceLimitation($finding, $metrics)) {
                $result[] = $finding;
            }
        }

        return $result;
    }

    /** @param array<string, string> $finding @param array<string, mixed> $metrics */
    private function isExpectedEvidenceLimitation(array $finding, array $metrics): bool
    {
        $title = mb_strtolower((string) ($finding['title'] ?? ''));
        $text = mb_strtolower(implode(' ', [
            $title,
            (string) ($finding['evidence'] ?? ''),
            (string) ($finding['recommendation'] ?? ''),
        ]));
        $population = is_array($metrics['population'] ?? null) ? $metrics['population'] : [];
        $evidence = is_array($metrics['event_evidence'] ?? null) ? $metrics['event_evidence'] : [];
        $codeBreakdown = is_array($metrics['code_breakdown'] ?? null) ? $metrics['code_breakdown'] : [];
        $leakGuard = is_array($codeBreakdown['subscription_leak_guard'] ?? null)
            ? $codeBreakdown['subscription_leak_guard']
            : [];
        $limitationWords = ['缺少', '不足', '无法', '为空', '上限', '限制', '不完整', '未提供'];
        $mentionsLimitation = false;
        foreach ($limitationWords as $word) {
            if (str_contains($text, $word)) {
                $mentionsLimitation = true;
                break;
            }
        }

        if (($population['available'] ?? false) === true
            && str_contains($text, '全量用户基线')
            && ($mentionsLimitation || str_contains($title, '可用'))) {
            return true;
        }

        if (($evidence['full_window_aggregated'] ?? false) === true
            && $mentionsLimitation
            && (str_contains($text, '回放样本') || str_contains($text, 'replay_sample'))) {
            return true;
        }

        if ((int) ($leakGuard['event_count'] ?? 0) === 0
            && $mentionsLimitation
            && (str_contains($text, '泄露保护')
                || str_contains($text, 'risk_score')
                || str_contains($text, 'scored_event_count')
                || str_contains($text, 'average_risk_score')
                || str_contains($text, 'maximum_risk_score'))) {
            return true;
        }

        if ($mentionsLimitation
            && (str_contains($text, 'top_signals')
                || str_contains($text, '处置效果')
                || str_contains($text, '对照组')
                || str_contains($text, '未触发事件'))) {
            return true;
        }

        return false;
    }

    /** @param array<int, mixed> $items @param array<string, int> $config */
    private function suggestions(array $items, array $config): array
    {
        $result = [];
        $seen = [];
        foreach (array_slice($items, 0, 8) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            $value = $item['suggested_value'] ?? null;
            if (isset($seen[$key]) || !is_numeric($value) || !$this->allowed($key, (int) $value)) {
                continue;
            }
            $current = (int) ($config[$key] ?? 0);
            $value = (int) $value;
            if ($current === $value) {
                continue;
            }

            $seen[$key] = true;
            $result[] = [
                'id' => 'rule_' . substr(hash('sha256', $key . ':' . $value), 0, 12),
                'key' => $key,
                'label' => self::RULES[$key]['label'],
                'current_value' => $current,
                'suggested_value' => $value,
                'reason' => mb_substr(trim((string) ($item['reason'] ?? '')), 0, 1000),
                'confidence' => round(max(0, min(1, (float) ($item['confidence'] ?? 0))), 4),
                'risk' => in_array($item['risk'] ?? null, ['low', 'medium', 'high'], true)
                    ? $item['risk']
                    : 'medium',
                'expected_impact' => mb_substr(trim((string) ($item['expected_impact'] ?? '')), 0, 800),
                'requires_manual_review' => true,
            ];
        }

        return $result;
    }

    /** @param array<int, array<string, mixed>> $suggestions @param array<int, array<string, mixed>> $events */
    private function replay(array $suggestions, array $events, array $config): array
    {
        $result = [];
        foreach ($suggestions as $item) {
            $key = (string) $item['key'];
            $rule = self::RULES[$key];
            $values = [];
            foreach ($events as $event) {
                if ((string) ($event['code'] ?? '') !== $rule['code']) {
                    continue;
                }
                $value = $event[$rule['field']] ?? null;
                if (str_starts_with($rule['operator'], 'count_')) {
                    $values[] = is_array($value) ? count($value) : 0;
                } elseif (is_numeric($value)) {
                    $values[] = (int) $value;
                }
            }

            $current = (int) $config[$key];
            $proposed = (int) $item['suggested_value'];
            $currentHits = count(array_filter(
                $values,
                fn (int $value): bool => $this->matches($value, $current, $rule['operator'])
            ));
            $proposedHits = count(array_filter(
                $values,
                fn (int $value): bool => $this->matches($value, $proposed, $rule['operator'])
            ));
            $result[] = [
                'suggestion_id' => $item['id'],
                'sample_size' => count($values),
                'current_historical_hits' => $currentHits,
                'proposed_historical_hits' => $proposedHits,
                'hit_delta' => $proposedHits - $currentHits,
                'coverage' => 'triggered_events_only',
                'is_partial' => true,
            ];
        }

        return $result;
    }

    private function matches(int $value, int $threshold, string $operator): bool
    {
        return $operator === 'gte' ? $value >= $threshold : $value > $threshold;
    }

    private function allowed(string $key, int $value): bool
    {
        $rule = self::RULES[$key] ?? null;

        return $rule !== null && $value >= $rule['min'] && $value <= $rule['max'];
    }

    private function fail(SubscriptionControlAiReview $review, string $code): void
    {
        $review->forceFill([
            'status' => 'failed',
            'error_code' => mb_substr($code, 0, 64),
            'generated_at' => time(),
        ])->save();
    }

    private function tableAvailable(): bool
    {
        try {
            return Schema::hasTable(self::REVIEW_TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    private function provider(): TicketAiProviderClient
    {
        return $this->providerClient ?? new TicketAiProviderClient();
    }

    private function ai(): TicketAiAssistantService
    {
        return $this->aiSettings ?? new TicketAiAssistantService();
    }

    private function configService(): PluginConfigService
    {
        return $this->pluginConfig ?? new PluginConfigService();
    }
}

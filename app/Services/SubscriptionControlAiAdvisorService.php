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
            $metrics = $this->metrics($events, (int) $review->window_days);
            $providerSettings = $this->ai()->providerSettingsForInternalUse();
            if (
                !(bool) ($providerSettings['enabled'] ?? false)
                || trim((string) ($providerSettings['base_url'] ?? '')) === ''
                || trim((string) ($providerSettings['model'] ?? '')) === ''
                || trim((string) ($providerSettings['api_key'] ?? '')) === ''
            ) {
                throw new RuntimeException('ai_disabled');
            }

            $completion = $this->provider()->complete(
                array_merge($providerSettings, [
                    'json_mode' => true,
                    'temperature' => 0.1,
                    'max_tokens' => 1400,
                ]),
                $this->messages($config, $metrics),
                false
            );
            $decoded = $this->decode((string) ($completion['content'] ?? ''));
            if ($decoded === null) {
                throw new RuntimeException('invalid_response');
            }
            $suggestions = $this->suggestions((array) ($decoded['suggestions'] ?? []), $config);

            $review->forceFill([
                'status' => 'completed',
                'event_count' => count($events),
                'health_score' => max(0, min(100, (int) ($decoded['health_score'] ?? 0))),
                'summary' => mb_substr(trim((string) ($decoded['summary'] ?? '')), 0, 2000),
                'current_config' => $config,
                'metrics' => $metrics,
                'findings' => $this->findings((array) ($decoded['findings'] ?? [])),
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
            'created_at' => $review->created_at?->timestamp,
        ];
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
    private function metrics(array $events, int $days): array
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

        return [
            'window_days' => $days,
            'event_count' => count($events),
            'unique_affected_users' => count($users),
            'repeat_user_count' => count(array_filter($userHits, static fn (int $count): bool => $count > 1)),
            'pull_count' => $pulls,
            'allowed_count' => $allowed,
            'blocked_count' => $blocked,
            'block_rate' => $pulls > 0 ? round($blocked / $pulls, 4) : null,
            'code_counts' => array_slice($codes, 0, 12, true),
            'action_counts' => $actions,
            'top_signals' => array_slice($signals, 0, 12, true),
            'average_risk_score' => $riskScores === [] ? null : round(array_sum($riskScores) / count($riskScores), 2),
            'maximum_risk_score' => $riskScores === [] ? null : max($riskScores),
            'hosting_source_count' => $hosting,
            'proxy_source_count' => $proxy,
            'daily' => $daily,
            'data_limits' => [
                'events_are_triggered_only' => true,
                'maximum_events' => 5000,
                'personal_data_sent' => false,
            ],
        ];
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
                'historical_events_are_triggered_only' => true,
                'prefer_no_change_when_evidence_is_weak' => true,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return [
            [
                'role' => 'system',
                'content' => '你是订阅风控规则顾问。只分析匿名聚合统计，不直接执行封禁。只能从 rule_catalog 建议整数阈值，不得建议关闭规则、修改动作、名单、代码或访问外部地址。数据只含已触发事件，证据不足时不要调参。只输出 JSON：summary, health_score, findings[{severity,title,evidence,recommendation}], suggestions[{key,suggested_value,reason,confidence,risk,expected_impact}]。severity/risk 只能是 low/medium/high，confidence 为 0-1，最多 6 条建议。',
            ],
            ['role' => 'user', 'content' => $payload],
        ];
    }

    /** @return array<string, mixed>|null */
    private function decode(string $content): ?array
    {
        $content = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/isu', $content, $matches) === 1) {
            $content = trim((string) $matches[1]);
        }
        $decoded = json_decode($content, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /** @param array<int, mixed> $items */
    private function findings(array $items): array
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
            if ($finding['title'] !== '') {
                $result[] = $finding;
            }
        }

        return $result;
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
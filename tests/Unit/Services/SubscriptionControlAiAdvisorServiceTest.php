<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SubscriptionControlAiReview;
use App\Services\SubscriptionControlAiAdvisorService;
use ReflectionMethod;
use Tests\TestCase;

final class SubscriptionControlAiAdvisorServiceTest extends TestCase
{
    public function test_review_serialization_accepts_integer_timestamp_casts(): void
    {
        $review = new SubscriptionControlAiReview();
        $review->forceFill([
            'id' => 8,
            'status' => 'pending',
            'window_days' => 7,
            'event_count' => 0,
            'created_at' => 1786765527,
        ]);

        $serialized = (new SubscriptionControlAiAdvisorService())->serialize($review);

        $this->assertSame(1786765527, $serialized['created_at']);
    }

    public function test_anonymous_metrics_do_not_expose_personal_fields(): void
    {
        $metrics = $this->invoke('metrics', [[
            'user_id' => 42,
            'email' => 'private@example.test',
            'client_ip' => '203.0.113.9',
            'token' => 'secret-token',
            'uuid' => 'secret-uuid',
            'code' => 'subscription_leak_guard',
            'action' => 'reset_token_uuid',
            'risk_score' => 88,
            'signals' => ['low_usage', 'many_ips'],
            'ip_type' => 'hosting',
        ]], 7);

        $encoded = json_encode($metrics, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('private@example.test', $encoded);
        $this->assertStringNotContainsString('203.0.113.9', $encoded);
        $this->assertStringNotContainsString('secret-token', $encoded);
        $this->assertStringNotContainsString('secret-uuid', $encoded);
        $this->assertSame(1, $metrics['unique_affected_users']);
        $this->assertSame(1, $metrics['hosting_source_count']);
        $this->assertTrue($metrics['data_limits']['events_are_triggered_only']);
        $this->assertFalse($metrics['data_limits']['personal_data_sent']);
    }

    public function test_behavior_baseline_metrics_are_explicitly_observe_only(): void
    {
        $metrics = $this->invoke('metrics', [[
            'user_id' => 88,
            'code' => 'behavior_baseline_observation',
            'action' => 'observe',
            'signals' => ['behavior_new_region', 'behavior_combined_deviation'],
        ]], 7, [
            'event_evidence' => [
                'total_event_count' => 4,
                'unique_affected_users' => 2,
                'repeat_affected_users' => 1,
                'code_breakdown' => [
                    'behavior_baseline_observation' => [
                        'event_count' => 4,
                        'affected_users' => 2,
                        'repeat_affected_users' => 1,
                    ],
                ],
                'full_window_aggregated' => true,
            ],
        ]);

        $this->assertSame('observe_only', $metrics['behavior_baseline']['mode']);
        $this->assertSame(4, $metrics['behavior_baseline']['event_count']);
        $this->assertSame(2, $metrics['behavior_baseline']['affected_users']);
        $this->assertSame(1, $metrics['behavior_baseline']['repeat_affected_users']);
        $this->assertSame(0, $metrics['behavior_baseline']['enforcement_count']);
        $this->assertTrue($metrics['data_limits']['behavior_baseline_is_observe_only']);
        $this->assertTrue($metrics['data_limits']['behavior_baseline_never_enforces']);
    }

    public function test_full_population_and_full_window_evidence_override_replay_sample_counts(): void
    {
        $metrics = $this->invoke('metrics', [[
            'user_id' => 7,
            'email' => 'sample@example.test',
            'client_ip' => '198.51.100.20',
            'code' => 'sample_only',
            'action' => 'allow',
            'signals' => ['sample_signal'],
        ]], 7, [
            'population' => [
                'available' => true,
                'total_users' => 1000,
                'active_users' => 800,
                'excluded_accounts' => 'administrators_and_staff',
            ],
            'event_evidence' => [
                'available' => true,
                'total_event_count' => 32000,
                'unique_affected_users' => 20,
                'repeat_affected_users' => 8,
                'code_counts' => ['subscription_leak_guard' => 12000],
                'action_counts' => ['reset_token_uuid' => 300],
                'code_breakdown' => [
                    'subscription_leak_guard' => [
                        'event_count' => 12000,
                        'affected_users' => 20,
                        'repeat_affected_users' => 8,
                        'field_event_counts' => [
                            'risk_score' => 12000,
                            'ip_count' => 11800,
                            'ua_categories' => 11500,
                            'regions' => 11000,
                        ],
                        'field_distributions' => [
                            'risk_score' => [
                                'sample_count' => 12000,
                                'p50' => 70,
                                'p90' => 88,
                                'p95' => 92,
                                'scope' => 'triggered_events_only',
                            ],
                        ],
                        'post_action_outcome' => [
                            'eligible_pairs' => 18,
                            'repeat_within_horizon_pairs' => 4,
                            'quiet_after_horizon_pairs' => 14,
                            'repeat_within_horizon_rate' => 0.222222,
                        ],
                    ],
                ],
                'average_risk_score' => 72.5,
                'maximum_risk_score' => 96,
                'hosting_source_count' => 900,
                'proxy_source_count' => 120,
                'post_action_outcomes' => [
                    'available' => true,
                    'eligible_user_rule_pairs' => 18,
                    'repeat_within_horizon_pairs' => 4,
                    'quiet_after_horizon_pairs' => 14,
                    'interpretation' => 'absence_of_repeat_is_not_confirmed_recovery',
                ],
                'appeal_signals' => [
                    'available' => true,
                    'matching_ticket_count' => 3,
                    'matching_user_count' => 2,
                    'confirmed_false_positive' => false,
                    'personal_data_included' => false,
                ],
                'full_window_aggregated' => true,
            ],
        ]);

        $this->assertSame(32000, $metrics['event_count']);
        $this->assertSame(1, $metrics['sample_event_count']);
        $this->assertSame(20, $metrics['unique_affected_users']);
        $this->assertSame(8, $metrics['repeat_user_count']);
        $this->assertSame(0.02, $metrics['population']['affected_user_rate']);
        $this->assertSame(['subscription_leak_guard' => 12000], $metrics['code_counts']);
        $this->assertSame(1, $metrics['event_evidence']['replay_sample_count']);
        $this->assertFalse($metrics['operational_telemetry']['comparable_to_event_evidence']);
        $this->assertTrue($metrics['data_limits']['all_consumer_users_aggregated']);
        $this->assertTrue($metrics['data_limits']['event_totals_cover_full_window']);
        $this->assertSame('triggered_evidence_available', $metrics['rule_evidence']['leak_guard_score_threshold']['status']);
        $this->assertSame(12000, $metrics['rule_evidence']['leak_guard_score_threshold']['field_evidence_count']);
        $this->assertSame(88, $metrics['rule_evidence']['leak_guard_score_threshold']['triggered_value_distribution']['p90']);
        $this->assertSame(0.222222, $metrics['rule_evidence']['leak_guard_score_threshold']['post_action_outcome']['repeat_within_horizon_rate']);
        $this->assertSame(3, $metrics['appeal_signals']['matching_ticket_count']);
        $this->assertSame(18, $metrics['post_action_outcomes']['eligible_user_rule_pairs']);
        $this->assertTrue($metrics['data_limits']['field_distributions_are_triggered_only']);
        $this->assertTrue($metrics['data_limits']['quiet_after_horizon_is_not_confirmed_recovery']);
        $this->assertTrue($metrics['data_limits']['appeal_signals_are_inferred_not_confirmed']);
        $this->assertSame('not_triggered_in_window', $metrics['rule_evidence']['online_ip_threshold']['status']);

        $encoded = json_encode($metrics, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sample@example.test', $encoded);
        $this->assertStringNotContainsString('198.51.100.20', $encoded);
    }


    public function test_expected_analysis_boundaries_are_not_exposed_as_rule_problems(): void
    {
        $findings = $this->invoke('findings', [
            [
                'severity' => 'high',
                'title' => '无法评估泄露保护分数阈值',
                'evidence' => 'scored_event_count 为空，average_risk_score 无法计算。',
                'recommendation' => '补充风险分数据。',
            ],
            [
                'severity' => 'medium',
                'title' => '重复触发用户占比较高',
                'evidence' => '受影响用户中有一半重复触发。',
                'recommendation' => '复核重复触发最多的规则。',
            ],
            [
                'severity' => 'low',
                'title' => '全量用户基线可用',
                'evidence' => '全量用户基线已经加载。',
                'recommendation' => '继续观察。',
            ],
            [
                'severity' => 'medium',
                'title' => '回放样本达到上限',
                'evidence' => 'replay_sample_count 为 5000，样本受限。',
                'recommendation' => '扩大样本。',
            ],
            [
                'severity' => 'medium',
                'title' => '缺少处置效果对照',
                'evidence' => '无法判断处置效果。',
                'recommendation' => '增加对照组。',
            ],
        ], [
            'population' => ['available' => true],
            'event_evidence' => ['full_window_aggregated' => true],
            'code_breakdown' => [],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('重复触发用户占比较高', $findings[0]['title']);
    }

    public function test_suggestions_only_accept_allowlisted_in_range_changed_thresholds(): void
    {
        $config = [
            'leak_guard_score_threshold' => 70,
            'online_ip_threshold' => 10,
        ];
        $items = [
            ['key' => 'leak_guard_score_threshold', 'suggested_value' => 75, 'reason' => 'evidence', 'confidence' => 0.8, 'risk' => 'low'],
            ['key' => 'online_ip_threshold', 'suggested_value' => 10, 'reason' => 'same value'],
            ['key' => 'online_ip_threshold', 'suggested_value' => 1000, 'reason' => 'out of range'],
            ['key' => 'enable_leak_guard', 'suggested_value' => 0, 'reason' => 'switches are forbidden'],
            ['key' => 'ua_blacklist', 'suggested_value' => 1, 'reason' => 'lists are forbidden'],
        ];

        $suggestions = $this->invoke('suggestions', $items, $config);

        $this->assertCount(1, $suggestions);
        $this->assertSame('leak_guard_score_threshold', $suggestions[0]['key']);
        $this->assertSame(70, $suggestions[0]['current_value']);
        $this->assertSame(75, $suggestions[0]['suggested_value']);
        $this->assertTrue($suggestions[0]['requires_manual_review']);
    }

    public function test_replay_is_explicitly_partial_and_only_counts_matching_triggered_events(): void
    {
        $suggestions = [[
            'id' => 'rule-test',
            'key' => 'online_ip_threshold',
            'suggested_value' => 6,
        ]];
        $events = [
            ['code' => 'online_ip_threshold', 'online_ip_count' => 12],
            ['code' => 'online_ip_threshold', 'online_ip_count' => 8],
            ['code' => 'subscription_leak_guard', 'online_ip_count' => 99],
        ];

        $replay = $this->invoke('replay', $suggestions, $events, ['online_ip_threshold' => 10]);

        $this->assertCount(1, $replay);
        $this->assertSame(2, $replay[0]['sample_size']);
        $this->assertSame(1, $replay[0]['current_historical_hits']);
        $this->assertSame(2, $replay[0]['proposed_historical_hits']);
        $this->assertSame(1, $replay[0]['hit_delta']);
        $this->assertTrue($replay[0]['is_partial']);
        $this->assertSame('triggered_events_only', $replay[0]['coverage']);
    }

    public function test_review_request_retries_once_with_a_larger_output_budget(): void
    {
        $provider = new class extends \App\Services\TicketAiProviderClient
        {
            public array $calls = [];

            public function complete(array $settings, array $messages, bool $validateStructuredContent = true): array
            {
                $this->calls[] = ['settings' => $settings, 'messages' => $messages];

                return count($this->calls) === 1
                    ? ['content' => '{"summary":"被截断"']
                    : ['content' => '{"summary":"重试成功","health_score":91,"findings":[],"suggestions":[]}'];
            }
        };
        $service = new SubscriptionControlAiAdvisorService($provider);
        $method = new ReflectionMethod(SubscriptionControlAiAdvisorService::class, 'requestReview');
        $method->setAccessible(true);

        $decoded = $method->invoke($service, ['enabled' => true], [
            ['role' => 'system', 'content' => '只输出 JSON'],
            ['role' => 'user', 'content' => '{}'],
        ]);

        $this->assertSame('重试成功', $decoded['summary']);
        $this->assertCount(2, $provider->calls);
        $this->assertSame(3200, $provider->calls[0]['settings']['max_tokens']);
        $this->assertSame(4096, $provider->calls[1]['settings']['max_tokens']);
        $this->assertStringContainsString('上一次响应无法解析', $provider->calls[1]['messages'][0]['content']);
    }
    public function test_review_request_retries_an_empty_provider_response(): void
    {
        $provider = new class extends \App\Services\TicketAiProviderClient
        {
            public int $calls = 0;

            public function complete(array $settings, array $messages, bool $validateStructuredContent = true): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \App\Exceptions\TicketAiProviderException('invalid_response');
                }

                return ['content' => '{"summary":"空响应重试成功","health_score":90,"findings":[],"suggestions":[]}'];
            }
        };
        $service = new SubscriptionControlAiAdvisorService($provider);
        $method = new ReflectionMethod(SubscriptionControlAiAdvisorService::class, 'requestReview');
        $method->setAccessible(true);

        $decoded = $method->invoke($service, ['enabled' => true], [
            ['role' => 'system', 'content' => '只输出 JSON'],
            ['role' => 'user', 'content' => '{}'],
        ]);

        $this->assertSame('空响应重试成功', $decoded['summary']);
        $this->assertSame(2, $provider->calls);
    }
    public function test_review_json_decoder_accepts_common_provider_wrappers(): void
    {
        $json = '{"summary":"规则健康，说明中含有 {括号}","health_score":88,"findings":[],"suggestions":[]}';
        $fence = str_repeat(chr(96), 3);
        $cases = [
            $json,
            "{$fence}json\n{$json}\n{$fence}",
            "分析完成，结果如下：\n{$json}\n请人工确认。",
            json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            "\xEF\xBB\xBF{$json}",
        ];

        foreach ($cases as $content) {
            $decoded = $this->invoke('decode', $content);

            $this->assertIsArray($decoded);
            $this->assertSame(88, $decoded['health_score']);
            $this->assertSame('规则健康，说明中含有 {括号}', $decoded['summary']);
        }
    }

    public function test_review_json_decoder_rejects_truncated_or_wrong_schema_responses(): void
    {
        $this->assertNull($this->invoke('decode', '{"summary":"未闭合"'));
        $this->assertNull($this->invoke('decode', '{"message":"缺少必要字段"}'));
        $this->assertNull($this->invoke('decode', '{"summary":"结论","health_score":70,"findings":"none"}'));
    }
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(SubscriptionControlAiAdvisorService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new SubscriptionControlAiAdvisorService(), ...$arguments);
    }
}
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
                'average_risk_score' => 72.5,
                'maximum_risk_score' => 96,
                'hosting_source_count' => 900,
                'proxy_source_count' => 120,
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

        $encoded = json_encode($metrics, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sample@example.test', $encoded);
        $this->assertStringNotContainsString('198.51.100.20', $encoded);
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

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(SubscriptionControlAiAdvisorService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new SubscriptionControlAiAdvisorService(), ...$arguments);
    }
}
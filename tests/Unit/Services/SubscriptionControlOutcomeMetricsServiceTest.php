<?php

namespace Tests\Unit\Services;

use App\Services\SubscriptionControlOutcomeMetricsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionControlOutcomeMetricsServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    public function test_collects_triggered_distributions_and_post_action_outcomes(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createEventTable();
        $now = time();
        DB::table('v2_subscription_control_event')->insert([
            $this->event(1, 'subscription_leak_guard', 'reset_token_uuid', $now - 259200, 40, 1, ['a'], ['HK']),
            $this->event(1, 'subscription_leak_guard', 'reset_token_uuid', $now - 255600, 90, 3, ['a', 'b'], ['HK', 'JP']),
            $this->event(2, 'subscription_leak_guard', 'reset_token_uuid', $now - 172800, 70, 2, ['a'], ['US']),
            $this->event(3, 'source_batch_pull', 'block', $now - 172800, null, null, null, null, 8),
            $this->event(4, 'source_batch_pull', 'block', $now - 3600, null, null, null, null, 12),
        ]);

        $metrics = (new SubscriptionControlOutcomeMetricsService())->collect($now - 604800);

        $risk = $metrics['field_distributions']['subscription_leak_guard']['risk_score'];
        $this->assertSame(3, $risk['sample_count']);
        $this->assertSame(40, $risk['minimum']);
        $this->assertSame(70, $risk['p50']);
        $this->assertSame(90, $risk['p90']);
        $this->assertSame(2, $metrics['field_distributions']['subscription_leak_guard']['ua_categories']['p90']);

        $outcomes = $metrics['post_action_outcomes'];
        $this->assertSame(3, $outcomes['eligible_user_rule_pairs']);
        $this->assertSame(1, $outcomes['immature_user_rule_pairs']);
        $this->assertSame(1, $outcomes['repeat_within_horizon_pairs']);
        $this->assertSame(2, $outcomes['quiet_after_horizon_pairs']);
        $this->assertSame(0.333333, $outcomes['repeat_within_horizon_rate']);
        $this->assertSame('absence_of_repeat_is_not_confirmed_recovery', $outcomes['interpretation']);
    }

    public function test_source_ip_deny_attribution_is_anonymous_and_distinguishes_rule_sources(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createEventTable();
        $now = time();
        DB::table('v2_subscription_control_event')->insert([
            [
                'user_id' => 99,
                'code' => 'ua_blacklist',
                'action' => 'block',
                'client_ip' => '203.0.113.9',
                'source_ip_deny_match_type' => null,
                'source_ip_deny_match' => null,
                'ip_org' => 'Amazon Technologies Inc.',
                'created_at' => $now - 300000,
            ],
            [
                'user_id' => 1,
                'code' => 'source_ip_denylist',
                'action' => 'block',
                'client_ip' => '203.0.113.9',
                'source_ip_deny_match_type' => 'cidr',
                'source_ip_deny_match' => '203.0.113.9',
                'ip_org' => 'Amazon Technologies Inc.',
                'created_at' => $now - 200000,
            ],
            [
                'user_id' => 1,
                'code' => 'source_ip_denylist',
                'action' => 'block',
                'client_ip' => '203.0.113.9',
                'source_ip_deny_match_type' => 'cidr',
                'source_ip_deny_match' => '203.0.113.9',
                'ip_org' => 'Amazon Technologies Inc.',
                'created_at' => $now - 190000,
            ],
            [
                'user_id' => 2,
                'code' => 'source_ip_denylist',
                'action' => 'block',
                'client_ip' => '198.51.100.8',
                'source_ip_deny_match_type' => 'cidr',
                'source_ip_deny_match' => '198.51.0.0/16',
                'ip_org' => null,
                'created_at' => $now - 180000,
            ],
            [
                'user_id' => 3,
                'code' => 'source_ip_denylist',
                'action' => 'block',
                'client_ip' => '192.0.2.8',
                'source_ip_deny_match_type' => 'org',
                'source_ip_deny_match' => 'microsoft',
                'ip_org' => 'Microsoft Azure',
                'created_at' => $now - 170000,
            ],
        ]);

        $attribution = (new SubscriptionControlOutcomeMetricsService())
            ->collect($now - 604800)['source_ip_deny_attribution'];

        $this->assertTrue($attribution['available']);
        $this->assertSame(4, $attribution['total_event_count']);
        $this->assertSame(4, $attribution['attributed_event_count']);
        $this->assertSame(2, $attribution['source_class_counts']['automatic_ua_ip']['event_count']);
        $this->assertSame(1, $attribution['source_class_counts']['automatic_ua_ip']['repeat_affected_users']);
        $this->assertSame(1, $attribution['source_class_counts']['configured_cidr']['event_count']);
        $this->assertSame(1, $attribution['source_class_counts']['configured_organization']['event_count']);
        $this->assertSame(2, $attribution['provider_counts']['aws']['event_count']);
        $this->assertSame(1, $attribution['provider_counts']['azure']['event_count']);
        $this->assertSame(2, $attribution['prefix_scope_counts']['exact_ipv4']['event_count']);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{16}$/',
            $attribution['top_anonymous_rules'][0]['rule_fingerprint']
        );
        $this->assertFalse($attribution['exact_rule_values_included']);
        $this->assertFalse($attribution['personal_data_included']);

        $encoded = json_encode($attribution, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('203.0.113.9', $encoded);
        $this->assertStringNotContainsString('198.51.0.0/16', $encoded);
        $this->assertStringNotContainsString('Microsoft Azure', $encoded);
    }

    public function test_appeal_signals_only_return_anonymous_aggregate_counts(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createEventTable();
        $this->createTicketTables();
        $now = time();
        DB::table('v2_subscription_control_event')->insert([
            $this->event(11, 'subscription_leak_guard', 'reset_token_uuid', $now - 7200, 80),
        ]);
        DB::table('v2_ticket')->insert([
            ['id' => 1, 'user_id' => 11, 'subject' => '订阅风控误封', 'created_at' => $now - 3600],
            ['id' => 2, 'user_id' => 12, 'subject' => '普通使用问题', 'created_at' => $now - 3600],
        ]);
        DB::table('v2_ticket_message')->insert([
            ['ticket_id' => 1, 'user_id' => 11, 'message' => '订阅被重置了', 'created_at' => $now - 3500],
        ]);

        $signals = (new SubscriptionControlOutcomeMetricsService())
            ->collect($now - 604800)['appeal_signals'];

        $this->assertTrue($signals['available']);
        $this->assertSame(1, $signals['related_ticket_count']);
        $this->assertSame(1, $signals['matching_ticket_count']);
        $this->assertSame(1, $signals['matching_user_count']);
        $this->assertFalse($signals['confirmed_false_positive']);
        $this->assertFalse($signals['personal_data_included']);
        $this->assertStringNotContainsString('订阅被重置了', json_encode($signals, JSON_THROW_ON_ERROR));
    }

    private function createEventTable(): void
    {
        Schema::create('v2_subscription_control_event', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->string('code', 64);
            $table->string('action', 32);
            $table->string('client_ip', 64)->nullable();
            $table->string('ip_org', 191)->nullable();
            $table->string('source_ip_deny_match_type', 32)->nullable();
            $table->string('source_ip_deny_match', 191)->nullable();
            $table->integer('risk_score')->nullable();
            $table->integer('source_user_count')->nullable();
            $table->integer('online_ip_count')->nullable();
            $table->integer('ip_count')->nullable();
            $table->json('ua_categories')->nullable();
            $table->json('regions')->nullable();
            $table->json('online_regions')->nullable();
            $table->integer('created_at');
        });
    }

    private function createTicketTables(): void
    {
        Schema::create('v2_ticket', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('subject');
            $table->integer('created_at');
        });
        Schema::create('v2_ticket_message', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->integer('user_id');
            $table->text('message');
            $table->integer('created_at');
        });
    }

    /** @return array<string, mixed> */
    private function event(
        int $userId,
        string $code,
        string $action,
        int $createdAt,
        ?int $riskScore = null,
        ?int $ipCount = null,
        ?array $uaCategories = null,
        ?array $regions = null,
        ?int $sourceUserCount = null
    ): array {
        return [
            'user_id' => $userId,
            'code' => $code,
            'action' => $action,
            'risk_score' => $riskScore,
            'source_user_count' => $sourceUserCount,
            'online_ip_count' => null,
            'ip_count' => $ipCount,
            'ua_categories' => $uaCategories === null ? null : json_encode($uaCategories),
            'regions' => $regions === null ? null : json_encode($regions),
            'online_regions' => null,
            'created_at' => $createdAt,
        ];
    }
}

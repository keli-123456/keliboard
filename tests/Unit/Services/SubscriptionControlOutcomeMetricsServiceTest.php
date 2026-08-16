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

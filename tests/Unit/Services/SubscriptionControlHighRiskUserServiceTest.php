<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SubscriptionControlHighRiskUserService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionControlHighRiskUserServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        Cache::flush();

        Schema::create('v2_site', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('site_id')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
        });
        Schema::create('v2_subscription_control_event', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->string('code', 64);
            $table->text('reason')->nullable();
            $table->string('action', 32);
            $table->string('client_ip', 64)->nullable();
            $table->string('proxy_ip', 64)->nullable();
            $table->string('ua_category', 64)->nullable();
            $table->json('ua_categories')->nullable();
            $table->string('region', 128)->nullable();
            $table->json('regions')->nullable();
            $table->integer('online_ip_count')->nullable();
            $table->integer('threshold')->nullable();
            $table->integer('source_user_count')->nullable();
            $table->integer('risk_score')->nullable();
            $table->string('ip_type', 32)->nullable();
            $table->string('ip_org')->nullable();
            $table->json('signals')->nullable();
            $table->boolean('active_plan_user')->nullable();
            $table->bigInteger('used_traffic')->nullable();
            $table->bigInteger('transfer_enable')->nullable();
            $table->integer('created_at');
        });
    }

    public function test_collect_lists_only_high_risk_consumer_users_with_site_and_evidence(): void
    {
        $now = time();
        DB::table('v2_site')->insert(['id' => 5, 'name' => 'Pianyi']);
        DB::table('v2_user')->insert([
            ['id' => 10, 'site_id' => 5, 'email' => 'risk@example.test', 'is_admin' => 0, 'is_staff' => 0],
            ['id' => 11, 'site_id' => 5, 'email' => 'quiet@example.test', 'is_admin' => 0, 'is_staff' => 0],
            ['id' => 12, 'site_id' => null, 'email' => 'admin@example.test', 'is_admin' => 1, 'is_staff' => 0],
        ]);
        DB::table('v2_subscription_control_event')->insert([
            $this->event(10, 'source_ip_denylist', 'block', $now - 600, '203.0.113.8', 'scanner', 'SG'),
            $this->event(10, 'ua_reset', 'reset_token_uuid', $now - 500, '198.51.100.9', 'legacy', 'JP', 8, 3),
            $this->event(10, 'subscription_leak_guard', 'block', $now - 300, '198.51.100.10', 'script', 'US', null, null, [
                'source_user_count' => 12,
                'risk_score' => 92,
                'ip_type' => 'hosting',
                'ip_org' => 'Example Cloud',
                'signals' => json_encode(['many_pull_ips', 'active_plan_low_usage_with_many_ips', 'ip_intelligence_hosting']),
                'active_plan_user' => 1,
                'used_traffic' => 1024,
                'transfer_enable' => 107374182400,
            ]),
            $this->event(11, 'behavior_baseline_observation', 'observe', $now - 10, '192.0.2.1', 'client', 'US'),
            $this->event(12, 'source_ip_denylist', 'reset_token_uuid', $now - 5, '192.0.2.2', 'scanner', 'US'),
        ]);

        $result = (new SubscriptionControlHighRiskUserService())->collect(7, 20);

        $this->assertTrue($result['available']);
        $this->assertFalse($result['sent_to_ai']);
        $this->assertFalse($result['automatic_enforcement']);
        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame(10, $item['user_id']);
        $this->assertSame('risk@example.test', $item['email']);
        $this->assertSame(5, $item['site_id']);
        $this->assertSame('Pianyi', $item['site_name']);
        $this->assertSame('high', $item['risk_level']);
        $this->assertSame(3, $item['blocking_event_count']);
        $this->assertSame(1, $item['reset_count']);
        $this->assertContains('source_ip_denylist', $item['event_codes']);
        $this->assertContains('203.0.113.8', $item['client_ips']);
        $this->assertContains('scanner', $item['ua_categories']);
        $this->assertGreaterThanOrEqual(75, $item['suspicion_score']);
        $this->assertSame('high', $item['confidence']);
        $this->assertSame('probable_subscription_leak', $item['verdict']);
        $this->assertSame(1, $item['post_reset_retrigger_count']);
        $this->assertContains('post_reset_retrigger', $item['case_evidence']);
        $this->assertContains('source_sharing', $item['case_evidence']);
        $this->assertContains('infrastructure_source', $item['case_evidence']);
        $this->assertNotEmpty($item['evidence_timeline']);
        $this->assertSame('subscription_leak_guard', $item['evidence_timeline'][0]['code']);
        $this->assertTrue($item['requires_manual_review']);
        $this->assertFalse($item['automatic_enforcement']);
    }

    public function testDenylistOnlyEventsRemainLowConfidence(): void
    {
        $now = time();
        DB::table('v2_user')->insert([
            'id' => 20,
            'site_id' => null,
            'email' => 'shared-network@example.test',
            'is_admin' => 0,
            'is_staff' => 0,
        ]);
        DB::table('v2_subscription_control_event')->insert([
            $this->event(20, 'source_ip_denylist', 'block', $now - 120, '203.0.113.20', 'client', 'CN'),
            $this->event(20, 'source_ip_denylist', 'block', $now - 60, '203.0.113.20', 'client', 'CN'),
        ]);

        $result = (new SubscriptionControlHighRiskUserService())->collect(7, 20);
        $item = collect($result['items'])->firstWhere('user_id', 20);

        $this->assertIsArray($item);
        $this->assertLessThanOrEqual(45, $item['suspicion_score']);
        $this->assertSame('low', $item['confidence']);
        $this->assertSame('watch_required', $item['verdict']);
        $this->assertContains('denylist_only', $item['false_positive_factors']);
        $this->assertTrue($item['requires_manual_review']);
        $this->assertFalse($item['automatic_enforcement']);
    }

    /** @return array<string, mixed> */
    private function event(
        int $userId,
        string $code,
        string $action,
        int $createdAt,
        string $clientIp,
        string $uaCategory,
        string $region,
        ?int $onlineIpCount = null,
        ?int $threshold = null,
        array $extra = []
    ): array {
        return array_replace([
            'user_id' => $userId,
            'code' => $code,
            'reason' => $code,
            'action' => $action,
            'client_ip' => $clientIp,
            'proxy_ip' => null,
            'ua_category' => $uaCategory,
            'ua_categories' => json_encode([$uaCategory]),
            'region' => $region,
            'regions' => json_encode([$region]),
            'online_ip_count' => $onlineIpCount,
            'threshold' => $threshold,
            'source_user_count' => null,
            'risk_score' => null,
            'ip_type' => null,
            'ip_org' => null,
            'signals' => null,
            'active_plan_user' => null,
            'used_traffic' => null,
            'transfer_enable' => null,
            'created_at' => $createdAt,
        ], $extra);
    }
}

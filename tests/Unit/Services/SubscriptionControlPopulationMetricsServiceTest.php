<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SubscriptionControlPopulationMetricsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionControlPopulationMetricsServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    public function test_collect_uses_all_consumer_users_and_full_window_events(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->createAgentCenterTables();
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
            $table->json('signals')->nullable();
            $table->string('ip_type', 32)->nullable();
            $table->integer('created_at');
        });

        $now = time();
        DB::table('v2_site')->insert([
            'id' => 1,
            'code' => 'branch',
            'name' => 'Branch',
            'status' => 'active',
            'is_default' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('v2_user')->insert([
            [
                'id' => 1,
                'site_id' => 1,
                'plan_id' => 1,
                'transfer_enable' => 1000,
                'u' => 100,
                'd' => 100,
                'expired_at' => null,
                'banned' => 0,
                'is_admin' => 0,
                'is_staff' => 0,
                'last_login_at' => $now,
                'last_login_ip' => 101,
                'created_at' => $now - 100,
            ],
            [
                'id' => 2,
                'site_id' => 1,
                'plan_id' => 1,
                'transfer_enable' => 1000,
                'u' => 0,
                'd' => 0,
                'expired_at' => null,
                'banned' => 1,
                'is_admin' => 0,
                'is_staff' => 0,
                'last_login_at' => $now,
                'last_login_ip' => 101,
                'created_at' => $now - 200,
            ],
            [
                'id' => 3,
                'site_id' => null,
                'plan_id' => 1,
                'transfer_enable' => 1000,
                'u' => 0,
                'd' => 0,
                'expired_at' => null,
                'banned' => 0,
                'is_admin' => 1,
                'is_staff' => 0,
                'last_login_at' => $now,
                'last_login_ip' => 102,
                'created_at' => $now - 300,
            ],
            [
                'id' => 4,
                'site_id' => null,
                'plan_id' => 1,
                'transfer_enable' => 1000,
                'u' => 50,
                'd' => 50,
                'expired_at' => null,
                'banned' => 0,
                'is_admin' => 0,
                'is_staff' => 0,
                'last_login_at' => $now,
                'last_login_ip' => 103,
                'created_at' => $now - 400,
            ],
        ]);
        DB::table('v2_agent_user')->insert([
            'agent_user_id' => 99,
            'sub_user_id' => 4,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('v2_subscription_control_event')->insert([
            [
                'user_id' => 1,
                'code' => 'subscription_leak_guard',
                'action' => 'reset_token_uuid',
                'risk_score' => 90,
                'ip_type' => 'hosting',
                'created_at' => $now - 10,
            ],
            [
                'user_id' => 1,
                'code' => 'subscription_leak_guard',
                'action' => 'observe',
                'risk_score' => 60,
                'ip_type' => 'hosting',
                'created_at' => $now - 20,
            ],
            [
                'user_id' => 4,
                'code' => 'multi_region_pull',
                'action' => 'observe',
                'risk_score' => null,
                'ip_type' => 'proxy',
                'created_at' => $now - 30,
            ],
        ]);

        $metrics = (new SubscriptionControlPopulationMetricsService())->collect(7);

        $this->assertSame(3, $metrics['population']['total_users']);
        $this->assertSame(2, $metrics['population']['active_users']);
        $this->assertSame(1, $metrics['population']['banned_users']);
        $this->assertSame(1, $metrics['population']['agent_downline_users']);
        $this->assertSame(3, $metrics['event_evidence']['total_event_count']);
        $this->assertSame(2, $metrics['event_evidence']['unique_affected_users']);
        $this->assertSame(1, $metrics['event_evidence']['repeat_affected_users']);
        $this->assertSame(1, $metrics['event_evidence']['agent_affected_users']);
        $this->assertSame(2, $metrics['event_evidence']['code_counts']['subscription_leak_guard']);
        $this->assertSame(2, $metrics['event_evidence']['hosting_source_count']);
        $this->assertSame(1, $metrics['event_evidence']['proxy_source_count']);
        $this->assertSame(2, $metrics['event_evidence']['code_breakdown']['subscription_leak_guard']['event_count']);
        $this->assertSame(1, $metrics['event_evidence']['code_breakdown']['subscription_leak_guard']['affected_users']);
        $this->assertSame(1, $metrics['event_evidence']['code_breakdown']['subscription_leak_guard']['repeat_affected_users']);
        $this->assertSame(2, $metrics['event_evidence']['code_breakdown']['subscription_leak_guard']['field_event_counts']['risk_score']);
        $this->assertSame(0, $metrics['event_evidence']['code_breakdown']['multi_region_pull']['field_event_counts']['regions']);

        $encoded = json_encode($metrics, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('last_login_ip', $encoded);
        $this->assertStringNotContainsString('101', $encoded);
    }
}

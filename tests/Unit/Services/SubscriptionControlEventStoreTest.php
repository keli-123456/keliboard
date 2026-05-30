<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Database\Schema\Blueprint;
use Plugin\SubscriptionControl\Services\SubscriptionControlEventStore;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionControlEventStoreTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createEventTable();
    }

    public function test_appends_event_and_prunes_records_older_than_three_days(): void
    {
        $now = time();
        $old = $now - (4 * 86400);

        $this->database->table('v2_subscription_control_event')->insert([
            'event_id' => 'old-event',
            'user_id' => 1,
            'email' => 'old@example.com',
            'code' => 'subscription_leak_guard',
            'reason' => 'old',
            'action' => 'empty',
            'client_ip' => '1.1.1.1',
            'proxy_ip' => null,
            'client_ip_source' => null,
            'trusted_proxy' => null,
            'cf_ray' => null,
            'user_agent' => null,
            'ua_category' => null,
            'ua_categories' => null,
            'region' => null,
            'regions' => null,
            'online_regions' => null,
            'online_ip_count' => null,
            'source_user_count' => null,
            'source_user_threshold' => null,
            'ip_count' => null,
            'risk_score' => null,
            'score_threshold' => null,
            'hit_count' => null,
            'signals' => null,
            'active_plan_user' => null,
            'used_traffic' => null,
            'transfer_enable' => null,
            'threshold' => null,
            'cooldown_hit' => false,
            'email_sent' => false,
            'telegram_sent' => false,
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $store = new SubscriptionControlEventStore();
        $store->append([
            'id' => 'new-event',
            'user_id' => 2,
            'email' => 'new@example.com',
            'code' => 'subscription_leak_guard',
            'reason' => 'new',
            'action' => 'empty',
            'client_ip' => '2.2.2.2',
            'ip_asn' => 45090,
            'ip_type' => 'hosting',
            'ip_risk_tags' => ['cloud_provider'],
            'ua_categories' => ['script', 'unknown'],
            'signals' => ['risky_ua'],
            'created_at' => $now,
        ]);

        $events = $store->recent(10);

        $this->assertCount(1, $events);
        $this->assertSame('new-event', $events[0]['id']);
        $this->assertSame(45090, $events[0]['ip_asn']);
        $this->assertSame('hosting', $events[0]['ip_type']);
        $this->assertSame(['cloud_provider'], $events[0]['ip_risk_tags']);
        $this->assertSame(['script', 'unknown'], $events[0]['ua_categories']);
        $this->assertSame(['risky_ua'], $events[0]['signals']);
        $this->assertSame(1, $this->database->table('v2_subscription_control_event')->count());
    }

    public function test_recent_can_filter_by_email(): void
    {
        $store = new SubscriptionControlEventStore();
        $store->append([
            'id' => 'first',
            'user_id' => 1,
            'email' => 'first@example.com',
            'code' => 'source_batch_pull',
            'reason' => 'batch',
            'action' => 'observe',
            'created_at' => 100,
        ]);
        $store->append([
            'id' => 'second',
            'user_id' => 2,
            'email' => 'second@example.com',
            'code' => 'source_batch_pull',
            'reason' => 'batch',
            'action' => 'observe',
            'created_at' => 200,
        ]);

        $events = $store->recent(10, 'second');

        $this->assertCount(1, $events);
        $this->assertSame('second', $events[0]['id']);
    }

    private function createEventTable(): void
    {
        $this->database->schema()->create('v2_subscription_control_event', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('event_id', 64)->unique();
            $table->integer('user_id')->nullable()->index();
            $table->string('email', 191)->nullable()->index();
            $table->string('code', 64)->index();
            $table->text('reason');
            $table->string('action', 32)->index();
            $table->string('client_ip', 64)->nullable()->index();
            $table->string('proxy_ip', 64)->nullable();
            $table->string('client_ip_source', 64)->nullable();
            $table->boolean('trusted_proxy')->nullable();
            $table->string('cf_ray', 128)->nullable();
            $table->integer('ip_asn')->nullable();
            $table->string('ip_prefix', 128)->nullable();
            $table->string('ip_country', 8)->nullable();
            $table->string('ip_registry', 32)->nullable();
            $table->string('ip_org', 191)->nullable();
            $table->string('ip_type', 32)->nullable();
            $table->json('ip_risk_tags')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ua_category', 64)->nullable();
            $table->json('ua_categories')->nullable();
            $table->string('region', 128)->nullable();
            $table->json('regions')->nullable();
            $table->json('online_regions')->nullable();
            $table->integer('online_ip_count')->nullable();
            $table->integer('source_user_count')->nullable();
            $table->integer('source_user_threshold')->nullable();
            $table->integer('ip_count')->nullable();
            $table->integer('risk_score')->nullable();
            $table->integer('score_threshold')->nullable();
            $table->integer('hit_count')->nullable();
            $table->json('signals')->nullable();
            $table->boolean('active_plan_user')->nullable();
            $table->bigInteger('used_traffic')->nullable();
            $table->bigInteger('transfer_enable')->nullable();
            $table->integer('threshold')->nullable();
            $table->boolean('cooldown_hit')->default(false);
            $table->boolean('email_sent')->default(false);
            $table->boolean('telegram_sent')->default(false);
            $table->integer('created_at')->index();
            $table->integer('updated_at');
        });
    }
}

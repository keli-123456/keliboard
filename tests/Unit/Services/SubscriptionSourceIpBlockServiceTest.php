<?php

namespace Tests\Unit\Services;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Plugin\SubscriptionControl\Services\SubscriptionControlEventStore;
use Plugin\SubscriptionControl\Services\SubscriptionSourceIpBlockService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class SubscriptionSourceIpBlockServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createUserTable();
        $this->createPluginTable();
        $this->createEventTable();
    }

    public function test_list_enriches_ua_blacklist_ip_blocks_from_recent_events(): void
    {
        Plugin::query()->create([
            'code' => 'subscription_control',
            'config' => json_encode([
                'source_ip_deny_cidrs' => "203.0.113.9\n198.51.100.0/24",
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $user = User::create([
            'email' => 'blocked@example.com',
            'password' => 'x',
            'token' => 'token',
            'uuid' => 'uuid',
        ]);
        $now = time();
        $store = new SubscriptionControlEventStore();
        $store->append([
            'user_id' => $user->id,
            'email' => $user->email,
            'code' => 'ua_blacklist',
            'reason' => 'UA 黑名单命中',
            'action' => 'block',
            'client_ip' => '203.0.113.9',
            'client_ip_source' => 'cf_connecting_ip',
            'proxy_ip' => '103.14.76.98',
            'user_agent' => 'TelegramBot',
            'ip_type' => 'hosting',
            'ip_asn' => 'AS64500',
            'ip_org' => 'Example Cloud',
            'region' => 'US',
            'created_at' => $now - 100,
        ]);
        $store->append([
            'user_id' => $user->id,
            'email' => $user->email,
            'code' => 'ua_blacklist',
            'reason' => 'UA 黑名单命中',
            'action' => 'block',
            'client_ip' => '203.0.113.9',
            'user_agent' => 'TelegramBot',
            'created_at' => $now - 50,
        ]);

        $items = (new SubscriptionSourceIpBlockService())->list()['items'];

        $this->assertCount(2, $items);
        $blocked = $items[0];
        $this->assertSame('203.0.113.9', $blocked['entry']);
        $this->assertSame('ua_blacklist', $blocked['source_type']);
        $this->assertSame('blocked@example.com', $blocked['last_email']);
        $this->assertSame('TelegramBot', $blocked['last_user_agent']);
        $this->assertSame(2, $blocked['event_count']);
        $this->assertSame($now - 100, $blocked['first_seen_at']);
        $this->assertSame($now - 50, $blocked['last_seen_at']);
        $this->assertSame('hosting', $blocked['ip_type']);
        $this->assertFalse($blocked['node_synced']);
    }

    public function test_unblock_removes_only_exact_entry_and_preserves_other_cidrs(): void
    {
        Plugin::query()->create([
            'code' => 'subscription_control',
            'config' => json_encode([
                'enable_source_ip_denylist' => true,
                'source_ip_deny_cidrs' => "203.0.113.9\n198.51.100.0/24\n203.0.113.10",
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $result = (new SubscriptionSourceIpBlockService())->unblock('198.51.100.0/24');

        $this->assertTrue($result['removed']);
        $this->assertSame('198.51.100.0/24', $result['entry']);
        $config = json_decode((string) Plugin::query()->where('code', 'subscription_control')->value('config'), true);
        $this->assertSame("203.0.113.9\n203.0.113.10", $config['source_ip_deny_cidrs']);
        $this->assertTrue($config['enable_source_ip_denylist']);
    }

    private function createPluginTable(): void
    {
        $this->database->schema()->create('v2_plugins', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code')->unique();
            $table->text('config')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
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

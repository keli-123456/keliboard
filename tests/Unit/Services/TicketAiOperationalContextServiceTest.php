<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\TicketAiOperationalContextService;
use App\Utils\CacheKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiOperationalContextServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindTestSettings(['subscription_proxy_enable' => true]);
        $this->createServerMachineTable();
        $this->createServerTable();
        $this->createDomainHealthTable();
        $this->createIncidentTable();
    }

    public function test_builds_healthy_privacy_safe_snapshot_for_current_tenant(): void
    {
        $now = time();
        DB::table('v2_server_machine')->insert([
            'id' => 1,
            'is_active' => 1,
            'subproxy_enabled' => 1,
            'last_seen_at' => $now,
            'load_status' => json_encode(['agent' => ['subscription_proxy' => ['running' => true, 'mode' => 'https']]]),
            'subproxy_cert_state' => json_encode(['probe' => [
                'status' => 'ok',
                'last_checked_at' => $now,
                'last_success_at' => $now,
            ]]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('v2_server')->insert([
            'id' => 7,
            'type' => 'vless',
            'parent_id' => null,
            'group_ids' => json_encode(['3']),
            'enabled' => 1,
            'show' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Cache::put(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', 7), $now);
        Cache::put(CacheKey::get('SERVER_VLESS_LAST_PUSH_AT', 7), $now);
        DB::table('v2_domain_health')->insert([
            'domain' => 'tenant.example.test',
            'monitored' => 1,
            'status' => 'healthy',
            'last_checked_at' => $now,
            'last_success_at' => $now,
        ]);
        DB::table('v2_domain_health')->insert([
            'domain' => 'other.example.test',
            'monitored' => 1,
            'status' => 'down',
            'reason' => 'tls_failed',
            'last_checked_at' => $now,
        ]);

        $user = new User();
        $user->group_id = 3;
        $context = (new TicketAiOperationalContextService())->build(
            $user,
            ['type' => 'site', 'site_id' => 8, 'domain' => 'tenant.example.test'],
            [['role' => 'user', 'content' => '订阅导入后节点不能用，网站也打不开']],
            '客户端连接问题'
        );
        $serialized = json_encode($context, JSON_UNESCAPED_UNICODE);

        $this->assertSame('healthy', $context['status']);
        $this->assertFalse($context['requires_human']);
        $this->assertSame('healthy', $context['tools']['subscription_proxy']['status']);
        $this->assertSame('healthy', $context['tools']['eligible_nodes']['status']);
        $this->assertSame('healthy', $context['tools']['tenant_domain']['status']);
        $this->assertStringNotContainsString('tenant.example.test', $serialized);
        $this->assertStringNotContainsString('other.example.test', $serialized);
        $this->assertStringContainsString('只能证明当前状态', $context['customer_safe_summary']);
    }

    public function test_unavailable_subscription_proxy_requires_human_without_exposing_endpoint(): void
    {
        $now = time();
        DB::table('v2_server_machine')->insert([
            'id' => 1,
            'is_active' => 1,
            'subproxy_enabled' => 1,
            'last_seen_at' => $now,
            'load_status' => json_encode(['agent' => ['subscription_proxy' => ['running' => true, 'mode' => 'https']]]),
            'subproxy_cert_state' => json_encode(['probe' => [
                'status' => 'error',
                'url' => 'https://secret.example.test/sub/site/private-token',
                'last_checked_at' => $now,
                'last_success_at' => $now - 600,
            ]]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $context = (new TicketAiOperationalContextService())->build(
            null,
            ['type' => 'platform', 'domain' => 'main.example.test'],
            [['role' => 'user', 'content' => '订阅链接提示 Network Error']],
            ''
        );
        $serialized = json_encode($context, JSON_UNESCAPED_UNICODE);

        $this->assertSame('unavailable', $context['status']);
        $this->assertTrue($context['requires_human']);
        $this->assertSame(1, $context['tools']['subscription_proxy']['configured_count']);
        $this->assertSame(0, $context['tools']['subscription_proxy']['healthy_count']);
        $this->assertStringContainsString('订阅加速通道', $context['customer_safe_summary']);
        $this->assertStringNotContainsString('secret.example.test', $serialized);
        $this->assertStringNotContainsString('private-token', $serialized);
    }

    public function test_current_critical_infrastructure_incident_is_scoped_and_forces_review(): void
    {
        $now = time();
        DB::table('v2_ai_diagnostic_incident')->insert([
            'fingerprint' => str_repeat('a', 64),
            'scope_key' => 'site:12',
            'scope_type' => 'site',
            'site_id' => 12,
            'finding_key' => 'infrastructure_nodes_offline',
            'module' => 'infrastructure',
            'severity' => 'critical',
            'status' => 'open',
            'last_seen_at' => $now,
        ]);
        DB::table('v2_ai_diagnostic_incident')->insert([
            'fingerprint' => str_repeat('b', 64),
            'scope_key' => 'site:99',
            'scope_type' => 'site',
            'site_id' => 99,
            'finding_key' => 'infrastructure_domain_unhealthy',
            'module' => 'infrastructure',
            'severity' => 'critical',
            'status' => 'open',
            'last_seen_at' => $now,
        ]);

        $context = (new TicketAiOperationalContextService())->build(
            null,
            ['type' => 'site', 'site_id' => 12, 'domain' => 'site.example.test'],
            [['role' => 'user', 'content' => '节点全部连接不上']],
            ''
        );
        $serialized = json_encode($context, JSON_UNESCAPED_UNICODE);

        $this->assertSame('degraded', $context['status']);
        $this->assertTrue($context['requires_human']);
        $this->assertCount(1, $context['active_incidents']);
        $this->assertSame('节点离线', $context['active_incidents'][0]['label']);
        $this->assertStringNotContainsString('域名健康异常', $serialized);
    }

    private function createServerMachineTable(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->increments('id');
            $table->boolean('is_active')->default(true);
            $table->boolean('subproxy_enabled')->default(false);
            $table->integer('last_seen_at')->nullable();
            $table->text('load_status')->nullable();
            $table->text('subproxy_cert_state')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createServerTable(): void
    {
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type')->default('vless');
            $table->unsignedInteger('parent_id')->nullable();
            $table->text('group_ids')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('show')->default(true);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createDomainHealthTable(): void
    {
        Schema::create('v2_domain_health', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('domain')->unique();
            $table->boolean('monitored')->default(true);
            $table->string('status')->default('unknown');
            $table->string('reason')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->integer('last_checked_at')->nullable();
            $table->integer('last_success_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createIncidentTable(): void
    {
        Schema::create('v2_ai_diagnostic_incident', function (Blueprint $table): void {
            $table->increments('id');
            $table->char('fingerprint', 64)->unique();
            $table->string('scope_key');
            $table->string('scope_type');
            $table->unsignedInteger('site_id')->nullable();
            $table->string('finding_key');
            $table->string('module');
            $table->string('severity');
            $table->string('status');
            $table->integer('last_seen_at');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }
}

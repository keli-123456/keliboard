<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Http\Controllers\V2\Admin\DomainAnalyticsController;
use App\Models\DomainMetricDaily;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class DomainAnalyticsControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createTables();
    }

    public function test_overview_builds_funnel_and_excludes_agent_revenue_from_site_performance(): void
    {
        $now = time();
        DB::table('v2_site')->insert(['id' => 1, 'code' => 'site-one', 'name' => 'Site One', 'status' => 'active', 'is_default' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $this->metric('platform.example.test', null, null, 100, 20, 10, 8, 5, 1000);
        $this->metric('site.example.test', 1, null, 200, 40, 20, 12, 10, 2000);
        $this->metric('agent.example.test', null, 99, 300, 60, 30, 20, 15, 9999);

        $controller = app(DomainAnalyticsController::class);
        $funnelMethod = new \ReflectionMethod($controller, 'funnel');
        $siteMethod = new \ReflectionMethod($controller, 'sitePerformance');
        $funnel = $funnelMethod->invoke($controller, [
            'page_views' => 600, 'unique_visitors' => 120, 'registrations' => 60,
            'orders_created' => 40, 'orders_paid' => 30, 'revenue_amount' => 12999,
            'subscription_pulls' => 0,
        ]);
        $sites = $siteMethod->invoke($controller, DomainMetricDaily::query());

        $this->assertCount(4, $funnel);
        $this->assertSame('visitors', $funnel[0]['key']);
        $this->assertSame(120, $funnel[0]['value']);
        $this->assertSame(50.0, $funnel[1]['rate']);
        $this->assertCount(2, $sites);
        $this->assertSame(3000, array_sum(array_column($sites, 'revenue_amount')));
    }

    private function metric(string $host, ?int $siteId, ?int $agentId, int $views, int $visitors, int $registrations, int $created, int $paid, int $revenue): void
    {
        DB::table('v2_domain_metric_daily')->insert([
            'record_date' => date('Y-m-d'), 'host' => $host, 'site_id' => $siteId, 'site_domain_id' => null,
            'agent_user_id' => $agentId, 'agent_domain_id' => null, 'page_views' => $views,
            'unique_visitors' => $visitors, 'registrations' => $registrations, 'orders_created' => $created,
            'orders_paid' => $paid, 'revenue_amount' => $revenue, 'subscription_pulls' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function createTables(): void
    {
        $this->database->schema()->create('v2_site', function (Blueprint $table): void {
            $table->increments('id'); $table->string('code'); $table->string('name'); $table->string('status');
            $table->boolean('is_default')->default(false); $table->integer('created_at'); $table->integer('updated_at');
        });
        $this->database->schema()->create('v2_domain_metric_daily', function (Blueprint $table): void {
            $table->bigIncrements('id'); $table->string('record_date', 10); $table->string('host');
            $table->integer('site_id')->nullable(); $table->integer('site_domain_id')->nullable();
            $table->integer('agent_user_id')->nullable(); $table->integer('agent_domain_id')->nullable();
            foreach (['page_views', 'unique_visitors', 'registrations', 'orders_created', 'orders_paid', 'revenue_amount', 'subscription_pulls'] as $column) $table->unsignedBigInteger($column)->default(0);
            $table->integer('created_at'); $table->integer('updated_at');
        });
    }
}

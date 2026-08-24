<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DomainMetricDaily;
use App\Services\DomainAnalyticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class DomainAnalyticsServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        config(['app.key' => 'domain-analytics-test-key']);
        $this->createContextTables();
        $this->createAnalyticsTables();
    }

    public function test_page_views_are_counted_while_visitors_are_anonymized_and_deduplicated(): void
    {
        $service = app(DomainAnalyticsService::class);
        $request = $this->request('203.0.113.8', 'Mozilla/5.0 Test Browser');

        $service->recordPageView($request);
        $service->recordPageView($request);
        $service->recordPageView($this->request('203.0.113.9', 'Mozilla/5.0 Another Browser'));

        $metric = DomainMetricDaily::query()->firstOrFail();
        $this->assertSame('dash.example.test', $metric->host);
        $this->assertSame(3, (int) $metric->page_views);
        $this->assertSame(2, (int) $metric->unique_visitors);
        $this->assertSame(2, DB::table('v2_domain_visitor_daily')->count());
        $this->assertFalse($this->database->schema()->hasColumn('v2_domain_visitor_daily', 'ip'));
        $this->assertFalse($this->database->schema()->hasColumn('v2_domain_visitor_daily', 'user_agent'));
        $this->assertStringNotContainsString('203.0.113.8', (string) DB::table('v2_domain_visitor_daily')->value('visitor_hash'));
    }

    public function test_business_events_share_the_normalized_domain_bucket(): void
    {
        $service = app(DomainAnalyticsService::class);
        $request = $this->request('203.0.113.8', 'Subscription Client');
        $request->headers->set('X-Forwarded-Host', 'DASH.EXAMPLE.TEST:443');

        $service->recordRegistration($request);
        $service->recordSubscriptionPull($request);

        $metric = DomainMetricDaily::query()->firstOrFail();
        $this->assertSame('dash.example.test', $metric->host);
        $this->assertSame(1, (int) $metric->registrations);
        $this->assertSame(1, (int) $metric->subscription_pulls);
    }

    private function request(string $ip, string $userAgent): Request
    {
        return Request::create('/dashboard', 'POST', [], [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_HOST' => 'dash.example.test',
            'HTTP_USER_AGENT' => $userAgent,
            'HTTP_ACCEPT_LANGUAGE' => 'zh-CN',
        ]);
    }

    private function createContextTables(): void
    {
        $this->database->schema()->create('v2_site', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('active');
            $table->boolean('is_default')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->database->schema()->create('v2_site_domain', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('site_id');
            $table->string('domain')->unique();
            $table->string('status')->default('active');
            $table->boolean('is_primary')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->database->schema()->create('v2_agent_domain', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id');
            $table->string('domain')->unique();
            $table->string('status')->default('active');
            $table->boolean('is_primary')->default(false);
        });
    }

    private function createAnalyticsTables(): void
    {
        $this->database->schema()->create('v2_domain_metric_daily', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->date('record_date');
            $table->string('host', 191);
            $table->integer('site_id')->nullable();
            $table->integer('site_domain_id')->nullable();
            $table->integer('agent_user_id')->nullable();
            $table->integer('agent_domain_id')->nullable();
            foreach (['page_views', 'unique_visitors', 'registrations', 'orders_created', 'orders_paid', 'revenue_amount', 'subscription_pulls'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['record_date', 'host']);
        });
        $this->database->schema()->create('v2_domain_visitor_daily', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->date('record_date');
            $table->string('host', 191);
            $table->char('visitor_hash', 64);
            $table->integer('created_at')->nullable();
            $table->unique(['record_date', 'host', 'visitor_hash']);
        });
    }
}

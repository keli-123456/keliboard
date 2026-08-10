<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\DomainHealth;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteNavigation;
use App\Models\SiteNavigationDomain;
use App\Models\User;
use App\Services\DomainHealthMonitorService;
use App\Services\DomainHealthProbeService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class DomainHealthTargetDiscoveryTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->bindTestSettings([
            'app_url' => 'https://main.example.com',
            'app_name' => 'Main site',
        ]);
        $this->createDomainHealthTable();
        $this->createDomainSourceTables();
    }

    public function test_active_sources_are_monitored_and_disabled_domains_are_not(): void
    {
        $site = Site::query()->create([
            'code' => 'branch',
            'name' => 'Branch site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'branch.example.com',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'disabled.example.com',
            'status' => SiteDomain::STATUS_DISABLED,
            'is_primary' => false,
        ]);

        $agent = User::query()->create(['email' => 'agent@example.com']);
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.com',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);

        $navigation = SiteNavigation::query()->create([
            'scope_key' => 'nav',
            'site_id' => $site->id,
            'title' => 'Navigation',
            'enabled' => true,
        ]);
        SiteNavigationDomain::query()->create([
            'navigation_id' => $navigation->id,
            'domain' => 'nav.example.com',
            'status' => SiteNavigationDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);

        $monitor = new DomainHealthMonitorService(new DomainHealthProbeService());
        $monitor->synchronizeTargets();

        $this->assertSame(4, DomainHealth::query()->where('monitored', true)->count());
        $this->assertSame(DomainHealth::SOURCE_SITE, DomainHealth::query()->where('domain', 'branch.example.com')->value('source_type'));
        $this->assertSame(DomainHealth::SOURCE_AGENT, DomainHealth::query()->where('domain', 'agent.example.com')->value('source_type'));
        $this->assertSame(DomainHealth::SOURCE_NAVIGATION, DomainHealth::query()->where('domain', 'nav.example.com')->value('source_type'));
        $this->assertSame(DomainHealth::SOURCE_SYSTEM, DomainHealth::query()->where('domain', 'main.example.com')->value('source_type'));
        $this->assertFalse((bool) DomainHealth::query()->where('domain', 'disabled.example.com')->value('monitored'));
    }

    private function createDomainHealthTable(): void
    {
        $this->database->schema()->create('v2_domain_health', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('domain')->unique();
            $table->string('source_type');
            $table->integer('source_id')->nullable();
            $table->integer('owner_id')->nullable();
            $table->string('source_name')->nullable();
            $table->string('configured_status')->nullable();
            $table->boolean('monitored')->default(true);
            $table->string('status')->default('unknown');
            $table->string('reason')->nullable();
            $table->integer('http_status')->nullable();
            $table->integer('response_ms')->nullable();
            $table->json('dns_addresses')->nullable();
            $table->integer('certificate_expires_at')->nullable();
            $table->string('certificate_issuer')->nullable();
            $table->string('certificate_sha256')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->boolean('alert_active')->default(false);
            $table->integer('last_checked_at')->nullable();
            $table->integer('last_success_at')->nullable();
            $table->integer('last_failure_at')->nullable();
            $table->integer('alerted_at')->nullable();
            $table->integer('recovered_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createDomainSourceTables(): void
    {
        $this->database->schema()->create('v2_agent_domain', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id');
            $table->string('domain')->unique();
            $table->string('status')->default('pending');
            $table->boolean('is_primary')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->database->schema()->create('v2_site_navigation', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('scope_key')->unique();
            $table->integer('site_id')->nullable();
            $table->string('title')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->database->schema()->create('v2_site_navigation_domain', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('navigation_id');
            $table->string('domain')->unique();
            $table->string('status')->default('active');
            $table->boolean('is_primary')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }
}

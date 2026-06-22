<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\SiteResolver;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteResolverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
    }

    public function test_resolves_active_site_domain_ignoring_port_and_case(): void
    {
        $site = Site::query()->create([
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'cheap.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(SiteResolver::class)->resolveHost('CHEAP.EXAMPLE.TEST:443');

        $this->assertSame($site->id, $context['site_id']);
        $this->assertSame('cheap', $context['site_code']);
        $this->assertSame('cheap.example.test', $context['domain']);
        $this->assertSame('domain', $context['source']);
    }
}

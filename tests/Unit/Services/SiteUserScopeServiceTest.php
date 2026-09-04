<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SiteUserScopeService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteUserScopeServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
    }

    public function test_missing_request_fails_closed_when_tenant_schema_is_active(): void
    {
        $this->createSiteTenantTables();
        app()->forgetInstance('request');

        $this->expectException(\LogicException::class);
        app(SiteUserScopeService::class)->context();
    }

    public function test_legacy_schema_without_site_tables_remains_compatible(): void
    {
        app()->forgetInstance('request');

        $context = app(SiteUserScopeService::class)->context();

        $this->assertFalse($context['enabled']);
        $this->assertSame('legacy', $context['source']);
    }
}

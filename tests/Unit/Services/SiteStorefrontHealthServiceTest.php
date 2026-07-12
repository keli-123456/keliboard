<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\SiteStorefrontHealthService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteStorefrontHealthServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createSiteTenantTables();
    }

    public function test_reports_ready_when_an_active_domain_resolves_and_returns_its_site_context(): void
    {
        $site = Site::query()->create([
            'code' => 'branch',
            'name' => 'Branch',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'branch.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $health = new SiteStorefrontHealthService(
            dnsResolver: static fn (string $domain): array => $domain === 'branch.example.test' ? ['203.0.113.7'] : [],
            httpProbe: static fn (string $url): array => [
                'status' => 200,
                'payload' => ['site_context' => ['site_code' => 'branch']],
            ],
        );

        $result = $health->check($site->fresh('domains'));

        $this->assertSame('ready', $result['status']);
        $this->assertSame('ready', $result['domains'][0]['status']);
        $this->assertSame(['203.0.113.7'], $result['domains'][0]['addresses']);
        $this->assertSame(200, $result['domains'][0]['http_status']);
        $this->assertSame('branch', $result['domains'][0]['resolved_site_code']);
    }

    public function test_reports_warning_when_a_domain_responds_but_resolves_to_the_wrong_site(): void
    {
        $site = Site::query()->create([
            'code' => 'branch',
            'name' => 'Branch',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'branch.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $health = new SiteStorefrontHealthService(
            dnsResolver: static fn (): array => ['203.0.113.7'],
            httpProbe: static fn (): array => [
                'status' => 200,
                'payload' => ['site_context' => ['site_code' => 'platform']],
            ],
        );

        $result = $health->check($site->fresh('domains'));

        $this->assertSame('warning', $result['status']);
        $this->assertSame('warning', $result['domains'][0]['status']);
        $this->assertSame('site_context_mismatch', $result['domains'][0]['reason']);
    }
}

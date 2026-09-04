<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\SiteResolver;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteResolverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_HOST);

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
    }

    public function test_resolves_active_site_domain_ignoring_port_and_case(): void
    {
        $site = $this->createSite('cheap', 'Cheap Site');
        $this->createDomain($site, 'cheap.example.test');

        $context = app(SiteResolver::class)->resolveHost('CHEAP.EXAMPLE.TEST:443');

        $this->assertSame($site->id, $context['site_id']);
        $this->assertSame('cheap', $context['site_code']);
        $this->assertSame('cheap.example.test', $context['domain']);
        $this->assertSame('domain', $context['source']);
    }

    public function test_resolves_forwarded_host_from_trusted_proxy(): void
    {
        $site = $this->createSite('forwarded', 'Forwarded Site');
        $this->createDomain($site, 'forwarded.example.test');

        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'plain.example.test',
            'HTTP_X_FORWARDED_HOST' => 'Forwarded.Example.Test:8443',
        ]);
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST);

        $context = app(SiteResolver::class)->resolveRequest($request);

        $this->assertSame($site->id, $context['site_id']);
        $this->assertSame('forwarded.example.test', $context['domain']);
        $this->assertSame('domain', $context['source']);
    }

    public function test_ignores_forwarded_host_from_untrusted_client(): void
    {
        $site = $this->createSite('spoofed', 'Spoofed Site');
        $this->createDomain($site, 'spoofed.example.test');

        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.25',
            'HTTP_HOST' => 'plain.example.test',
            'HTTP_X_FORWARDED_HOST' => 'spoofed.example.test',
        ]);
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST);

        $context = app(SiteResolver::class)->resolveRequest($request);

        $this->assertNull($context['site_id']);
        $this->assertSame('platform', $context['source']);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_HOST);
        parent::tearDown();
    }

    public function test_disabled_domain_falls_back_to_platform_context(): void
    {
        $site = $this->createSite('disabled', 'Disabled Site');
        $this->createDomain($site, 'disabled.example.test', SiteDomain::STATUS_DISABLED);

        $context = app(SiteResolver::class)->resolveHost('disabled.example.test');

        $this->assertNull($context['site_id']);
        $this->assertSame('platform', $context['site_code']);
        $this->assertNull($context['site_domain_id']);
        $this->assertSame('platform', $context['source']);
    }

    public function test_unmatched_host_does_not_create_default_site(): void
    {
        $context = app(SiteResolver::class)->resolveHost('missing.example.test');

        $this->assertNull(Site::query()->where('code', 'default')->first());
        $this->assertNull($context['site_id']);
        $this->assertSame('platform', $context['site_code']);
        $this->assertSame('', $context['site_name']);
        $this->assertFalse($context['is_default']);
        $this->assertSame('platform', $context['source']);
    }

    public function test_legacy_default_site_domain_is_ignored(): void
    {
        $legacyDefault = $this->createSite('default', 'Default Site', Site::STATUS_ACTIVE, true);
        $this->createDomain($legacyDefault, 'main.example.test');

        $context = app(SiteResolver::class)->resolveHost('main.example.test');

        $this->assertNull($context['site_id']);
        $this->assertSame('platform', $context['site_code']);
        $this->assertSame('platform', $context['source']);
    }

    public function test_normalize_host_strips_scheme_path_ipv6_brackets_port_and_dot(): void
    {
        $resolver = app(SiteResolver::class);

        $this->assertSame('example.test', $resolver->normalizeHost('https://Example.Test:8443/path?x=1'));
        $this->assertSame('example.test', $resolver->normalizeHost('Example.Test.'));
        $this->assertSame('::1', $resolver->normalizeHost('[::1]:8080'));
    }

    private function createSite(string $code, string $name, string $status = Site::STATUS_ACTIVE, bool $isDefault = false): Site
    {
        return Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => $status,
            'is_default' => $isDefault,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createDomain(Site $site, string $domain, string $status = SiteDomain::STATUS_ACTIVE, bool $isPrimary = true): SiteDomain
    {
        return SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $domain,
            'status' => $status,
            'is_primary' => $isPrimary,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

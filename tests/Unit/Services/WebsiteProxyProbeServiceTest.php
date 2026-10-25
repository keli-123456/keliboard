<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\WebsiteProxyHealth;
use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use App\Services\SubscriptionProxy\WebsiteProxyProbeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class WebsiteProxyProbeServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        (require base_path('database/migrations/2026_10_25_120000_create_website_proxy_health_table.php'))->up();
        Http::preventStrayRequests();
    }

    public function test_matches_tenant_identity_and_pins_verified_https_to_a_public_address(): void
    {
        $options = [];
        Http::fake(function ($request, $sentOptions) use (&$options) {
            $options[] = $sentOptions;
            $this->assertSame(['identity'], $request->header('Accept-Encoding'));
            return str_contains($request->url(), '/api/')
                ? Http::response(['data' => ['site_context' => ['site_id' => 2, 'site_code' => 'branch']]], 200)
                : $this->page();
        });
        $target = $this->target();
        $result = $this->service()->probeTarget($target);
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['tls_verified']);
        $this->assertTrue(WebsiteProxyHealth::firstOrFail()->eligibleFor($target));
        $this->assertGreaterThanOrEqual(1, $result['latency_ms']);
        Http::assertSentCount(2);
        $this->assertCount(2, $options);
        foreach ($options as $option) {
            $this->assertTrue($option['verify']);
            $this->assertFalse($option['decode_content']);
            $this->assertFalse($option['allow_redirects']);
            $this->assertSame('', $option['proxy']);
            $this->assertSame(['edge.example.com:8444:1.1.1.1'], $option['curl'][CURLOPT_RESOLVE]);
            $this->assertSame(4, $option['timeout']);
        }
    }

    public function test_errors_remove_eligibility_and_two_consecutive_successes_restore_it(): void
    {
        $service = $this->service();
        $target = $this->target();
        $this->fakeHealthy();
        $this->assertSame('ok', $service->probeTarget($target)['status']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => Http::response('unavailable', 503));
        $failed = $service->probeTarget($target);
        $this->assertSame('page_http_error', $failed['reason']);
        $this->assertFalse(WebsiteProxyHealth::firstOrFail()->eligibleFor($target));
        $this->fakeHealthy();
        $this->assertSame('recovering', $service->probeTarget($target)['status']);
        $this->assertFalse(WebsiteProxyHealth::firstOrFail()->eligibleFor($target));
        $this->assertSame('ok', $service->probeTarget($target)['status']);
        $this->assertTrue(WebsiteProxyHealth::firstOrFail()->eligibleFor($target));
    }

    public function test_http_200_for_the_wrong_site_is_not_healthy(): void
    {
        $this->fakeHealthy(['site_id' => null, 'site_code' => 'platform']);
        $result = $this->service()->probeTarget($this->target());
        $this->assertSame(200, $result['http_status']);
        $this->assertSame(200, $result['context_http_status']);
        $this->assertSame('site_context_mismatch', $result['reason']);
        $this->assertSame('error', $result['status']);
        $this->assertFalse(WebsiteProxyHealth::firstOrFail()->eligibleFor($this->target()));
    }

    public function test_accepts_main_site_null_identity_only_for_platform(): void
    {
        $this->fakeHealthy(['site_id' => null, 'site_code' => 'platform']);
        $target = $this->target();
        $target['site_id'] = 0;
        $target['site_code'] = 'platform';
        $this->assertSame('ok', $this->service()->probeTarget($target)['status']);
    }

    public function test_rejects_redirects_non_html_invalid_json_missing_or_string_identity_and_oversized_responses(): void
    {
        $cases = [
            [Http::response('', 302, ['Location' => 'http://127.0.0.1']), null, 'page_http_error'],
            [Http::response('not a page', 200), null, 'page_not_html'],
            [$this->page(), Http::response('bad json', 200), 'site_context_missing'],
            [$this->page(), Http::response(['data' => []], 200), 'site_context_missing'],
            [$this->page(), Http::response(['data' => ['site_context' => ['site_id' => '2', 'site_code' => 'branch']]], 200), 'site_context_mismatch'],
            [$this->page(), Http::response('', 502), 'context_http_error'],
            [Http::response('<html>' . str_repeat('x', 524288), 200, ['Content-Type' => 'text/html']), null, 'response_too_large'],
        ];
        foreach ($cases as [$page, $context, $reason]) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(fn ($request) => str_contains($request->url(), '/api/') ? $context : $page);
            $this->assertSame($reason, $this->service()->probeTarget($this->target())['reason']);
        }
    }

    public function test_transport_errors_are_sanitized(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('private-token-do-not-store');
        });
        $result = $this->service()->probeTarget($this->target());
        $this->assertSame('request_failed', $result['reason']);
        $this->assertStringNotContainsString('private-token', json_encode($result));
    }

    public function test_unsafe_dns_answers_and_invalid_endpoints_never_send_requests(): void
    {
        Http::fake();
        foreach ([['127.0.0.1'], ['10.0.0.1'], ['100.64.0.1'], ['203.0.113.1'], ['224.0.0.1'], ['1.1.1.1', '192.168.1.1'], [null], []] as $addresses) {
            $service = new WebsiteProxyProbeService(new WebsiteProxyEndpointService(), fn () => $addresses);
            $this->assertSame('unsafe_address', $service->probeTarget($this->target())['reason']);
        }
        foreach (['http://edge.example.com', 'https://user@edge.example.com', 'https://edge.example.com/?token=secret', 'https://edge.example.com/path'] as $url) {
            $target = $this->target();
            $target['url'] = $url;
            $this->assertSame('invalid_endpoint', $this->service()->probeTarget($target)['reason']);
        }
        $target = $this->target();
        $target['runtime_error'] = 'runtime_unavailable';
        $this->assertSame('runtime_unavailable', $this->service()->probeTarget($target)['reason']);
        Http::assertNothingSent();
    }

    public function test_busy_target_does_not_overwrite_existing_health(): void
    {
        Http::fake();
        $lock = Cache::lock('website-proxy:probe:28:2', 60);
        $lock->get();
        try {
            $this->assertSame('skipped', $this->service()->probeTarget($this->target())['status']);
            $this->assertSame(0, WebsiteProxyHealth::count());
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    private function service(): WebsiteProxyProbeService
    {
        return new WebsiteProxyProbeService(new WebsiteProxyEndpointService(), fn () => ['1.1.1.1']);
    }

    private function target(): array
    {
        return [
            'machine_id' => 28, 'site_id' => 2, 'site_code' => 'branch',
            'url' => 'https://edge.example.com:8444', 'upstream_url' => 'https://branch.example.com',
            'route_fingerprint' => str_repeat('a', 64), 'runtime_error' => null,
        ];
    }

    private function page()
    {
        return Http::response('<!doctype html><html><body>ok</body></html>', 200, ['Content-Type' => 'text/html']);
    }

    private function fakeHealthy(?array $context = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn ($request) => str_contains($request->url(), '/api/')
            ? Http::response(['data' => ['site_context' => $context ?? ['site_id' => 2, 'site_code' => 'branch']]], 200)
            : $this->page());
    }
}

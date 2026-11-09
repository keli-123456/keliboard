<?php
namespace Tests\Unit\Services;

use App\Models\MachineDnsCredential;
use App\Services\ServerMachine\Dns\DnsException;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\DnsRequestFailure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MachineOwnedDnsProviderTest extends TestCase
{
    private DnsProviderClient $client;
    private MachineDnsCredential $credential;
    private string $zone = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private string $host = 'owned.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('encrypter', new Encrypter(str_repeat('q', 32), 'AES-256-CBC'));
        $this->client = new DnsProviderClient();
        $this->credential = (new MachineDnsCredential())->forceFill(['provider' => 'cloudflare', 'credentials' => ['api_token' => 'synthetic-secret']]);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
    }
    private function record(int $id = 1): array
    {
        return ['id' => str_pad(dechex($id), 32, '0', STR_PAD_LEFT), 'name' => $this->host, 'type' => 'A', 'content' => '8.8.4.4',
            'ttl' => 60, 'proxied' => false, 'comment' => 'keli-owned-' . str_repeat('b', 48), 'locked' => false];
    }
    private function page(array $rows, int $page = 1, int $total = 1): array
    {
        return ['success' => true, 'result' => $rows, 'result_info' => ['total_pages' => max(1, (int) ceil($total / 50)), 'total_count' => $total, 'page' => $page, 'per_page' => 50]];
    }
    public static function incomplete(): array
    {
        return array_map(fn ($case) => [$case], ['missing_meta', 'missing_total', 'wrong_page', 'wrong_size', 'truncated', 'too_large', 'duplicates', 'wrong_host', 'invalid_proxy', 'invalid_ttl', 'invalid_private']);
    }
    #[DataProvider('incomplete')]
    public function test_incomplete_or_malformed_owned_dns_view_never_proves_an_empty_safe_record(string $case): void
    {
        $body = $this->page([$this->record()]);
        if ($case === 'missing_meta') unset($body['result_info']);
        if ($case === 'missing_total') unset($body['result_info']['total_count']);
        if ($case === 'wrong_page') $body['result_info']['page'] = 2;
        if ($case === 'wrong_size') $body['result_info']['per_page'] = 100;
        if ($case === 'truncated') $body['result'] = [];
        if ($case === 'too_large') { $body['result_info']['total_count'] = 151; $body['result_info']['total_pages'] = 4; }
        if ($case === 'duplicates') { $body['result'][] = $this->record(); $body['result_info']['total_count'] = 2; }
        if ($case === 'wrong_host') $body['result'][0]['name'] = 'unrelated.example.test';
        if ($case === 'invalid_proxy') $body['result'][0]['proxied'] = 'false';
        if ($case === 'invalid_ttl') $body['result'][0]['ttl'] = '60';
        if ($case === 'invalid_private') $body['result'][0]['private_routing'] = 'false';
        Http::fake(fn () => Http::response($body));
        try { $this->client->ownedRecords($this->credential, $this->zone, $this->host); $this->fail('Expected incomplete view rejection'); }
        catch (DnsException $error) { $this->assertSame('provider_rejected', $error->getMessage()); }
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
    public function test_complete_fixed_origin_pagination_checks_totals_on_every_page(): void
    {
        $rows = array_map(fn ($id) => $this->record($id), range(1, 51));
        Http::fake(function ($request) use ($rows) {
            $this->assertStringStartsWith('https://api.cloudflare.com/client/v4/zones/' . $this->zone . '/dns_records?', $request->url());
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query); $page = (int) $query['page'];
            return Http::response($this->page(array_slice($rows, ($page - 1) * 50, 50), $page, 51));
        });
        $this->assertCount(51, $this->client->ownedRecords($this->credential, $this->zone, $this->host)); Http::assertSentCount(2);
    }
    public function test_changed_page_totals_are_not_accepted_as_a_complete_dns_inventory(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query); $page = (int) $query['page'];
            return Http::response($this->page(array_map(fn ($id) => $this->record($id), $page === 1 ? range(1, 50) : [51]), $page, $page === 1 ? 51 : 52));
        });
        $this->expectException(DnsException::class); $this->client->ownedRecords($this->credential, $this->zone, $this->host);
    }
    public function test_verified_empty_view_is_explicit_and_bounded(): void
    {
        Http::fake(fn () => Http::response($this->page([], 1, 0)));
        $this->assertSame([], $this->client->ownedRecords($this->credential, $this->zone, $this->host)); Http::assertSentCount(1);
    }
    public static function retryHeaders(): array { return [['900', 900, true], ['-1', 120, true], ['99999999', 86400, false]]; }
    #[DataProvider('retryHeaders')]
    public function test_rate_limit_headers_are_bounded_without_exposing_provider_bodies(string $header, int $seconds, bool $retryable): void
    {
        Http::fake(fn () => Http::response(['private' => 'never-output'], 429, ['Retry-After' => $header]));
        try { $this->client->ownedRecords($this->credential, $this->zone, $this->host); $this->fail('Expected backoff'); }
        catch (DnsRequestFailure $error) {
            $this->assertSame('provider_rate_limited', $error->getMessage()); $this->assertSame($seconds, $error->retryAfterSeconds);
            $this->assertSame($retryable, $error->retryable); $this->assertStringNotContainsString('never-output', $error->getMessage());
        }
        Http::assertSentCount(1);
    }
}

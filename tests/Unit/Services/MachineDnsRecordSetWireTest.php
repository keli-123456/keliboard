<?php
namespace Tests\Unit\Services;

use App\Models\MachineDnsCredential;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Dns\DnsException;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\DnsRequestFailure;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MachineDnsRecordSetWireTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); app()->instance('encrypter', new Encrypter(str_repeat('w', 32), 'AES-256-CBC'));
        // Only this isolated test double bypasses the non-configurable production gate.
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate { public function assertAllowed(string $operation): void {} });
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
    }
    private function scope(string $provider = 'aliyun'): array
    {
        $credential = (new MachineDnsCredential())->forceFill(['id' => 9, 'provider' => $provider, 'revision' => 1, 'credentials' => $provider === 'cloudflare'
            ? ['api_token' => 'fake-cloudflare-secret'] : ['access_key_id' => 'fake-access-key', 'access_key_secret' => 'fake-aliyun-secret']]);
        return [$credential, ['credential_id' => 9, 'credential_revision' => 1, 'provider' => $provider, 'zone' => $provider === 'cloudflare' ? str_repeat('a', 32) : 'example.test',
            'hostname' => 'shared.example.test', 'ttl' => $provider === 'cloudflare' ? 60 : 600]];
    }
    private function action(): array
    {
        return ['kind' => 'create', 'before' => null, 'after' => ['hostname' => 'shared.example.test', 'type' => 'AAAA', 'line' => 'telecom',
            'content' => '2001:db8:1::2', 'ttl' => 600, 'marker' => 'keli-set-' . str_repeat('b', 48), 'proxied' => false, 'locked' => false]];
    }
    public function test_aliyun_add_and_remark_use_distinct_signed_requests_and_remark_has_no_record_id_response(): void
    {
        [$credential, $set] = $this->scope(); $id = '900000000000000000001'; $seen = [];
        Http::fake(function ($request) use (&$seen, $id) {
            $v = $request->data(); $seen[] = $v;
            $this->assertSame('https://alidns.aliyuncs.com/', $request->url()); $this->assertSame('POST', $request->method());
            $unsigned = $v; unset($unsigned['Signature']); $this->assertSame(DnsProviderClient::signAliyun($unsigned, 'fake-aliyun-secret')['Signature'], $v['Signature']);
            return Http::response($v['Action'] === 'AddDomainRecord' ? ['RequestId' => 'add-receipt', 'RecordId' => $id] : ['RequestId' => 'remark-receipt']);
        });
        $client = new DnsProviderClient(); $action = $this->action(); $this->assertSame($id, $client->recordSetWrite($credential, $set, $action));
        $this->assertSame($id, $client->recordSetWrite($credential, $set, array_replace($action, ['kind' => 'claim']), $id));
        $this->assertArrayNotHasKey('Remark', $seen[0]); $this->assertSame('AAAA', $seen[0]['Type']); $this->assertSame('telecom', $seen[0]['Line']);
        $this->assertSame('shared', $seen[0]['RR']); $this->assertSame($id, $seen[1]['RecordId']); $this->assertSame($action['after']['marker'], $seen[1]['Remark']);
        $this->assertNotSame($seen[0]['SignatureNonce'], $seen[1]['SignatureNonce']); Http::assertSentCount(2);
    }
    public function test_aliyun_inventory_pins_zone_and_exact_hostname_and_preserves_long_ids(): void
    {
        [$credential, $set] = $this->scope();
        Http::fake(function ($request) {
            $this->assertSame('DescribeSubDomainRecords', $request['Action']); $this->assertSame('shared.example.test', $request['SubDomain']);
            $this->assertSame('example.test', $request['DomainName']); $this->assertSame(50, $request['PageSize']);
            return Http::response(['RequestId' => 'read-receipt', 'TotalCount' => 1, 'PageNumber' => 1, 'PageSize' => 50, 'DomainRecords' => ['Record' => [
                ['RecordId' => '900000000000000000001', 'DomainName' => 'example.test', 'RR' => 'shared', 'Type' => 'AAAA', 'Value' => '2001:db8:0:0::1',
                    'TTL' => 600, 'Line' => 'telecom', 'Status' => 'Enable', 'Locked' => false, 'Remark' => '']]]]);
        });
        $records = (new DnsProviderClient())->recordSetInventory($credential, $set);
        $this->assertSame('900000000000000000001', $records[0]['id']); $this->assertSame('2001:db8::1', $records[0]['content']); Http::assertSentCount(1);
    }
    public function test_cloudflare_inventory_accepts_a_proven_empty_page_with_zero_total_pages(): void
    {
        [$credential, $set] = $this->scope('cloudflare');
        Http::fake(['https://api.cloudflare.com/client/v4/*' => Http::response(['success' => true, 'result' => [], 'result_info' => ['page' => 1, 'per_page' => 50, 'total_count' => 0, 'total_pages' => 0]])]);
        $this->assertSame([], (new DnsProviderClient())->recordSetInventory($credential, $set));
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), 'name.exact=shared.example.test'));
    }
    public static function failures(): array { return [[401, 'provider_forbidden'], [403, 'provider_forbidden'], [429, 'provider_rate_limited'], [503, 'provider_unavailable'], [400, 'provider_rejected']]; }
    #[DataProvider('failures')]
    public function test_http_errors_are_typed_and_never_automatically_repeat_a_mutation(int $status, string $reason): void
    {
        [$credential, $set] = $this->scope(); Http::fake(['https://alidns.aliyuncs.com/*' => Http::response('private-provider-body', $status, ['Retry-After' => '140'])]);
        try { (new DnsProviderClient())->recordSetWrite($credential, $set, $this->action()); $this->fail('Expected typed failure'); }
        catch (DnsException $e) { $this->assertSame($reason, $e->getMessage()); if ($status === 429) { $this->assertInstanceOf(DnsRequestFailure::class, $e); $this->assertSame(140, $e->retryAfterSeconds); } }
        Http::assertSentCount(1);
    }
    public function test_mismatched_credentials_and_malformed_scope_make_no_http_request(): void
    {
        [$credential, $set] = $this->scope(); Http::fake();
        foreach ([['credential_revision' => 2], ['provider' => 'cloudflare'], ['hostname' => 'other.test']] as $change) {
            try { (new DnsProviderClient())->recordSetInventory($credential, array_replace($set, $change)); $this->fail('Expected scope failure'); }
            catch (\App\Services\ServerMachine\Cloud\CloudException|DnsException) {}
        }
        Http::assertNothingSent();
    }
    public function test_missing_acknowledged_create_id_is_unknown_not_a_new_create_or_ip_adoption(): void
    {
        [$credential, $set] = $this->scope(); Http::fake(['https://alidns.aliyuncs.com/*' => Http::response(['RequestId' => 'receipt-only'])]);
        try { (new DnsProviderClient())->recordSetWrite($credential, $set, $this->action()); $this->fail('Expected unknown outcome'); }
        catch (DnsException $e) { $this->assertSame('dns_outcome_unknown', $e->getMessage()); }
        Http::assertSentCount(1);
    }
    public static function rpcFailures(): array { return [['Throttling.User', 'provider_rate_limited'], ['InternalError', 'provider_unavailable']]; }
    #[DataProvider('rpcFailures')]
    public function test_aliyun_rpc_errors_with_http_200_are_typed_without_retrying_writes(string $code, string $reason): void
    {
        [$credential, $set] = $this->scope(); Http::fake(['https://alidns.aliyuncs.com/*' => Http::response(['Code' => $code, 'Message' => 'private-provider-body'])]);
        try { (new DnsProviderClient())->recordSetWrite($credential, $set, $this->action()); $this->fail('Expected RPC failure'); }
        catch (DnsRequestFailure $e) { $this->assertSame($reason, $e->getMessage()); }
        Http::assertSentCount(1);
    }
}

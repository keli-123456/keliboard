<?php
namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Dns\MachineDnsPropagation as Check;
use App\Services\ServerMachine\Dns\MachineDnsPropagationHttp as Reader;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MachineDnsPropagationTest extends TestCase
{
    private const NOW = 1800001000;
    private function plan(): array
    {
        return ['hostname' => 'edge.example.com', 'changed_at' => self::NOW - 601, 'expected' => ['A' => ['192.0.2.2'], 'AAAA' => ['2001:db8::2']],
            'previous_ttl' => ['A' => 600, 'AAAA' => 600], 'negative_ttl' => null, 'lines' => ['default']];
    }
    private function observations(): array
    {
        $rows = [];
        foreach (Check::RESOLVERS as $resolver) foreach (['A' => [1, '192.0.2.2'], 'AAAA' => [28, '2001:0db8:0:0:0:0:0:2']] as $type => [$number, $ip])
            $rows[] = ['resolver' => $resolver, 'type' => $type, 'checked_at' => self::NOW, 'status' => 'ok', 'body' => ['Status' => 0, 'TC' => false,
                'Question' => [['name' => 'EDGE.EXAMPLE.COM.', 'type' => $number]], 'Answer' => [['name' => 'edge.example.com.', 'type' => $number, 'TTL' => 60, 'data' => $ip]]]];
        return $rows;
    }
    public function test_selected_resolvers_are_not_global_or_provider_authority_and_ttl_uses_the_old_value(): void
    {
        $p = $this->plan(); $r = (new Check())->evaluate($p, $this->observations(), self::NOW);
        $this->assertSame('converged', $r['state']); $this->assertTrue($r['ttl_elapsed']); $this->assertTrue($r['selected_resolvers_match']);
        $this->assertFalse($r['propagation_verified']); $this->assertFalse($r['global_propagation_verified']);
        $this->assertSame('selected_public_resolvers', $r['scope']);
        $p['changed_at'] = self::NOW - 60;
        $r = (new Check())->evaluate($p, $this->observations(), self::NOW);
        $this->assertSame('waiting_ttl', $r['state']); $this->assertSame(540, $r['remaining_seconds']);
    }
    public function test_line_and_missing_baseline_limits_cannot_be_hidden_by_matching_answers(): void
    {
        $p = $this->plan(); $p['lines'] = ['default', 'telecom', 'unicom'];
        $r = (new Check())->evaluate($p, $this->observations(), self::NOW);
        $this->assertSame('partial_scope', $r['state']); $this->assertSame(['telecom', 'unicom'], $r['unobserved_lines']);
        $p['previous_ttl']['AAAA'] = null;
        $r = (new Check())->evaluate($p, $this->observations(), self::NOW);
        $this->assertSame('baseline_required', $r['state']); $this->assertNull($r['remaining_seconds']);
    }
    public static function badResponses(): array
    {
        return array_map(fn ($v) => [$v], ['missing', 'duplicate', 'old', 'future', 'timeout', 'servfail', 'truncated', 'checking_disabled',
            'question_name', 'question_type', 'question_invalid', 'cname', 'extra_address', 'wrong_family', 'ttl_invalid', 'answer_invalid', 'nxdomain_with_address']);
    }
    #[DataProvider('badResponses')]
    public function test_one_bad_resolver_or_family_never_converges(string $case): void
    {
        $rows = $this->observations();
        if ($case === 'missing') array_pop($rows);
        if ($case === 'duplicate') $rows[3] = $rows[2];
        if ($case === 'old') $rows[0]['checked_at'] -= 61;
        if ($case === 'future') $rows[0]['checked_at']++;
        if ($case === 'timeout') $rows[0]['status'] = 'timeout';
        if ($case === 'servfail') $rows[0]['body']['Status'] = 2;
        if ($case === 'truncated') $rows[0]['body']['TC'] = true;
        if ($case === 'checking_disabled') $rows[0]['body']['CD'] = true;
        if ($case === 'question_name') $rows[0]['body']['Question'][0]['name'] = 'other.example.com';
        if ($case === 'question_type') $rows[0]['body']['Question'][0]['type'] = 28;
        if ($case === 'question_invalid') $rows[0]['body']['Question'][0]['name'] = [];
        if ($case === 'cname') $rows[0]['body']['Answer'][0]['type'] = 5;
        if ($case === 'extra_address') $rows[0]['body']['Answer'][] = ['name' => 'edge.example.com', 'type' => 1, 'TTL' => 0, 'data' => '192.0.2.3'];
        if ($case === 'wrong_family') $rows[0]['body']['Answer'][0]['data'] = '2001:db8::2';
        if ($case === 'ttl_invalid') $rows[0]['body']['Answer'][0]['TTL'] = '60';
        if ($case === 'answer_invalid') $rows[0]['body']['Answer'] = 'secret body';
        if ($case === 'nxdomain_with_address') $rows[0]['body']['Status'] = 3;
        $r = (new Check())->evaluate($this->plan(), $rows, self::NOW);
        $this->assertNotSame('converged', $r['state']); $this->assertFalse($r['selected_resolvers_match']);
        $this->assertStringNotContainsString('secret body', json_encode($r));
    }
    public function test_negative_cache_uses_minimum_of_soa_ttl_and_minimum_and_preserves_wait_on_recheck(): void
    {
        $rows = $this->observations();
        $rows[0]['body']['Status'] = 3; $rows[0]['body']['Answer'] = [];
        $rows[0]['body']['Authority'] = [['name' => 'example.com.', 'type' => 6, 'TTL' => 900, 'data' => 'ns.example.com. hostmaster.example.com. 1 3600 600 86400 300']];
        $r = (new Check())->evaluate($this->plan(), $rows, self::NOW);
        $this->assertSame('nxdomain', $r['rows'][0]['state']); $this->assertSame(300, $r['rows'][0]['ttl']);
        $this->assertSame(self::NOW + 300, $r['wait_until']);
        $p = $this->plan(); $p['wait_until'] = $r['wait_until'];
        $r = (new Check())->evaluate($p, $this->observations(), self::NOW);
        $this->assertSame('waiting_ttl', $r['state']); $this->assertSame(300, $r['remaining_seconds']);
    }
    public function test_empty_answer_is_not_proof_of_requested_absence_without_relevant_soa(): void
    {
        $p = $this->plan(); $p['expected']['A'] = []; $rows = $this->observations();
        foreach ([0, 2] as $index) $rows[$index]['body']['Answer'] = [];
        $r = (new Check())->evaluate($p, $rows, self::NOW); $this->assertSame('mismatch', $r['state']);
        foreach ([0, 2] as $index) $rows[$index]['body']['Authority'] = [['name' => 'example.com', 'type' => 6, 'TTL' => 0, 'data' => 'ns.example.com. mail.example.com. 1 2 3 4 0']];
        $this->assertSame('converged', (new Check())->evaluate($p, $rows, self::NOW)['state']);
    }
    public function test_http_reader_has_no_public_execution_bypass(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests(); Http::fake();
        try { app(Reader::class)->query('google', 'edge.example.com', 'A'); $this->fail('Expected closed gate'); }
        catch (CloudException $e) { $this->assertSame('orchestration_disabled', $e->getMessage()); }
        Http::assertNothingSent();
    }
    public function test_http_contract_uses_only_fixed_tls_endpoints_and_no_redirect_or_credentials(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        $gate = $this->createMock(MachineFailoverExecutionGate::class); $gate->method('assertAllowed')->willReturnCallback(fn () => null);
        Http::fake(['https://dns.google/*' => Http::response($this->observations()[0]['body'])]);
        $r = (new Reader($gate))->query('google', 'edge.example.com', 'A'); $this->assertSame('ok', $r['status']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://dns.google/resolve?') && $request['cd'] === '0'
            && $request['edns_client_subnet'] === '0.0.0.0/0' && !$request->hasHeader('Authorization'));
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        $this->assertSame('invalid', (new Reader($gate))->query('cloudflare', 'edge.example.com', 'AAAA')['status']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
    }
}

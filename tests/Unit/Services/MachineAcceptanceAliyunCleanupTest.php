<?php

namespace Tests\Unit\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MachineAcceptanceAliyunCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function reader(?callable $remaining = null): \Closure
    {
        $factory = require base_path('tools/machine-acceptance/aliyun_cleanup.php');
        return $factory(['access_key_id' => 'synthetic-only', 'access_key_secret' => 'synthetic-only'], $remaining ?? fn () => 30);
    }

    public static function missingResponses(): array
    {
        $cases = [];
        foreach (['InvalidRecordId.NotFound', 'InvalidRR.NoExist'] as $code) foreach ([200, 400, 404] as $status) {
            $cases[$code . '-' . $status] = [$code, $status];
        }
        return $cases;
    }

    #[DataProvider('missingResponses')]
    public function test_documented_missing_exact_id_is_recognized_only_when_opted_in(string $code, int $status): void
    {
        Http::fake(['alidns.aliyuncs.com/*' => Http::response(['Code' => $code, 'RequestId' => 'synthetic'], $status)]);
        $this->assertNull(($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123'], true));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['Action'] === 'DescribeDomainRecordInfo' && $r['RecordId'] === '123');
        $this->expectExceptionMessage('dns_read_rejected');
        ($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123']);
    }

    public static function unconfirmedResponses(): array
    {
        $cases = [];
        foreach (['InvalidAccessKeyId.NotFound', 'Forbidden', 'AccessDenied', 'Throttling', 'InternalError',
                  'DomainNotFound', 'InvalidDomainName.NoExist', 'NotFound', null, ['InvalidRR.NoExist']] as $index => $code) {
            $cases['code-' . $index] = [400, ['Code' => $code]];
        }
        foreach ([301, 401, 403, 429, 500, 503] as $status) $cases['status-' . $status] = [$status, ['Code' => 'InvalidRR.NoExist']];
        $cases['record-and-error'] = [400, ['Code' => 'InvalidRR.NoExist', 'RecordId' => '123']];
        $cases['null-record-and-error'] = [400, ['Code' => 'InvalidRR.NoExist', 'RecordId' => null]];
        $cases['non-json'] = [400, 'not-json synthetic-private-error'];
        $cases['empty-success'] = [200, []];
        $cases['wrong-id-success'] = [200, ['RecordId' => '456']];
        $cases['oversized'] = [400, str_repeat('x', 262145)];
        return $cases;
    }

    #[DataProvider('unconfirmedResponses')]
    public function test_unknown_auth_throttle_server_and_malformed_errors_are_not_absence(int $status, mixed $body): void
    {
        Http::fake(['alidns.aliyuncs.com/*' => Http::response($body, $status)]);
        $this->expectException(RuntimeException::class);
        ($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123'], true);
    }

    public function test_missing_record_code_does_not_mean_empty_name_inventory(): void
    {
        Http::fake(['alidns.aliyuncs.com/*' => Http::response(['Code' => 'InvalidRR.NoExist'], 400)]);
        $this->expectExceptionMessage('dns_read_rejected');
        ($this->reader())('DescribeSubDomainRecords', ['DomainName' => 'isolated.invalid', 'SubDomain' => 'test.isolated.invalid'], true);
    }

    public function test_truthy_non_boolean_opt_in_does_not_authorize_absence(): void
    {
        Http::fake(['alidns.aliyuncs.com/*' => Http::response(['Code' => 'InvalidRR.NoExist'], 400)]);
        foreach ([1, 'true', 'false', '1', null] as $optIn) {
            try { ($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123'], $optIn); $this->fail('Expected refusal'); }
            catch (RuntimeException $e) { $this->assertSame('dns_read_rejected', $e->getMessage()); }
        }
    }

    public function test_existing_exact_record_is_returned_for_outer_ownership_validation(): void
    {
        $record = ['RecordId' => '123', 'DomainName' => 'isolated.invalid', 'RR' => 'test', 'Type' => 'A',
                   'Value' => '45.79.10.20', 'TTL' => 600, 'Line' => 'default', 'Remark' => 'owned-synthetic'];
        Http::fake(['alidns.aliyuncs.com/*' => Http::response($record)]);
        $this->assertSame($record, ($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123'], true));
    }

    public function test_omitted_empty_remark_normalizes_only_after_exact_id_confirmation(): void
    {
        $record = ['RecordId' => '123', 'DomainName' => 'isolated.invalid', 'RR' => 'test', 'Type' => 'A',
            'Value' => '45.79.10.20', 'TTL' => 600, 'Line' => 'default'];
        Http::fake(['alidns.aliyuncs.com/*' => Http::response($record)]);
        $actual = ($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123'], true);
        $this->assertSame($record + ['Remark' => ''], $actual);
        $this->assertNotNull($actual);
        // The existing outer guard still needs its explicit partial-create receipt opt-in.
        $matches = fn ($allowBlank) => $actual['Remark'] === 'owned-synthetic'
            || $allowBlank === true && $actual['Remark'] === '';
        $this->assertTrue($matches(true));
        foreach ([false, null, 'true', 1] as $flag) $this->assertFalse($matches($flag));
        Http::assertSentCount(1);
    }

    public static function invalidRemarks(): array { return [[null], [false], [12], [[]], [str_repeat('x', 1001)]]; }

    #[DataProvider('invalidRemarks')]
    public function test_malformed_remark_is_not_silently_converted_to_empty(mixed $remark): void
    {
        Http::fake(['alidns.aliyuncs.com/*' => Http::response(['RecordId' => '123', 'Remark' => $remark])]);
        $this->expectExceptionMessage('dns_read_rejected');
        ($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123'], true);
    }

    public function test_compact_legacy_and_foreign_ownership_remain_distinct(): void
    {
        $marker = 'keli-set-' . str_repeat('a', 48);
        $compact = \App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider::aliyunMarker($marker);
        foreach ([$compact => $marker, $marker => $marker, 'foreign-synthetic' => 'foreign-synthetic'] as $wire => $canonical) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            Http::fake(['alidns.aliyuncs.com/*' => Http::response(['RecordId' => '123', 'Remark' => $wire])]);
            $this->assertSame($canonical, ($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123'], true)['Remark']);
        }
    }

    public function test_list_and_exact_id_readback_use_the_same_marker_but_omitted_list_remark_still_needs_fallback(): void
    {
        $marker = 'keli-set-' . str_repeat('a', 48);
        $body = ['TotalCount' => 3, 'PageNumber' => 1, 'PageSize' => 100, 'DomainRecords' => ['Record' => [
            ['RecordId' => '123', 'Remark' => \App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider::aliyunMarker($marker)],
            ['RecordId' => '456'], ['RecordId' => '789', 'Remark' => 'foreign-synthetic']]]];
        Http::fake(['alidns.aliyuncs.com/*' => Http::response($body)]);
        $rows = ($this->reader())('DescribeSubDomainRecords', ['SubDomain' => 'test.isolated.invalid'])['DomainRecords']['Record'];
        $this->assertSame($marker, $rows[0]['Remark']);
        $this->assertArrayNotHasKey('Remark', $rows[1]);
        $this->assertSame('foreign-synthetic', $rows[2]['Remark']);
        Http::assertSentCount(1);
    }

    public function test_reader_does_not_require_a_new_application_class_on_the_existing_credential_bridge(): void
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY,
            base_path('tests/Fixtures/MachineAcceptance/cleanup_legacy_bridge_probe.php')]);
        $process->setTimeout(15); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $value = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(['ok' => true, 'remark' => 'keli-set-' . str_repeat('a', 48),
            'new_application_class_loaded' => false, 'requests' => 1], $value);
    }

    public function test_write_actions_and_invalid_ids_never_reach_http(): void
    {
        Http::fake();
        foreach (['DeleteDomainRecord', 'UpdateDomainRecord', 'AddDomainRecord'] as $action) {
            try { ($this->reader())($action, ['RecordId' => '123'], true); $this->fail('Expected refusal'); }
            catch (RuntimeException $e) { $this->assertSame('dns_read_action_required', $e->getMessage()); }
        }
        foreach ([null, 123, true, '', '0', '1/records', str_repeat('1', 41)] as $id) {
            try { ($this->reader())('DescribeDomainRecordInfo', ['RecordId' => $id], true); $this->fail('Expected refusal'); }
            catch (RuntimeException $e) { $this->assertSame('dns_record_id_invalid', $e->getMessage()); }
        }
        Http::assertNothingSent();
    }

    public function test_data_cannot_override_the_read_action_or_signing_identity(): void
    {
        Http::fake(['alidns.aliyuncs.com/*' => Http::response(['Code' => 'InvalidRR.NoExist'], 400)]);
        $this->assertNull(($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123',
            'Action' => 'DeleteDomainRecord', 'AccessKeyId' => 'foreign', 'Version' => 'foreign',
            'SignatureNonce' => 'foreign', 'Timestamp' => 'foreign'], true));
        Http::assertSent(fn ($r) => $r['Action'] === 'DescribeDomainRecordInfo' && $r['AccessKeyId'] === 'synthetic-only'
            && $r['Version'] === '2015-01-09' && $r['RecordId'] === '123'
            && $r['SignatureNonce'] !== 'foreign' && $r['Timestamp'] !== 'foreign');
    }

    public function test_deadline_and_transport_failure_never_produce_success(): void
    {
        Http::fake();
        $reader = $this->reader(function () { throw new RuntimeException('cleanup_deadline_exhausted'); });
        try { $reader('DescribeDomainRecordInfo', ['RecordId' => '123'], true); $this->fail('Expected refusal'); }
        catch (RuntimeException $e) { $this->assertSame('cleanup_deadline_exhausted', $e->getMessage()); }
        Http::assertNothingSent();
        Http::fake(fn () => throw new ConnectionException('synthetic-timeout'));
        $this->expectException(ConnectionException::class);
        ($this->reader())('DescribeDomainRecordInfo', ['RecordId' => '123'], true);
    }

    public function test_post_response_deadline_is_checked_before_accepting_absence(): void
    {
        Http::fake(['alidns.aliyuncs.com/*' => Http::response(['Code' => 'InvalidRR.NoExist'], 400)]);
        $calls = 0;
        $reader = $this->reader(function () use (&$calls) {
            if (++$calls === 3) throw new RuntimeException('cleanup_deadline_exhausted');
            return 10;
        });
        $this->expectExceptionMessage('cleanup_deadline_exhausted');
        $reader('DescribeDomainRecordInfo', ['RecordId' => '123'], true);
    }
}

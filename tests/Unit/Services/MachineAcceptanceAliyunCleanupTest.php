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

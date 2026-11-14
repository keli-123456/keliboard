<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MachineDnsCredential;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Dns\DnsException;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\DnsRequestFailure;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider as Contract;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/cli_diagnostics.php';

class MachineDnsAliyunCompatibilityTest extends TestCase
{
    private const ID = '900000000000000000001';
    private const MARKER = 'keli-set-' . '0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('encrypter', new Encrypter(str_repeat('w', 32), 'AES-256-CBC'));
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate {
            public function assertAllowed(string $operation): void {}
        });
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
    }

    private function scope(): array
    {
        return [(new MachineDnsCredential())->forceFill(['id' => 9, 'revision' => 1, 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'synthetic-only', 'access_key_secret' => 'synthetic-only']]),
            ['credential_id' => 9, 'credential_revision' => 1, 'provider' => 'aliyun', 'zone' => 'example.test',
                'hostname' => 'edge.example.test', 'ttl' => 600]];
    }

    private function row(): array
    {
        return ['RecordId' => self::ID, 'DomainName' => 'example.test', 'RR' => 'edge', 'Type' => 'A',
            'Value' => '45.79.10.20', 'TTL' => 600, 'Line' => 'default', 'Status' => 'Enable', 'Locked' => false];
    }

    private function page(array $row): array
    {
        return ['TotalCount' => 1, 'PageNumber' => 1, 'PageSize' => 50, 'DomainRecords' => ['Record' => [$row]]];
    }

    private function action(string $kind = 'create'): array
    {
        return ['kind' => $kind, 'after' => ['hostname' => 'edge.example.test', 'type' => 'A', 'line' => 'default',
            'content' => '45.79.10.20', 'ttl' => 600, 'marker' => self::MARKER, 'proxied' => false, 'locked' => false]];
    }

    public function test_compact_remark_preserves_all_192_bits_and_legacy_markers_without_truncation(): void
    {
        foreach ([str_repeat('0', 48), str_repeat('f', 48), substr(self::MARKER, 9), str_repeat('fbef', 12)] as $hex) {
            $marker = 'keli-set-' . $hex;
            $wire = Contract::aliyunMarker($marker);
            $this->assertSame(45, strlen($wire));
            $this->assertMatchesRegularExpression('/^keli-set-b64-[A-Za-z0-9_-]{32}$/D', $wire);
            $this->assertSame($hex, bin2hex(base64_decode(strtr(substr($wire, 13), '-_', '+/'), true)));
            $this->assertSame($marker, Contract::canonicalAliyunMarker($wire));
            $this->assertSame($marker, Contract::canonicalAliyunMarker($marker));
        }
        $this->assertNotSame(Contract::aliyunMarker('keli-set-' . str_repeat('0', 48)),
            Contract::aliyunMarker('keli-set-' . str_repeat('0', 47) . '1'));
        foreach (['', 'foreign-owner', 'keli-set-b64-' . str_repeat('a', 31), 'keli-set-b64-' . str_repeat('a', 33)] as $foreign) {
            $this->assertSame($foreign, Contract::canonicalAliyunMarker($foreign));
        }
    }

    public function test_create_claim_and_exact_readback_work_with_a_50_character_provider_fixture(): void
    {
        [$credential, $set] = $this->scope(); $remark = null; $actions = [];
        Http::fake(function ($request) use (&$remark, &$actions) {
            $actions[] = $request['Action'];
            return match ($request['Action']) {
                'AddDomainRecord' => Http::response(['RequestId' => 'create-receipt', 'RecordId' => self::ID]),
                'UpdateDomainRecordRemark' => (function () use ($request, &$remark) {
                    $this->assertSame(self::ID, $request['RecordId']);
                    if (strlen($request['Remark']) > 50) return Http::response(['Code' => 'InvalidRemark.TooLong'], 400);
                    $remark = $request['Remark'];
                    return Http::response(['RequestId' => 'claim-receipt']);
                })(),
                'DescribeSubDomainRecords' => Http::response($this->page($this->row())),
                'DescribeDomainRecordInfo' => Http::response($this->row() + ['Remark' => $remark]),
                default => throw new \LogicException('Unexpected simulated action'),
            };
        });
        $client = new DnsProviderClient();
        $id = $client->recordSetWrite($credential, $set, $this->action());
        $this->assertSame($id, $client->recordSetWrite($credential, $set, $this->action('claim'), $id));
        $this->assertSame(self::MARKER, $client->recordSetInventory($credential, $set)[0]['marker']);
        $this->assertSame(['AddDomainRecord', 'UpdateDomainRecordRemark', 'DescribeSubDomainRecords', 'DescribeDomainRecordInfo'], $actions);
        Http::assertSentCount(4);
    }

    public function test_missing_empty_remark_is_not_a_missing_record_or_a_foreign_owner_claim(): void
    {
        [$credential, $set] = $this->scope();
        foreach ([null, '', 'foreign-owner', self::MARKER, Contract::aliyunMarker(self::MARKER)] as $remark) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            Http::fake(fn ($request) => Http::response($request['Action'] === 'DescribeSubDomainRecords'
                ? $this->page($this->row()) : $this->row() + ($remark === null ? [] : ['Remark' => $remark])));
            $rows = (new DnsProviderClient())->recordSetInventory($credential, $set);
            $this->assertCount(1, $rows);
            $this->assertSame(self::ID, $rows[0]['id']);
            $this->assertSame($remark === null ? '' : Contract::canonicalAliyunMarker($remark), $rows[0]['marker']);
            Http::assertSentCount(2);
            Http::assertNotSent(fn ($r) => in_array($r['Action'], ['AddDomainRecord', 'UpdateDomainRecordRemark', 'DeleteDomainRecord'], true));
        }
    }

    public static function changedRecords(): array
    {
        return [['RecordId', '456'], ['DomainName', 'foreign.test'], ['RR', 'other'], ['Type', 'AAAA'],
            ['Value', '45.79.10.21'], ['TTL', 601], ['Line', 'telecom'], ['Status', 'Disable'], ['Locked', true]];
    }

    #[DataProvider('changedRecords')]
    public function test_missing_remark_fallback_cannot_replace_any_identity_or_state_field(string $field, mixed $value): void
    {
        [$credential, $set] = $this->scope();
        Http::fake(fn ($request) => Http::response($request['Action'] === 'DescribeSubDomainRecords'
            ? $this->page($this->row()) : array_replace($this->row(), [$field => $value])));
        try { (new DnsProviderClient())->recordSetInventory($credential, $set); $this->fail('Expected exact identity refusal'); }
        catch (DnsException $error) { $this->assertSame('dns_ownership_changed', $error->getMessage()); }
        Http::assertSentCount(2);
    }

    public static function malformedRemarks(): array { return [[null], [false], [12], [[]], [str_repeat('x', 1001)]]; }

    #[DataProvider('malformedRemarks')]
    public function test_explicit_null_and_non_string_remarks_are_not_blank(mixed $remark): void
    {
        [$credential, $set] = $this->scope();
        Http::fake(fn () => Http::response($this->page($this->row() + ['Remark' => $remark])));
        $this->expectException(CloudException::class);
        (new DnsProviderClient())->recordSetInventory($credential, $set);
    }

    public static function refusals(): array
    {
        return [[400, 'InvalidRemark.TooLong', 'provider_rejected'], [200, 'InvalidParameter', 'provider_rejected'],
            [403, 'Forbidden.RAM', 'provider_forbidden'], [429, 'Throttling.User', 'provider_rate_limited'],
            [503, 'InternalError', 'provider_unavailable'], [400, 'InvalidRecordId.NotFound', 'provider_rejected']];
    }

    #[DataProvider('refusals')]
    public function test_refusal_keeps_safe_operation_status_and_code_but_not_body_or_signing_data(int $status, string $code, string $reason): void
    {
        [$credential, $set] = $this->scope();
        Http::fake(fn () => Http::response(['Code' => $code, 'Message' => 'private-sentinel', 'RequestId' => 'private-sentinel'], $status));
        try { (new DnsProviderClient())->recordSetWrite($credential, $set, $this->action('claim'), self::ID); $this->fail('Expected refusal'); }
        catch (DnsException $error) {
            $this->assertSame($reason, $error->getMessage());
            $this->assertSame(['schema' => 1, 'provider' => 'aliyun', 'action' => 'UpdateDomainRecordRemark',
                'http_status' => $status, 'code' => $code], $error->providerDiagnostic());
            $out = \keli_acceptance_cli_failure('initial-dns', $error);
            $this->assertSame($reason, $out['reason']);
            $this->assertSame($error->providerDiagnostic(), $out['dns_provider_diagnostic']);
            foreach (['private-sentinel', 'synthetic-only', 'AccessKey', 'Signature', self::ID, base_path()] as $private) {
                $this->assertStringNotContainsString($private, json_encode($out));
            }
        }
        Http::assertSentCount(1);
    }

    public function test_unknown_error_code_is_redacted_and_does_not_overwrite_first_context(): void
    {
        [$credential, $set] = $this->scope();
        Http::fake(fn () => Http::response(['Code' => 'private-sentinel', 'Message' => 'private-sentinel'], 400));
        try { (new DnsProviderClient())->recordSetWrite($credential, $set, $this->action()); $this->fail('Expected refusal'); }
        catch (DnsException $error) {
            $first = $error->providerDiagnostic();
            $this->assertSame('other', $first['code']);
            $this->assertSame($error, $error->withAliyunDiagnostic('private-sentinel', 599, 'InternalError'));
            $this->assertSame($first, $error->providerDiagnostic());
            $this->assertStringNotContainsString('private-sentinel', json_encode(\keli_acceptance_cli_failure('initial-dns', $error)));
        }
    }

    public function test_claim_rejection_preserves_create_receipt_and_never_repeats_creation(): void
    {
        [$credential, $set] = $this->scope(); $actions = [];
        Http::fake(function ($request) use (&$actions) {
            $actions[] = $request['Action'];
            return Http::response($request['Action'] === 'AddDomainRecord'
                ? ['RequestId' => 'create-receipt', 'RecordId' => self::ID] : ['Code' => 'Forbidden.RAM'],
                $request['Action'] === 'AddDomainRecord' ? 200 : 403);
        });
        $client = new DnsProviderClient(); $receipt = $client->recordSetWrite($credential, $set, $this->action());
        try { $client->recordSetWrite($credential, $set, $this->action('claim'), $receipt); $this->fail('Expected refusal'); }
        catch (DnsException $error) { $this->assertSame('UpdateDomainRecordRemark', $error->providerDiagnostic()['action']); }
        $this->assertSame(self::ID, $receipt);
        $this->assertSame(['AddDomainRecord', 'UpdateDomainRecordRemark'], $actions);
        Http::assertSentCount(2);
    }

    public function test_connection_failure_is_unconfirmed_not_absence_or_a_new_create(): void
    {
        [$credential, $set] = $this->scope();
        Http::fake(fn () => throw new ConnectionException('private-sentinel'));
        try { (new DnsProviderClient())->recordSetWrite($credential, $set, $this->action()); $this->fail('Expected unconfirmed outcome'); }
        catch (DnsRequestFailure $error) {
            $this->assertSame('provider_unavailable', $error->getMessage());
            $this->assertSame(['schema' => 1, 'provider' => 'aliyun', 'action' => 'AddDomainRecord',
                'http_status' => null, 'code' => null], $error->providerDiagnostic());
        }
    }
}

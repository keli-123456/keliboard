<?php
namespace Tests\Unit\Services;

use App\Models\MachineDnsCredential;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Dns\DnsException;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberDnsInitialization as Setup;
use Keli\Acceptance\MemberProviderBindings as Provider;
use Tests\TestCase;

class MachineMemberDnsInitializationTest extends TestCase
{
    private array $journal = [];
    private array $rows = [];
    private array $writes = [];
    private string $mode = '';
    private bool $stopped = false;
    private bool $dropSave = false;
    private int $inventoryReads = 0;
    private MachineDnsCredential $credential;
    private array $scope;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('tools/machine-acceptance/member_provider_bindings.php');
        require_once base_path('tools/machine-acceptance/member_dns_initialization.php');
        app()->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('t', 32), 'AES-256-CBC'));
        Http::preventStrayRequests();
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate {
            public function assertAllowed(string $operation): void {
                if (!in_array($operation, ['dns_set_read', 'dns_set_write'], true)) throw new \RuntimeException('fixture_gate');
            }
        });
        $this->credential = (new MachineDnsCredential())->forceFill(['id' => 1, 'provider' => 'aliyun', 'revision' => 1,
            'credentials' => ['access_key_id' => 'fixture-id', 'access_key_secret' => 'fixture-secret']]);
        $this->scope = ['run_id' => Provider::RUN, 'credential_id' => 1, 'credential_revision' => 1,
            'records' => ['edge-a' => ['value' => '8.8.8.8', 'marker' => 'keli-set-' . str_repeat('a', 48)]]];
        Http::fake(function ($r) {
            $action = $r['Action'];
            if ($action === 'DescribeSubDomainRecords') {
                if (++$this->inventoryReads === 2 && $this->mode === 'name-race') {
                    $this->rows['9001'] = ['RecordId' => '9001', 'DomainName' => 'dnscloudcloud.top',
                        'RR' => 'edge-a-' . Provider::RUN, 'Type' => 'A', 'Value' => '9.9.9.9',
                        'Line' => 'default', 'TTL' => 600, 'Remark' => '', 'Status' => 'ENABLE'];
                }
                return Http::response(['TotalCount' => count($this->rows), 'PageNumber' => 1,
                    'PageSize' => 50, 'DomainRecords' => ['Record' => array_values($this->rows)]]);
            }
            if ($action === 'DescribeDomainRecordInfo') return isset($this->rows[$r['RecordId']])
                ? Http::response($this->rows[$r['RecordId']]) : Http::response(['Code' => 'InvalidRR.NoExist'], 400);
            $this->writes[] = $action;
            if ($action === 'AddDomainRecord') {
                if ($this->mode === 'reject-create') return Http::response(['Code' => 'Forbidden'], 403);
                $this->rows['5001'] = ['RecordId' => '5001', 'DomainName' => 'dnscloudcloud.top', 'RR' => $r['RR'],
                    'Type' => $r['Type'], 'Value' => $r['Value'], 'Line' => 'default', 'TTL' => 600, 'Remark' => '', 'Status' => 'ENABLE'];
                if ($this->mode === 'lost-create') throw new ConnectionException('must-not-persist-provider-secret');
            } elseif ($action === 'UpdateDomainRecordRemark') {
                if ($this->mode === 'reject-claim') return Http::response(['Code' => 'Forbidden.RAM'], 403);
                $this->rows[$r['RecordId']]['Remark'] = $r['Remark'];
                if ($this->mode === 'lost-claim') throw new ConnectionException('must-not-persist-provider-secret');
            } elseif ($action === 'DeleteDomainRecord') unset($this->rows[$r['RecordId']]);
            else throw new \RuntimeException('unexpected_provider_operation');
            return Http::response(['RecordId' => '5001', 'RequestId' => 'fixture-request']);
        });
    }

    private function save(string $key, array $value): void { if (!$this->dropSave) $this->journal[$key] = $value; }
    private function setupDns(): Setup
    {
        return new Setup($this->scope, $this->credential, function ($operation): void {
            if ($this->stopped && $operation !== 'dns-read') throw new \RuntimeException('stopped');
        }, fn ($key) => $this->journal[$key] ?? null, $this->save(...));
    }

    private function cleanup(array $pin): Provider
    {
        return new Provider(['run_id' => Provider::RUN, 'baseline_ids' => [], 'pins' => [Provider::digest($pin)]],
            ['api_token' => 'fixture-cloud-unused'], $this->credential->credentials, fn () => null,
            fn ($key) => $this->journal[$key] ?? null, $this->save(...));
    }

    public function test_real_provider_create_claim_readback_is_exact_and_not_repeated(): void
    {
        $before = config('machine_failover');
        $result = $this->setupDns()->advance('edge-a');
        $this->assertTrue($result['ready']);
        $this->assertSame(['AddDomainRecord', 'UpdateDomainRecordRemark'], $this->writes);
        $this->assertSame(MachineDnsRecordSetProvider::aliyunMarker($result['pin']['marker']), $this->rows['5001']['Remark']);
        $this->assertSame($result, $this->setupDns()->advance('edge-a'));
        $this->assertCount(2, $this->writes);
        $this->assertTrue($this->setupDns()->readback('edge-a')['ready']);
        $this->assertSame($before, config('machine_failover'));
        $this->assertStringNotContainsString('fixture-secret', json_encode($this->journal));
        Http::assertSent(fn ($r) => $r['Action'] === 'AddDomainRecord' && $r->url() === 'https://alidns.aliyuncs.com/'
            && is_string($r['Signature']) && $r['Type'] === 'A' && $r['Line'] === 'default' && $r['TTL'] === 600);
    }

    public function test_cleanup_receipt_uses_acknowledged_id_without_inventory_or_write(): void
    {
        $pin = $this->setupDns()->advance('edge-a')['pin'];
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        $this->assertSame($pin, $this->setupDns()->cleanupReceipt('edge-a'));
        Http::assertNothingSent();
    }

    public function test_cleanup_receipt_does_not_invent_identity_after_lost_create_ack(): void
    {
        $this->mode = 'lost-create';
        try { $this->setupDns()->advance('edge-a'); $this->fail(); } catch (DnsException) { }
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        $this->expectExceptionMessage('member_dns_initialization_create_unconfirmed');
        $this->setupDns()->cleanupReceipt('edge-a');
    }

    public function test_existing_name_is_not_adopted(): void
    {
        $this->setupDns()->advance('edge-a'); $this->journal = []; $this->writes = [];
        $this->expectExceptionMessage('member_dns_initialization_name_not_fresh');
        try { $this->setupDns()->advance('edge-a'); }
        finally { $this->assertSame([], $this->writes); $this->assertSame([], $this->journal); }
    }

    public function test_name_added_after_first_inventory_prevents_post(): void
    {
        $this->mode = 'name-race';
        $this->expectExceptionMessage('member_dns_initialization_name_not_fresh');
        try { $this->setupDns()->advance('edge-a'); }
        finally { $this->assertSame([], $this->writes); $this->assertFalse(array_values($this->journal)[0]['create_sent']); }
    }

    public function test_lost_create_ack_is_unresolved_without_any_repeated_post(): void
    {
        $this->mode = 'lost-create';
        try { $this->setupDns()->advance('edge-a'); $this->fail(); } catch (DnsException) {}
        foreach (['advance', 'readback'] as $method) {
            try { $this->setupDns()->{$method}('edge-a'); $this->fail(); }
            catch (\RuntimeException $e) { $this->assertSame('member_dns_initialization_create_unconfirmed', $e->getMessage()); }
        }
        $this->assertSame(['AddDomainRecord'], $this->writes);
        $this->assertStringNotContainsString('must-not-persist', json_encode($this->journal));
    }

    public function test_lost_claim_ack_is_reconciled_from_matching_marker_without_replay(): void
    {
        $this->mode = 'lost-claim';
        try { $this->setupDns()->advance('edge-a'); $this->fail(); } catch (DnsException) {}
        $this->stopped = true;
        $this->assertTrue($this->setupDns()->readback('edge-a')['ready']);
        $this->stopped = false;
        $this->assertTrue($this->setupDns()->advance('edge-a')['ready']);
        $this->assertSame(['AddDomainRecord', 'UpdateDomainRecordRemark'], $this->writes);
    }

    public function test_unclaimed_creation_can_be_cleaned_only_with_our_exact_ack(): void
    {
        $this->mode = 'reject-claim';
        try { $this->setupDns()->advance('edge-a'); $this->fail(); } catch (DnsException) {}
        $this->stopped = true;
        $result = $this->setupDns()->readback('edge-a');
        $this->assertFalse($result['ready']); $pin = $result['pin'];
        $this->assertTrue($this->cleanup($pin)->dispatch('dns-delete', $pin)['dispatched']);
        $this->assertSame('absent', $this->cleanup($pin)->dispatch('dns-inspect', $pin)['status']);
        $this->assertSame(['AddDomainRecord', 'UpdateDomainRecordRemark', 'DeleteDomainRecord'], $this->writes);
    }

    public function test_claimed_record_with_removed_or_foreign_marker_is_not_deleted(): void
    {
        $pin = $this->setupDns()->advance('edge-a')['pin'];
        foreach (['', 'foreign'] as $marker) {
            $this->rows['5001']['Remark'] = $marker;
            try { $this->cleanup($pin)->dispatch('dns-delete', $pin); $this->fail(); }
            catch (\RuntimeException $e) { $this->assertStringStartsWith('member_provider_dns_', $e->getMessage()); }
        }
        $this->assertNotContains('DeleteDomainRecord', $this->writes);
    }

    public function test_blank_record_with_missing_or_mismatched_ack_is_not_deleted(): void
    {
        $this->mode = 'reject-claim';
        try { $this->setupDns()->advance('edge-a'); $this->fail(); } catch (DnsException) {}
        $pin = $this->setupDns()->readback('edge-a')['pin'];
        $original = $this->journal;
        foreach (['missing', 'id', 'value'] as $change) {
            $this->journal = $original;
            if ($change === 'missing') $this->journal = [];
            else foreach ($this->journal as &$state) {
                if ($change === 'id') $state['created_id'] = '9001';
                else $state['request']['value'] = '9.9.9.9';
            }
            unset($state);
            try { $this->cleanup($pin)->dispatch('dns-delete', $pin); $this->fail(); }
            catch (\RuntimeException $e) { $this->assertSame('member_provider_dns_identity_changed', $e->getMessage()); }
        }
        $this->assertNotContains('DeleteDomainRecord', $this->writes);
    }

    public function test_definite_rejection_retains_intent_and_has_no_cleanup_resource(): void
    {
        $this->mode = 'reject-create';
        try { $this->setupDns()->advance('edge-a'); $this->fail(); } catch (DnsException) {}
        $this->assertSame(['resolved' => true, 'pin' => null, 'ready' => false], $this->setupDns()->readback('edge-a'));
        $this->expectExceptionMessage('member_dns_initialization_create_rejected');
        try { $this->setupDns()->advance('edge-a'); } finally { $this->assertSame(['AddDomainRecord'], $this->writes); }
    }

    public function test_unconfirmed_journal_and_stopped_setup_do_not_create(): void
    {
        $this->dropSave = true;
        try { $this->setupDns()->advance('edge-a'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_dns_initialization_journal_unconfirmed', $e->getMessage()); }
        $this->dropSave = false; $this->stopped = true;
        try { $this->setupDns()->advance('edge-a'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('stopped', $e->getMessage()); }
        $this->assertSame([], $this->writes);
    }

    public function test_scope_and_state_drift_rejected_but_json_key_order_is_not_identity(): void
    {
        $this->setupDns()->advance('edge-a');
        foreach ($this->journal as &$state) { ksort($state); ksort($state['request']); }
        unset($state);
        $this->assertTrue($this->setupDns()->readback('edge-a')['ready']);
        $this->scope['records']['edge-a']['value'] = '9.9.9.9';
        $this->expectExceptionMessage('member_dns_initialization_journal_changed');
        $this->setupDns()->readback('edge-a');
    }
}

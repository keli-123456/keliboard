<?php
namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MachineDnsRecordSetProviderTest extends TestCase
{
    private function set(string $provider): array { return ['provider' => $provider, 'zone' => $provider === 'cloudflare' ? str_repeat('a', 32) : 'example.test',
        'hostname' => 'node.example.test', 'ttl' => $provider === 'cloudflare' ? 60 : 600]; }
    private function row(string $provider, int $id = 1): array
    {
        return $provider === 'cloudflare' ? ['id' => str_pad(dechex($id), 32, '0', STR_PAD_LEFT), 'name' => 'node.example.test', 'type' => 'AAAA',
            'content' => '2001:db8::1', 'ttl' => 60, 'proxied' => false, 'comment' => 'keli-set-' . str_repeat('a', 48)]
            : ['RecordId' => '9000000000000000000' . $id, 'DomainName' => 'example.test', 'RR' => 'node', 'Type' => 'AAAA', 'Value' => '2001:db8::1',
                'TTL' => 600, 'Line' => 'telecom', 'Status' => 'Enable', 'Locked' => false, 'Remark' => 'keli-set-' . str_repeat('a', 48)];
    }
    private function page(string $provider, array $rows, int $page = 1, ?int $total = null): array
    {
        $total ??= count($rows);
        return $provider === 'cloudflare' ? ['success' => true, 'result' => $rows, 'result_info' => ['page' => $page, 'per_page' => 50, 'total_count' => $total, 'total_pages' => max(1, (int) ceil($total / 50))]]
            : ['PageNumber' => $page, 'PageSize' => 50, 'TotalCount' => $total, 'DomainRecords' => ['Record' => $rows]];
    }
    public static function providers(): array { return [['cloudflare'], ['aliyun']]; }
    #[DataProvider('providers')]
    public function test_complete_bounded_inventory_and_long_record_ids(string $provider): void
    {
        $client = new MachineDnsRecordSetProvider(); $rows = array_map(fn ($id) => $this->row($provider, $id), range(1, 51));
        $result = $client->inventory($this->set($provider), [$this->page($provider, array_slice($rows, 0, 50), 1, 51), $this->page($provider, array_slice($rows, 50), 2, 51)]);
        $this->assertCount(51, $result); $this->assertIsString($result[0]['id']); $this->assertSame('AAAA', $result[0]['type']);
        $this->assertSame($provider === 'aliyun' ? 'telecom' : 'default', $result[0]['line']);
        $this->assertSame([], $client->inventory($this->set($provider), [$this->page($provider, [])]));
    }
    public static function invalidInventory(): array
    {
        $out = [];
        foreach (['cloudflare', 'aliyun'] as $provider) foreach (['truncated', 'duplicate', 'wrong_page', 'wrong_size', 'changed_total', 'too_many_pages', 'missing_metadata', 'foreign_hostname'] as $kind)
            $out[$provider . ':' . $kind] = [$provider, $kind];
        return $out;
    }
    #[DataProvider('invalidInventory')]
    public function test_incomplete_or_inconsistent_inventory_cannot_be_used_as_an_empty_set(string $provider, string $kind): void
    {
        $row = $this->row($provider); $pages = [$this->page($provider, [$row])];
        switch ($kind) {
            case 'truncated': $pages = [$this->page($provider, [$row], 1, 2)]; break;
            case 'duplicate': $pages = [$this->page($provider, [$row, $row])]; break;
            case 'wrong_page': $pages = [$this->page($provider, [$row], 2)]; break;
            case 'wrong_size': $pages[0][$provider === 'cloudflare' ? 'result_info' : 'PageSize'] = $provider === 'cloudflare' ? ['page' => 1, 'per_page' => 20, 'total_count' => 1, 'total_pages' => 1] : 20; break;
            case 'changed_total': $pages = [$this->page($provider, array_map(fn ($id) => $this->row($provider, $id), range(1, 50)), 1, 51), $this->page($provider, [$this->row($provider, 51)], 2, 52)]; break;
            case 'too_many_pages': $pages = array_fill(0, 4, $pages[0]); break;
            case 'missing_metadata': $pages = [$provider === 'cloudflare' ? ['success' => true, 'result' => []] : ['DomainRecords' => ['Record' => []]]]; break;
            case 'foreign_hostname': $row[$provider === 'cloudflare' ? 'name' : 'RR'] = 'foreign'; $pages = [$this->page($provider, [$row])]; break;
        }
        $this->expectException(CloudException::class); (new MachineDnsRecordSetProvider())->inventory($this->set($provider), $pages);
    }
    #[DataProvider('providers')]
    public function test_provider_native_mutations_preserve_family_line_and_ownership_without_transport(string $provider): void
    {
        $client = new MachineDnsRecordSetProvider(); $set = $this->set($provider); $row = $client->record($set, $this->row($provider));
        $after = array_replace($row, ['content' => '2001:db8::2']);
        $action = ['kind' => 'update', 'before' => $row, 'after' => $after]; $wire = $client->mutation($set, $action);
        $this->assertSame($provider === 'cloudflare' ? 'PATCH' : 'POST', $wire['method']);
        if ($provider === 'aliyun') {
            $this->assertSame('UpdateDomainRecord', $wire['action']); $this->assertSame('telecom', $wire['parameters']['Line']);
            $this->assertSame($row['id'], $wire['parameters']['RecordId']); $this->assertSame('AAAA', $wire['parameters']['Type']);
            $create = $client->mutation($set, array_replace($action, ['kind' => 'create', 'before' => null]));
            $this->assertSame('AddDomainRecord', $create['action']); $this->assertArrayNotHasKey('Remark', $create['parameters']);
            $claim = $client->mutation($set, array_replace($action, ['kind' => 'claim']), $row['id']);
            $this->assertSame('UpdateDomainRecordRemark', $claim['action']); $this->assertSame($row['marker'], $claim['parameters']['Remark']);
        } else {
            $this->assertStringStartsWith('/zones/' . str_repeat('a', 32) . '/dns_records/', $wire['path']);
            $this->assertFalse($wire['parameters']['proxied']); $this->assertSame($row['marker'], $wire['parameters']['comment']);
        }
        $this->assertStringNotContainsString('secret', json_encode($wire));
    }
    public function test_malformed_aliyun_status_and_unclaimed_mutation_are_rejected(): void
    {
        $client = new MachineDnsRecordSetProvider(); $row = $this->row('aliyun'); $row['Status'] = [];
        try { $client->record($this->set('aliyun'), $row); $this->fail('Expected typed failure'); } catch (CloudException $e) { $this->assertSame('dns_inventory_invalid', $e->getMessage()); }
        $row = $client->record($this->set('aliyun'), $this->row('aliyun')); $row['marker'] = '';
        $this->expectException(CloudException::class); $client->mutation($this->set('aliyun'), ['kind' => 'delete', 'before' => $row, 'after' => null]);
    }
}

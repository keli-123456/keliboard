<?php
namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MachineDnsRecordSetPlannerTest extends TestCase
{
    private function fixture(): array
    {
        $snapshot = ['set' => ['hostname' => 'shared.example.test', 'ttl' => 60],
            'members' => [['id' => 1, 'enabled' => true], ['id' => 2, 'enabled' => true]],
            'slots' => [['id' => 1, 'member_id' => 1, 'type' => 'A', 'line' => 'default', 'marker' => 'keli-set-' . str_repeat('a', 48)],
                ['id' => 2, 'member_id' => 2, 'type' => 'A', 'line' => 'default', 'marker' => 'keli-set-' . str_repeat('b', 48)],
                ['id' => 3, 'member_id' => 2, 'type' => 'AAAA', 'line' => 'default', 'marker' => 'keli-set-' . str_repeat('c', 48)]]];
        $addresses = [1 => ['A' => '198.51.100.1'], 2 => ['A' => '192.0.2.2', 'AAAA' => '2001:db8::2']];
        $records = array_map(fn ($slot) => ['id' => str_pad((string) $slot['id'], 32, '0', STR_PAD_LEFT), 'hostname' => 'shared.example.test',
            'type' => $slot['type'], 'line' => $slot['line'], 'marker' => $slot['marker'], 'ttl' => 60, 'proxied' => false, 'locked' => false,
            'content' => $slot['id'] === 1 ? '192.0.2.1' : $addresses[2][$slot['type']]], $snapshot['slots']);
        return [$snapshot, $addresses, $records];
    }
    public function test_single_stack_member_change_preserves_another_members_a_and_aaaa(): void
    {
        [$s, $a, $r] = $this->fixture(); $plan = (new MachineDnsRecordSetPlanner())->plan($s, $a, $r, 'replace', 1);
        $this->assertCount(1, $plan['actions']); $this->assertSame('update', $plan['actions'][0]['kind']);
        $this->assertSame(1, $plan['actions'][0]['member_id']); $this->assertSame('198.51.100.1', $plan['actions'][0]['after']['content']);
        $this->assertSame(array_slice($r, 1), $plan['protected']); $this->assertFalse($plan['propagation_verified']);
    }
    public function test_unmanaged_unselected_family_and_txt_records_are_not_deleted_or_adopted(): void
    {
        [$s, $a, $r] = $this->fixture(); $s['slots'] = array_slice($s['slots'], 0, 1); $s['members'] = array_slice($s['members'], 0, 1);
        $r = [$r[0], array_replace($r[2], ['marker' => '']), array_replace($r[1], ['marker' => '', 'type' => 'TXT', 'content' => 'verification'])];
        $plan = (new MachineDnsRecordSetPlanner())->plan($s, $a, $r, 'replace', 1);
        $this->assertCount(2, $plan['foreign']); $this->assertCount(1, $plan['actions']); $this->assertSame('update', $plan['actions'][0]['kind']);
    }
    public static function unsafe(): array
    {
        return [['shared_address', 'dns_shared_address'], ['wrong_family', 'dns_address_missing'], ['missing_other_ipv6', 'dns_address_missing'],
            ['foreign_cname', 'dns_foreign_record'], ['orphan_marker', 'dns_orphan_record'], ['duplicate_id', 'dns_inventory_invalid'],
            ['wrong_name', 'dns_inventory_invalid'], ['changed_line', 'dns_ownership_changed'], ['locked', 'dns_ownership_changed'],
            ['proxied', 'dns_ownership_changed'], ['duplicate_marker', 'dns_ownership_changed'], ['untargeted_change', 'dns_untargeted_change']];
    }
    #[DataProvider('unsafe')]
    public function test_ambiguous_identity_or_partial_member_evidence_blocks_the_plan(string $scenario, string $reason): void
    {
        [$s, $a, $r] = $this->fixture();
        switch ($scenario) {
            case 'shared_address': $a[1]['A'] = $a[2]['A']; break;
            case 'wrong_family': $a[1]['A'] = '2001:db8::1'; break;
            case 'missing_other_ipv6': unset($a[2]['AAAA']); break;
            case 'foreign_cname': $r[] = array_replace($r[0], ['id' => str_repeat('e', 32), 'type' => 'CNAME', 'content' => 'foreign.example.test', 'marker' => '']); break;
            case 'orphan_marker': $r[] = array_replace($r[0], ['id' => str_repeat('e', 32), 'marker' => 'keli-set-' . str_repeat('e', 48)]); break;
            case 'duplicate_id': $r[] = $r[0]; break;
            case 'wrong_name': $r[0]['hostname'] = 'other.example.test'; break;
            case 'changed_line': $r[0]['line'] = 'telecom'; break;
            case 'locked': $r[0]['locked'] = true; break;
            case 'proxied': $r[0]['proxied'] = true; break;
            case 'duplicate_marker': $r[] = array_replace($r[0], ['id' => str_repeat('e', 32)]); break;
            case 'untargeted_change': $a[2]['A'] = '198.51.100.2'; break;
        }
        try { (new MachineDnsRecordSetPlanner())->plan($s, $a, $r, 'replace', 1); $this->fail('Expected blocked plan'); }
        catch (CloudException $e) { $this->assertSame($reason, $e->getMessage()); }
    }
    public function test_explicit_detach_only_removes_owned_target_records_and_keeps_other_families(): void
    {
        [$s, $a, $r] = $this->fixture(); $plan = (new MachineDnsRecordSetPlanner())->plan($s, $a, $r, 'detach', 1);
        $this->assertSame('delete', $plan['actions'][0]['kind']); $this->assertCount(1, $plan['actions']);
        $this->assertSame(array_slice($r, 1), $plan['protected']); $this->assertCount(2, $plan['expected']);
    }
}

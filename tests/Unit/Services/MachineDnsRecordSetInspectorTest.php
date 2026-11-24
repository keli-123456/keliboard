<?php

namespace Tests\Unit\Services;

use App\Services\ServerMachine\Dns\MachineDnsRecordSetInspector;
use PHPUnit\Framework\TestCase;

final class MachineDnsRecordSetInspectorTest extends TestCase
{
    public function test_peer_members_on_the_same_line_match_only_with_distinct_addresses_and_markers(): void
    {
        $slot = ['type' => 'A', 'line' => 'telecom'];
        $slots = [$slot + ['id' => 1, 'member_id' => 1, 'marker' => 'keli-a'], $slot + ['id' => 2, 'member_id' => 2, 'marker' => 'keli-b']];
        $members = [1 => ['enabled' => true], 2 => ['enabled' => true]];
        $a = '192.0.2.1'; $b = '192.0.2.2';
        $addresses = [1 => ['A' => $a], 2 => ['A' => $b]];
        $record = $slot + ['ttl' => 600, 'locked' => false, 'proxied' => false];
        $rows = [$record + ['id' => '1', 'content' => $a, 'marker' => 'keli-a'], $record + ['id' => '2', 'content' => $b, 'marker' => 'keli-b']];
        $compare = fn ($records) => array_column((new MachineDnsRecordSetInspector())->compare(['ttl' => 600], $slots, $members, $addresses, $records), 'reason');
        $this->assertSame(['exact_match', 'exact_match'], $compare($rows));
        $this->assertSame(['shared_address', 'address_mismatch'], $compare([$rows[0], array_replace($rows[1], ['content' => $a])]));
        $this->assertSame(['duplicate_marker', 'missing_record'], $compare([$rows[0], array_replace($rows[1], ['marker' => 'keli-a'])]));
        $this->assertSame(['routing_changed', 'exact_match'], $compare([array_replace($rows[0], ['line' => 'unicom']), $rows[1]]));
        $this->assertSame(['record_protected', 'ttl_mismatch'], $compare([array_replace($rows[0], ['locked' => true]), array_replace($rows[1], ['ttl' => 900])]));
        $this->assertSame(['alias_conflict', 'alias_conflict'], $compare([...$rows, array_replace($rows[0], ['id' => '3', 'type' => 'CNAME', 'marker' => ''])]));
    }
}

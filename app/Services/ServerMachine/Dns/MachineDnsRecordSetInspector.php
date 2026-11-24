<?php

namespace App\Services\ServerMachine\Dns;

final class MachineDnsRecordSetInspector
{
    public function compare(array $set, array $slots, array $members, array $addresses, array $records): array
    {
        $markers = array_column($slots, null, 'marker');
        $owned = $foreign = $expectedCounts = $observedCounts = [];
        $alias = false;
        foreach ($records as $record) {
            $alias = $alias || $record['type'] === 'CNAME';
            $slot = $markers[$record['marker']] ?? null;
            $scope = $record['type'] . ':' . $record['line'];
            if (in_array($record['type'], ['A', 'AAAA'], true)) {
                $key = $scope . ':' . $record['content'];
                $observedCounts[$key] = ($observedCounts[$key] ?? 0) + 1;
            }
            if ($slot) $owned[$slot['id']][] = $record;
            if (!$slot || $scope !== ($slot['type'] . ':' . $slot['line'])) $foreign[$scope][] = $record;
        }
        foreach ($slots as $slot) {
            $ip = $addresses[$slot['member_id']][$slot['type']] ?? null;
            if ($ip !== null && $members[$slot['member_id']]['enabled']) {
                $key = $slot['type'] . ':' . $slot['line'] . ':' . $ip;
                $expectedCounts[$key] = ($expectedCounts[$key] ?? 0) + 1;
            }
        }
        $rows = [];
        foreach ($slots as $slot) {
            $scope = $slot['type'] . ':' . $slot['line'];
            $ip = $addresses[$slot['member_id']][$slot['type']] ?? null;
            $claims = $owned[$slot['id']] ?? [];
            $claim = $claims[0] ?? null;
            $others = $foreign[$scope] ?? [];
            $records = array_column([...$claims, ...$others], null, 'id');
            $inScope = array_filter($records, fn ($row) => $row['type'] === $slot['type']);
            $claimed = count($claims) === 1 && $claim['type'] === $slot['type'] && $claim['line'] === $slot['line'];
            $addressKey = $scope . ':' . $ip;
            [$state, $reason] = match (true) {
                !$members[$slot['member_id']]['enabled'] => ['unconfirmed', 'member_disabled'],
                $alias => ['conflict', 'alias_conflict'],
                count($claims) > 1 => ['conflict', 'duplicate_marker'],
                $claim !== null && !$claimed => ['conflict', 'routing_changed'],
                count(array_filter($records, fn ($r) => $r['locked'] || $r['proxied'])) > 0 => ['conflict', 'record_protected'],
                $ip !== null && (($expectedCounts[$addressKey] ?? 0) > 1 || ($observedCounts[$addressKey] ?? 0) > 1) => ['conflict', 'shared_address'],
                !$claim && count($others) === 1 && $ip !== null && $others[0]['content'] === $ip
                    && !str_starts_with($others[0]['marker'], 'keli-') => ['unconfirmed', 'ownership_unconfirmed'],
                !$claim && count($others) === 1 && $ip === null && !str_starts_with($others[0]['marker'], 'keli-') => ['unconfirmed', 'address_unconfirmed'],
                $others !== [] => ['conflict', 'foreign_record'],
                !$claim => ['unconfirmed', 'missing_record'],
                $ip === null => ['unconfirmed', 'address_unconfirmed'],
                $claim['content'] !== $ip => ['conflict', 'address_mismatch'],
                $claim['ttl'] !== $set['ttl'] => ['conflict', 'ttl_mismatch'],
                default => ['match', 'exact_match'],
            };
            $rows[] = ['slot_id' => $slot['id'], 'member_id' => $slot['member_id'], 'type' => $slot['type'], 'line' => $slot['line'],
                'state' => $state, 'reason' => $reason, 'panel_marker' => $claimed, 'expected_ip' => $ip,
                'observed_ips' => array_slice(array_values(array_unique(array_column($inScope, 'content'))), 0, 8),
                'record_count' => count($records)];
        }
        return $rows;
    }
}

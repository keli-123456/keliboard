<?php
namespace App\Services\ServerMachine\Dns;

use App\Services\ServerMachine\Cloud\CloudException;

// A plan is not authorization. The caller must prove source addresses and complete inventory.
class MachineDnsRecordSetPlanner
{
    public function plan(array $snapshot, array $addresses, array $records, string $mode = 'replace', ?int $target = null): array
    {
        if (!in_array($mode, ['replace', 'detach', 'restore'], true) || !array_is_list($records) || count($records) > 150) throw new CloudException('invalid_dns_plan');
        $set = $snapshot['set']; $slots = $snapshot['slots']; $members = array_column($snapshot['members'], null, 'id');
        if ($target !== null && !in_array($target, array_column($slots, 'member_id'), true)) throw new CloudException('invalid_dns_members');
        if ($mode === 'detach' && $target === null) throw new CloudException('invalid_dns_members');
        $markers = array_column($slots, 'id', 'marker'); $owned = []; $ids = []; $foreign = [];
        foreach ($records as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || isset($ids[$row['id']]) || ($row['hostname'] ?? null) !== $set['hostname']) throw new CloudException('dns_inventory_invalid');
            $ids[$row['id']] = true;
            if (isset($markers[$row['marker'] ?? ''])) {
                $slotId = $markers[$row['marker']];
                if (isset($owned[$slotId])) throw new CloudException('dns_ownership_changed');
                $owned[$slotId] = $row;
            } else {
                if (str_starts_with($row['marker'] ?? '', 'keli-set-')) throw new CloudException('dns_orphan_record');
                $foreign[] = $row;
            }
        }
        $actions = []; $expected = []; $keys = []; $protected = []; $activeDesired = 0;
        foreach ($slots as $slot) {
            $member = $members[$slot['member_id']] ?? null;
            if (!$member) throw new CloudException('invalid_dns_members');
            $before = $owned[$slot['id']] ?? null;
            if ($before && ($before['type'] !== $slot['type'] || $before['line'] !== $slot['line'] || $before['locked'] || $before['proxied'])) throw new CloudException('dns_ownership_changed');
            foreach ($foreign as $row) if ($row['type'] === 'CNAME' || $row['type'] === $slot['type'] && $row['line'] === $slot['line']) throw new CloudException('dns_foreign_record');
            if ($mode === 'detach' && $slot['member_id'] === $target) {
                if ($before) $actions[] = ['kind' => 'delete', 'slot_id' => $slot['id'], 'member_id' => $slot['member_id'], 'before' => $before, 'after' => null];
                continue;
            }
            if (!$member['enabled']) {
                if ($before) { $expected[] = $before; $protected[] = $before; }
                continue;
            }
            $value = $addresses[$slot['member_id']][$slot['type']] ?? null;
            if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP, $slot['type'] === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) throw new CloudException('dns_address_missing');
            $value = inet_ntop(inet_pton($value)); $key = $slot['type'] . ':' . $slot['line'] . ':' . $value;
            $activeDesired++;
            if (isset($keys[$key])) throw new CloudException('dns_shared_address');
            $keys[$key] = true;
            $after = ['hostname' => $set['hostname'], 'type' => $slot['type'], 'line' => $slot['line'], 'content' => $value,
                'ttl' => $set['ttl'], 'marker' => $slot['marker'], 'proxied' => false, 'locked' => false];
            $expected[] = $after;
            if ($before && $this->same($before, $after)) { $protected[] = $before; continue; }
            if ($target !== null && $slot['member_id'] !== $target) throw new CloudException('dns_untargeted_change');
            $actions[] = ['kind' => $before ? 'update' : 'create', 'slot_id' => $slot['id'], 'member_id' => $slot['member_id'], 'before' => $before, 'after' => $after];
        }
        if (!$expected || $mode === 'detach' && !$activeDesired) throw new CloudException('dns_last_member');
        usort($actions, fn ($a, $b) => (($a['kind'] === 'delete') <=> ($b['kind'] === 'delete')) ?: $a['slot_id'] <=> $b['slot_id']);
        return ['actions' => $actions, 'expected' => $expected, 'protected' => $protected, 'foreign' => $foreign,
            'inventory_hash' => hash('sha256', json_encode($records, JSON_THROW_ON_ERROR)), 'propagation_verified' => false];
    }

    public function same(array $a, array $b): bool
    {
        foreach (['hostname', 'type', 'line', 'content', 'ttl', 'marker', 'proxied', 'locked'] as $field) if (($a[$field] ?? null) !== ($b[$field] ?? null)) return false;
        return true;
    }
}

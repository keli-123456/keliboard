<?php
namespace App\Services\ServerMachine\Dns;

use App\Services\ServerMachine\Cloud\CloudException;

// Provider wire contracts for offline rehearsal. No HTTP client or credentials are accepted here.
class MachineDnsRecordSetProvider
{
    public const LINES = ['default', 'telecom', 'unicom', 'mobile', 'edu', 'oversea'];
    public function assertSet(array $set): void { $this->scope($set); }

    public function inventory(array $set, array $pages): array
    {
        $this->scope($set);
        if (!array_is_list($pages) || !$pages || count($pages) > 3) throw new CloudException('dns_inventory_incomplete');
        $result = []; $seen = []; $total = null;
        foreach ($pages as $index => $page) {
            if (!is_array($page)) throw new CloudException('dns_inventory_incomplete');
            if ($set['provider'] === 'cloudflare') {
                $meta = $page['result_info'] ?? [];
                if (($page['success'] ?? null) !== true || ($meta['page'] ?? null) !== $index + 1 || ($meta['per_page'] ?? null) !== 50)
                    throw new CloudException('dns_inventory_incomplete');
                $count = $meta['total_count'] ?? null; $rows = $page['result'] ?? null;
                if (!is_int($count) || ($count === 0 ? !in_array($meta['total_pages'] ?? null, [0, 1], true)
                    : ($meta['total_pages'] ?? null) !== (int) ceil($count / 50))) throw new CloudException('dns_inventory_incomplete');
            } else {
                if (($page['PageNumber'] ?? null) !== $index + 1 || ($page['PageSize'] ?? null) !== 50) throw new CloudException('dns_inventory_incomplete');
                $count = $page['TotalCount'] ?? null; $rows = $page['DomainRecords']['Record'] ?? null;
            }
            if (!is_int($count) || $count < 0 || $count > 150 || $total !== null && $total !== $count
                || !is_array($rows) || !array_is_list($rows) || count($rows) > 50) throw new CloudException('dns_inventory_incomplete');
            $total = $count;
            foreach ($rows as $row) {
                $record = $this->record($set, $row);
                if (isset($seen[$record['id']])) throw new CloudException('dns_inventory_incomplete');
                $seen[$record['id']] = true; $result[] = $record;
            }
            if ($index + 1 < count($pages) && count($rows) !== 50) throw new CloudException('dns_inventory_incomplete');
        }
        if (count($pages) !== max(1, (int) ceil($total / 50)) || count($result) !== $total) throw new CloudException('dns_inventory_incomplete');
        return $result;
    }

    public function record(array $set, mixed $row): array
    {
        $this->scope($set);
        if (!is_array($row)) throw new CloudException('dns_inventory_invalid');
        $cf = $set['provider'] === 'cloudflare';
        $id = $row[$cf ? 'id' : 'RecordId'] ?? null;
        $type = $row[$cf ? 'type' : 'Type'] ?? null; $content = $row[$cf ? 'content' : 'Value'] ?? null;
        $name = $cf ? ($row['name'] ?? null) : (($row['RR'] ?? null) === '@' ? ($row['DomainName'] ?? null)
            : (is_string($row['RR'] ?? null) && is_string($row['DomainName'] ?? null) ? $row['RR'] . '.' . $row['DomainName'] : null));
        $ttl = $row[$cf ? 'ttl' : 'TTL'] ?? null; $marker = $row[$cf ? 'comment' : 'Remark'] ?? '';
        $line = $cf ? 'default' : ($row['Line'] ?? null); $locked = $row[$cf ? 'locked' : 'Locked'] ?? false;
        if (!is_string($id) || !preg_match($cf ? '/^[a-f0-9]{32}$/D' : '/^[0-9]{1,40}$/D', $id)
            || !is_string($type) || !preg_match('/^[A-Z0-9]{1,16}$/D', $type) || !is_string($content) || strlen($content) > 1024
            || !is_string($name) || strtolower(rtrim($name, '.')) !== $set['hostname']
            || !$cf && ($row['DomainName'] ?? null) !== $set['zone']
            || !is_int($ttl) || $ttl < 1 || $ttl > 86400 || !is_string($marker) || strlen($marker) > 1000
            || !is_string($line) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $line) || !is_bool($locked)
            || $cf && (!is_bool($row['proxied'] ?? null) || !is_bool($row['private_routing'] ?? false))
            || !$cf && (!is_string($row['Status'] ?? null) || !in_array(strtoupper($row['Status']), ['ENABLE', 'DISABLE'], true))) throw new CloudException('dns_inventory_invalid');
        if (in_array($type, ['A', 'AAAA'], true)) {
            if (!filter_var($content, FILTER_VALIDATE_IP, $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) throw new CloudException('dns_inventory_invalid');
            $content = inet_ntop(inet_pton($content));
        }
        return ['id' => $id, 'hostname' => $set['hostname'], 'type' => $type, 'line' => $line, 'content' => $content,
            'ttl' => $ttl, 'marker' => $marker, 'proxied' => $cf ? $row['proxied'] : false,
            'locked' => $locked || ($cf ? ($row['private_routing'] ?? false) : strtoupper($row['Status']) !== 'ENABLE')];
    }

    public function mutation(array $set, array $action, ?string $createdId = null): array
    {
        $this->scope($set); $kind = $action['kind'] ?? null; $record = $action['after'] ?? $action['before'] ?? null;
        if (!in_array($kind, ['create', 'update', 'delete', 'claim'], true) || !is_array($record)
            || !in_array($record['type'] ?? null, ['A', 'AAAA'], true) || !in_array($record['line'] ?? null, self::LINES, true)
            || !is_string($record['marker'] ?? null) || !preg_match('/^keli-set-[a-f0-9]{48}$/D', $record['marker'])
            || !filter_var($record['content'] ?? null, FILTER_VALIDATE_IP, $record['type'] === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6))
            throw new CloudException('invalid_dns_set');
        $id = $createdId ?? ($action['before']['id'] ?? null);
        if ($kind !== 'create' && (!is_string($id) || !preg_match($set['provider'] === 'cloudflare' ? '/^[a-f0-9]{32}$/D' : '/^[0-9]{1,40}$/D', $id)))
            throw new CloudException('dns_ownership_changed');
        if ($set['provider'] === 'cloudflare') {
            if ($kind === 'claim' || $record['line'] !== 'default') throw new CloudException('invalid_dns_set');
            return ['method' => $kind === 'delete' ? 'DELETE' : ($kind === 'create' ? 'POST' : 'PATCH'),
                'path' => '/zones/' . $set['zone'] . '/dns_records' . ($kind === 'create' ? '' : '/' . $id),
                'parameters' => $kind === 'delete' ? [] : ['type' => $record['type'], 'name' => $set['hostname'], 'content' => $record['content'],
                    'ttl' => $set['ttl'], 'proxied' => false, 'comment' => $record['marker']]];
        }
        $rr = $set['hostname'] === $set['zone'] ? '@' : substr($set['hostname'], 0, -strlen('.' . $set['zone']));
        return ['method' => 'POST', 'action' => match ($kind) { 'create' => 'AddDomainRecord', 'update' => 'UpdateDomainRecord',
                'delete' => 'DeleteDomainRecord', 'claim' => 'UpdateDomainRecordRemark' },
            'parameters' => match ($kind) {
                'delete' => ['RecordId' => $id], 'claim' => ['RecordId' => $id, 'Remark' => $record['marker']],
                default => ($kind === 'create' ? ['DomainName' => $set['zone']] : ['RecordId' => $id])
                    + ['RR' => $rr, 'Type' => $record['type'], 'Value' => $record['content'], 'TTL' => $set['ttl'], 'Line' => $record['line']],
            }];
    }

    private function scope(array $set): void
    {
        if (!in_array($set['provider'] ?? null, ['cloudflare', 'aliyun'], true)
            || !is_string($set['hostname'] ?? null) || !filter_var($set['hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !is_string($set['zone'] ?? null) || !is_int($set['ttl'] ?? null) || $set['ttl'] < ($set['provider'] === 'cloudflare' ? 60 : 600) || $set['ttl'] > 86400
            || ($set['provider'] === 'cloudflare' ? !preg_match('/^[a-f0-9]{32}$/D', $set['zone'])
                : !filter_var($set['zone'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || $set['hostname'] !== $set['zone'] && !str_ends_with($set['hostname'], '.' . $set['zone'])))
            throw new CloudException('invalid_dns_set');
    }
}

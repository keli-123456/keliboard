<?php

namespace App\Services\ServerMachine\Dns;

use App\Models\MachineDnsBinding;
use App\Models\MachineDnsCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DnsProviderClient
{
    public function ownedRecords(MachineDnsCredential $credential, string $zone, string $hostname): array
    {
        $this->ownedProvider($credential, $zone); $hostname = $this->domain($hostname); $records = []; $expected = null; $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $json = $this->cloudflareRequest($credential, 'GET', '/zones/' . $zone . '/dns_records', [],
                ['name.exact' => $hostname, 'page' => $page, 'per_page' => 50]);
            $this->assertList($json['result']);
            $meta = $json['result_info'] ?? []; $pages = $meta['total_pages'] ?? null; $total = $meta['total_count'] ?? null;
            if (!is_int($pages) || $pages < 0 || $pages > 3 || !is_int($total) || $total < 0 || $total > 150
                || ($meta['page'] ?? null) !== $page || ($meta['per_page'] ?? null) !== 50
                || ($total === 0 ? !in_array($pages, [0, 1], true) : $pages !== (int) ceil($total / 50))
                || $expected !== null && $expected !== [$pages, $total]) throw new DnsException('provider_rejected');
            $expected = [$pages, $total];
            foreach ($json['result'] as $row) {
                $record = $this->ownedRecordData($credential, $row, $hostname);
                if (isset($seen[$record['id']])) throw new DnsException('provider_rejected');
                $seen[$record['id']] = true; $records[] = $record;
            }
            if (count($records) > $total || $page < $pages && count($json['result']) !== 50) throw new DnsException('provider_rejected');
            if ($page >= $pages) {
                if (count($records) !== $total) throw new DnsException('provider_rejected');
                return $records;
            }
        }
        throw new DnsException('provider_rejected');
    }

    public function createOwnedA(MachineDnsCredential $credential, string $zone, string $hostname, string $ip, string $marker): array
    {
        $this->ownedProvider($credential, $zone);
        if (\App\Services\ServerMachine\MachineEntryProbe::publicIp($ip) !== $ip || str_contains($ip, ':')
            || !filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !preg_match('/^keli-owned-[a-f0-9]{48}$/D', $marker)) throw new DnsException('invalid_record');
        $json = $this->cloudflareRequest($credential, 'POST', '/zones/' . $zone . '/dns_records',
            ['type' => 'A', 'name' => $hostname, 'content' => $ip, 'ttl' => 60, 'proxied' => false, 'comment' => $marker]);
        return $this->ownedRecordData($credential, $json['result'], $hostname);
    }

    private function ownedProvider(MachineDnsCredential $credential, string $zone): void
    {
        if ($credential->provider !== 'cloudflare' || !preg_match('/^[a-f0-9]{32}$/D', $zone)) throw new DnsException('owned_dns_unsupported');
    }

    private function ownedRecordData(MachineDnsCredential $credential, mixed $row, string $hostname): array
    {
        if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $row['id'])
            || ($row['name'] ?? null) !== $hostname || !is_string($row['comment'] ?? '') || strlen($row['comment'] ?? '') > 1000
            || !is_string($row['content'] ?? null) || strlen($row['content']) > 1024 || !is_int($row['ttl'] ?? null) || !is_bool($row['proxied'] ?? null)
            || isset($row['locked']) && !is_bool($row['locked']) || isset($row['private_routing']) && !is_bool($row['private_routing'])) throw new DnsException('provider_rejected');
        return $this->normalize($credential, $row) + ['id' => $row['id'], 'comment' => $row['comment'] ?? '',
            'private_routing' => $row['private_routing'] ?? false];
    }

    public function zones(MachineDnsCredential $credential, int $page, string $search): array
    {
        if ($credential->provider === 'cloudflare') {
            $json = $this->cloudflareRequest($credential, 'GET', '/zones', [],
                ['page' => $page, 'per_page' => 50, 'order' => 'name'] + ($search === '' ? [] : ['name' => $search]));
            $rows = $json['result'];
            $more = $this->hasMore($json['result_info']['total_pages'] ?? null, $page);
        } else {
            $json = $this->aliyun($credential, 'DescribeDomains', ['PageNumber' => $page, 'PageSize' => 50]
                + ($search === '' ? [] : ['KeyWord' => $search]));
            $rows = $json['Domains']['Domain'] ?? null;
            $more = $this->hasMore($json['TotalCount'] ?? null, $page, 50);
        }
        $this->assertList($rows);
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new DnsException('provider_rejected');
            $name = $this->domain($row[$credential->provider === 'cloudflare' ? 'name' : 'DomainName'] ?? null);
            $id = $credential->provider === 'cloudflare' ? ($row['id'] ?? '') : $name;
            if (!is_string($id) || ($credential->provider === 'cloudflare' && !preg_match('/^[a-f0-9]{32}$/D', $id))) throw new DnsException('provider_rejected');
            $items[] = ['id' => $id, 'name' => $name];
        }
        return ['items' => $items, 'page' => $page, 'has_more' => $more];
    }

    public function records(MachineDnsCredential $credential, string $zone, string $type, int $page, string $search): array
    {
        if ($credential->provider === 'cloudflare') {
            $json = $this->cloudflareRequest($credential, 'GET', '/zones/' . $zone . '/dns_records', [],
                ['page' => $page, 'per_page' => 50, 'type' => $type, 'order' => 'name'] + ($search === '' ? [] : ['name.contains' => $search]));
            $rows = $json['result'];
            $more = $this->hasMore($json['result_info']['total_pages'] ?? null, $page);
        } else {
            $json = $this->aliyun($credential, 'DescribeDomainRecords', ['DomainName' => $zone, 'TypeKeyWord' => $type,
                'PageNumber' => $page, 'PageSize' => 50] + ($search === '' ? [] : ['RRKeyWord' => $search]));
            $rows = $json['DomainRecords']['Record'] ?? null;
            $more = $this->hasMore($json['TotalCount'] ?? null, $page, 50);
        }
        $this->assertList($rows);
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new DnsException('provider_rejected');
            $record = $this->normalize($credential, $row);
            $id = $row[$credential->provider === 'cloudflare' ? 'id' : 'RecordId'] ?? '';
            if (!is_string($id) || !preg_match($credential->provider === 'cloudflare' ? '/^[a-f0-9]{32}$/D' : '/^[0-9]{1,40}$/D', $id)
                || $record['type'] !== $type) throw new DnsException('provider_rejected');
            $wildcard = str_starts_with($record['hostname'], '*.');
            $this->domain($wildcard ? substr($record['hostname'], 2) : $record['hostname']);
            if (!is_string($record['content']) || strlen($record['content']) > 64 || !is_int($record['ttl'])
                || (isset($record['line']) && (!is_string($record['line']) || strlen($record['line']) > 100))) throw new DnsException('provider_rejected');
            $ip = \App\Services\ServerMachine\MachineEntryProbe::publicIp($record['content']);
            $record['locked'] = $record['locked'] || $wildcard || !$ip || str_contains($ip, ':') !== ($type === 'AAAA');
            $items[] = array_intersect_key($record, array_flip(['hostname', 'type', 'content', 'ttl', 'proxied', 'line', 'locked'])) + ['id' => $id];
        }
        return ['items' => $items, 'page' => $page, 'has_more' => $more];
    }

    public function inspect(MachineDnsCredential $credential, MachineDnsBinding $binding): array
    {
        return $this->normalize($credential, $credential->provider === 'cloudflare'
            ? $this->cloudflare($credential, $binding, 'GET')
            : $this->aliyun($credential, 'DescribeDomainRecordInfo', ['RecordId' => $binding->record_id]));
    }

    private function normalize(MachineDnsCredential $credential, array $record): array
    {
        if ($credential->provider === 'cloudflare') {
            return ['hostname' => strtolower(rtrim((string) ($record['name'] ?? ''), '.')),
                'type' => $record['type'] ?? '', 'content' => $record['content'] ?? '',
                'ttl' => $record['ttl'] ?? 1, 'proxied' => $record['proxied'] ?? false,
                'locked' => ($record['locked'] ?? false) === true];
        }
        $domain = strtolower((string) ($record['DomainName'] ?? ''));
        $rr = (string) ($record['RR'] ?? '');
        return ['hostname' => strtolower($rr === '@' ? $domain : $rr . '.' . $domain),
            'zone' => $domain, 'type' => $record['Type'] ?? '', 'content' => $record['Value'] ?? '',
            'ttl' => $record['TTL'] ?? 600, 'line' => $record['Line'] ?? 'default',
            'locked' => ($record['Locked'] ?? false) === true || strtoupper((string) ($record['Status'] ?? '')) !== 'ENABLE'];
    }

    public function update(MachineDnsCredential $credential, MachineDnsBinding $binding, array $record, string $ip): void
    {
        if ($credential->provider === 'cloudflare') {
            // Patch only the address; retain the existing TTL, proxy, name and metadata.
            $this->cloudflare($credential, $binding, 'PATCH', ['content' => $ip]);
            return;
        }
        $rr = $binding->hostname === $binding->zone ? '@' : substr($binding->hostname, 0, -strlen('.' . $binding->zone));
        $this->aliyun($credential, 'UpdateDomainRecord', ['RecordId' => $binding->record_id,
            'RR' => $rr, 'Type' => $binding->type, 'Value' => $ip,
            'TTL' => $record['ttl'], 'Line' => $record['line']]);
    }

    private function cloudflare(MachineDnsCredential $credential, MachineDnsBinding $binding, string $method, array $data = []): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $binding->zone) || !preg_match('/^[a-f0-9]{32}$/D', $binding->record_id)) throw new DnsException('invalid_record');
        return $this->cloudflareRequest($credential, $method, '/zones/' . $binding->zone . '/dns_records/' . $binding->record_id, $data)['result'];
    }

    private function cloudflareRequest(MachineDnsCredential $credential, string $method, string $path, array $data = [], array $query = []): array
    {
        try {
            $response = Http::withToken($credential->credentials['api_token'])->acceptJson()->connectTimeout(5)->timeout(10)
                ->withoutRedirecting()->send($method, 'https://api.cloudflare.com/client/v4' . $path,
                    ($data ? ['json' => $data] : []) + ($query ? ['query' => $query] : []));
        } catch (\Throwable) { throw new DnsRequestFailure('provider_unavailable'); }
        $json = $this->decode($response);
        if (($json['success'] ?? false) !== true || !is_array($json['result'] ?? null)) throw new DnsException('provider_rejected');
        return $json;
    }

    public static function signAliyun(array $parameters, string $secret): array
    {
        ksort($parameters, SORT_STRING);
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $parameters['Signature'] = base64_encode(hash_hmac('sha1', 'POST&%2F&' . rawurlencode($query), $secret . '&', true));
        return $parameters;
    }

    private function aliyun(MachineDnsCredential $credential, string $action, array $parameters): array
    {
        $keys = $credential->credentials;
        $parameters = self::signAliyun($parameters + ['Action' => $action, 'Format' => 'JSON', 'Version' => '2015-01-09',
            'AccessKeyId' => $keys['access_key_id'], 'SignatureMethod' => 'HMAC-SHA1', 'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(16)), 'Timestamp' => gmdate('Y-m-d\TH:i:s\Z')], $keys['access_key_secret']);
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(10)->withoutRedirecting()
                ->post('https://alidns.aliyuncs.com/', $parameters);
        } catch (\Throwable) { throw new DnsRequestFailure('provider_unavailable'); }
        $json = $this->decode($response);
        if (str_starts_with((string) ($json['Code'] ?? ''), 'Forbidden') || ($json['Code'] ?? '') === 'InvalidAccessKeyId.NotFound') throw new DnsException('provider_forbidden');
        if (isset($json['Code'])) throw new DnsException('provider_rejected');
        return $json;
    }

    private function assertList(mixed $rows): void
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 50) throw new DnsException('provider_rejected');
    }

    private function hasMore(mixed $total, int $page, int $size = 1): bool
    {
        if ((!is_int($total) && !(is_string($total) && ctype_digit($total))) || $total < 0 || $total > 10000000) throw new DnsException('provider_rejected');
        return $page < 10000 && $page * $size < (int) $total;
    }

    private function domain(mixed $name): string
    {
        if (!is_string($name) || strlen($name) > 253) throw new DnsException('provider_rejected');
        $name = strtolower(rtrim($name, '.'));
        if (!filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) throw new DnsException('provider_rejected');
        return $name;
    }

    private function decode(Response $response): array
    {
        // Never surface provider bodies or exception URLs: they may contain credentials.
        if (in_array($response->status(), [401, 403], true)) throw new DnsException('provider_forbidden');
        if ($response->status() === 429) {
            $after = $response->header('Retry-After'); $seconds = null;
            if (is_string($after) && preg_match('/^\d{1,8}$/D', $after)) $seconds = (int) $after;
            elseif (is_string($after) && strlen($after) < 80 && preg_match('/GMT$/D', $after)) {
                $at = strtotime($after); if ($at !== false) $seconds = $at - time();
            }
            throw new DnsRequestFailure('provider_rate_limited', $seconds !== null && $seconds >= 0 ? min(86400, max(120, $seconds)) : 120,
                $seconds === null || $seconds <= 86400);
        }
        if ($response->status() >= 500) throw new DnsRequestFailure('provider_unavailable');
        if (!$response->successful() || strlen($response->body()) > 262144) throw new DnsException('provider_rejected');
        $json = $response->json();
        if (!is_array($json)) throw new DnsException('provider_rejected');
        return $json;
    }
}

<?php

namespace App\Services\ServerMachine\Dns;

use App\Models\MachineDnsBinding;
use App\Models\MachineDnsCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DnsProviderClient
{
    public function inspect(MachineDnsCredential $credential, MachineDnsBinding $binding): array
    {
        if ($credential->provider === 'cloudflare') {
            $record = $this->cloudflare($credential, $binding, 'GET');
            return ['hostname' => strtolower(rtrim((string) ($record['name'] ?? ''), '.')),
                'type' => $record['type'] ?? '', 'content' => $record['content'] ?? '',
                'ttl' => $record['ttl'] ?? 1, 'proxied' => $record['proxied'] ?? false,
                'locked' => ($record['locked'] ?? false) === true];
        }
        $record = $this->aliyun($credential, 'DescribeDomainRecordInfo', ['RecordId' => $binding->record_id]);
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
        try {
            $response = Http::withToken($credential->credentials['api_token'])->acceptJson()->connectTimeout(5)->timeout(10)
                ->withoutRedirecting()->send($method, 'https://api.cloudflare.com/client/v4/zones/' . $binding->zone . '/dns_records/' . $binding->record_id,
                    $data ? ['json' => $data] : []);
        } catch (\Throwable) { throw new DnsException('provider_unavailable'); }
        $json = $this->decode($response);
        if (($json['success'] ?? false) !== true || !is_array($json['result'] ?? null)) throw new DnsException('provider_rejected');
        return $json['result'];
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
        } catch (\Throwable) { throw new DnsException('provider_unavailable'); }
        $json = $this->decode($response);
        if (isset($json['Code'])) throw new DnsException('provider_rejected');
        return $json;
    }

    private function decode(Response $response): array
    {
        // Never surface provider bodies or exception URLs: they may contain credentials.
        if (in_array($response->status(), [401, 403], true)) throw new DnsException('provider_forbidden');
        if ($response->status() === 429) throw new DnsException('provider_rate_limited');
        if (!$response->successful() || strlen($response->body()) > 262144) throw new DnsException('provider_rejected');
        $json = $response->json();
        if (!is_array($json)) throw new DnsException('provider_rejected');
        return $json;
    }
}

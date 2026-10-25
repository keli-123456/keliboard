<?php

namespace App\Services\SubscriptionProxy;

use App\Models\WebsiteProxyHealth;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class WebsiteProxyProbeService
{
    private const MAX_RESPONSE_BYTES = 524288;

    public function __construct(
        private WebsiteProxyEndpointService $endpoints,
        private ?Closure $dnsResolver = null,
    ) {}

    public function probeAll(?int $machineId = null): array
    {
        if (!$this->endpoints->healthTableExists()) {
            return [];
        }
        $targets = $this->endpoints->configuredTargets($machineId);
        $previous = WebsiteProxyHealth::query()->get(['machine_id', 'site_id', 'last_checked_at'])
            ->keyBy(fn ($row): string => $row->machine_id . ':' . $row->site_id);
        usort($targets, fn (array $a, array $b): int => [
            $previous->get($a['machine_id'] . ':' . $a['site_id'])?->last_checked_at ?? 0,
            $a['machine_id'], $a['site_id'],
        ] <=> [
            $previous->get($b['machine_id'] . ':' . $b['site_id'])?->last_checked_at ?? 0,
            $b['machine_id'], $b['site_id'],
        ]);

        $results = [];
        $deadline = microtime(true) + 45;
        foreach (array_slice($targets, 0, 64) as $target) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $results[] = $this->probeTarget($target);
        }
        WebsiteProxyHealth::query()->where('last_checked_at', '<', time() - 86400)->delete();

        return $results;
    }

    public function probeTarget(array $target): array
    {
        $lock = Cache::lock('website-proxy:probe:' . $target['machine_id'] . ':' . $target['site_id'], 60);
        if (!$lock->get()) {
            return ['status' => 'skipped', 'reason' => 'probe_busy'];
        }
        try {
            $started = microtime(true);
            $result = $this->check($target);
            $result['latency_ms'] = max(1, (int) round((microtime(true) - $started) * 1000));
            return $this->store($target, $result);
        } finally {
            $lock->release();
        }
    }

    private function check(array $target): array
    {
        $result = [
            'status' => 'error', 'reason' => $target['runtime_error'] ?? 'request_failed',
            'tls_verified' => false, 'http_status' => null, 'context_http_status' => null,
            'latency_ms' => null,
        ];
        if ($target['runtime_error'] !== null) {
            return $result;
        }
        $parts = parse_url($target['url']);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 443);
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || $port < 1 || $port > 65535
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            return array_merge($result, ['reason' => 'invalid_endpoint']);
        }
        try {
            $addresses = $this->addresses($host);
        } catch (Throwable) {
            return array_merge($result, ['reason' => 'unsafe_address']);
        }
        if ($addresses === [] || array_filter($addresses, fn ($ip): bool => !$this->isPublicAddress($ip))) {
            return array_merge($result, ['reason' => 'unsafe_address']);
        }

        try {
            $nonce = bin2hex(random_bytes(8));
            [$status, $body, $contentType] = $this->request($target['url'] . '/?__website_probe=' . $nonce, $host, $port, $addresses[0]);
            $result['http_status'] = $status;
            if ($status !== 200) {
                return array_merge($result, ['reason' => 'page_http_error']);
            }
            if (!str_contains(strtolower($contentType), 'text/html')
                || !str_contains(strtolower(substr($body, 0, 4096)), '<html')) {
                return array_merge($result, ['reason' => 'page_not_html']);
            }
            [$contextStatus, $body] = $this->request(
                $target['url'] . '/api/v1/guest/comm/config?__website_probe=' . $nonce,
                $host, $port, $addresses[0]
            );
            $result['context_http_status'] = $contextStatus;
            if ($contextStatus !== 200) {
                return array_merge($result, ['reason' => 'context_http_error']);
            }
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            $context = data_get($payload, 'data.site_context', data_get($payload, 'site_context'));
            if (!is_array($context) || !array_key_exists('site_id', $context) || !array_key_exists('site_code', $context)) {
                return array_merge($result, ['reason' => 'site_context_missing']);
            }
            $observedId = $context['site_id'] === null ? 0 : $context['site_id'];
            if (!is_int($observedId) || $observedId !== $target['site_id']
                || ($context['site_code'] ?? '') !== $target['site_code']) {
                return array_merge($result, ['reason' => 'site_context_mismatch']);
            }

            return array_merge($result, ['status' => 'ok', 'reason' => 'ok', 'tls_verified' => true]);
        } catch (\JsonException) {
            return array_merge($result, ['reason' => 'site_context_missing']);
        } catch (\LengthException) {
            return array_merge($result, ['reason' => 'response_too_large']);
        } catch (Throwable) {
            // Transport errors can contain URLs, headers or response fragments. Store only a fixed reason.
            return array_merge($result, ['reason' => 'request_failed']);
        }
    }

    private function request(string $url, string $host, int $port, string $address): array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Keliboard-Website-Proxy-Probe/1.0',
            'Cache-Control' => 'no-cache, no-store',
        ])->withoutRedirecting()->connectTimeout(2)->timeout(4)->withOptions([
            'verify' => true,
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $address], CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            'progress' => static function ($total, $downloaded): void {
                if ($downloaded > self::MAX_RESPONSE_BYTES) {
                    throw new \LengthException('Website probe response exceeds its limit');
                }
            },
        ])->get($url);
        $body = $response->body();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new \LengthException('Website probe response exceeds its limit');
        }

        return [$response->status(), $body, (string) $response->header('Content-Type')];
    }

    private function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [$host];
        }
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return [];
        }
        $addresses = $this->dnsResolver ? ($this->dnsResolver)($host) : (@gethostbynamel($host) ?: []);

        if (!is_array($addresses) || array_filter($addresses, fn ($ip): bool => !is_string($ip))) {
            return [];
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(mixed $ip): bool
    {
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return !IpUtils::checkIp($ip, [
            '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        ]);
    }

    private function store(array $target, array $result): array
    {
        $previous = WebsiteProxyHealth::query()->where('machine_id', $target['machine_id'])->where('site_id', $target['site_id'])->first();
        if ($previous && !hash_equals((string) $previous->route_fingerprint, $target['route_fingerprint'])) {
            $previous = null;
        }
        $successful = $result['status'] === 'ok';
        $successes = $successful ? min(2, (int) ($previous?->consecutive_successes ?? 0) + 1) : 0;
        if ($successful && in_array($previous?->status, ['error', 'recovering'], true) && $successes < 2) {
            $result['status'] = 'recovering';
            $result['reason'] = 'recovering';
        }
        $record = WebsiteProxyHealth::query()->updateOrCreate([
            'machine_id' => $target['machine_id'], 'site_id' => $target['site_id'],
        ], array_merge($result, [
            'route_fingerprint' => $target['route_fingerprint'],
            'endpoint_url' => $target['url'], 'upstream_url' => $target['upstream_url'], 'site_code' => $target['site_code'],
            'consecutive_successes' => $successes,
            'consecutive_failures' => $successful ? 0 : min(10000, (int) ($previous?->consecutive_failures ?? 0) + 1),
            'last_checked_at' => time(),
            'last_success_at' => $successful ? time() : $previous?->last_success_at,
        ]));

        return $record->toArray();
    }
}

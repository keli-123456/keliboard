<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use Illuminate\Http\Request;

final class SubscriptionClientIpResolver
{
    private const DEFAULT_TRUSTED_PROXY_CIDRS = <<<'TEXT'
127.0.0.0/8
::1/128
10.0.0.0/8
172.16.0.0/12
192.168.0.0/16
fc00::/7
fe80::/10
103.21.244.0/22
103.22.200.0/22
103.31.4.0/22
104.16.0.0/13
104.24.0.0/14
108.162.192.0/18
131.0.72.0/22
141.101.64.0/18
162.158.0.0/15
172.64.0.0/13
173.245.48.0/20
188.114.96.0/20
190.93.240.0/20
197.234.240.0/22
198.41.128.0/17
2400:cb00::/32
2606:4700::/32
2803:f800::/32
2405:b500::/32
2405:8100::/32
2a06:98c0::/29
2c0f:f248::/32
TEXT;

    public function __construct(private readonly array $config = [])
    {
    }

    public function resolve(Request $request): array
    {
        $proxyIp = $this->normalizeIp($request->server('REMOTE_ADDR')) ?? $this->normalizeIp($request->ip()) ?? '0.0.0.0';
        $trustedProxy = $this->isTrustedProxy($proxyIp);
        $clientIp = $proxyIp;
        $source = 'remote_addr';

        if ($trustedProxy) {
            foreach ($this->candidateHeaders($request) as $candidate) {
                if ($candidate['ip'] === null) {
                    continue;
                }
                $clientIp = $candidate['ip'];
                $source = $candidate['source'];
                break;
            }
        }

        return [
            'client_ip' => $clientIp,
            'proxy_ip' => $proxyIp,
            'client_ip_source' => $source,
            'trusted_proxy' => $trustedProxy,
            'cf_ray' => $this->normalizeText($request->header('CF-Ray')),
        ];
    }

    private function candidateHeaders(Request $request): array
    {
        return [
            [
                'source' => 'cf_connecting_ip',
                'ip' => $this->normalizeIp($request->header('CF-Connecting-IP')),
            ],
            [
                'source' => 'true_client_ip',
                'ip' => $this->normalizeIp($request->header('True-Client-IP')),
            ],
            [
                'source' => 'cf_connecting_ipv6',
                'ip' => $this->normalizeIp($request->header('CF-Connecting-IPv6')),
            ],
            [
                'source' => 'x_forwarded_for',
                'ip' => $this->firstForwardedIp($request->header('X-Forwarded-For')),
            ],
            [
                'source' => 'x_real_ip',
                'ip' => $this->normalizeIp($request->header('X-Real-IP')),
            ],
        ];
    }

    private function firstForwardedIp(?string $value): ?string
    {
        foreach (explode(',', (string) $value) as $part) {
            $ip = $this->normalizeIp($part);
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    private function isTrustedProxy(string $ip): bool
    {
        foreach ($this->trustedProxyCidrs() as $cidr) {
            if ($this->ipMatchesCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function trustedProxyCidrs(): array
    {
        $value = (string) ($this->config['trusted_proxy_cidrs'] ?? self::DEFAULT_TRUSTED_PROXY_CIDRS);
        $egressValue = $this->config['trusted_egress_ips'] ?? '';
        if (is_array($egressValue)) {
            $egressValue = implode("\n", array_map('strval', $egressValue));
        }
        $parts = preg_split('/[\r\n，,]+/', $value . "\n" . (string) $egressValue) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), static fn($item): bool => $item !== '')));
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }

        if (!str_contains($cidr, '/')) {
            return $this->normalizeIp($cidr) === $ip;
        }

        [$network, $prefixText] = explode('/', $cidr, 2);
        $network = $this->normalizeIp($network);
        if ($network === null || !ctype_digit($prefixText)) {
            return false;
        }

        $ipBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $maxPrefix = strlen($ipBytes) * 8;
        $prefix = min($maxPrefix, max(0, (int) $prefixText));
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        for ($i = 0; $i < $fullBytes; $i++) {
            if ($ipBytes[$i] !== $networkBytes[$i]) {
                return false;
            }
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }

    private function normalizeIp(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }

    private function normalizeText(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

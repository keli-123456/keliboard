<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use Closure;
use Illuminate\Support\Facades\Http;

class SiteStorefrontHealthService
{
    public function __construct(
        private ?Closure $dnsResolver = null,
        private ?Closure $httpProbe = null,
    ) {}

    /**
     * @return array{status: string, checked_at: int, domains: array<int, array<string, mixed>>}
     */
    public function check(Site $site): array
    {
        $site->loadMissing('domains');
        $domains = $site->domains
            ->sortByDesc('is_primary')
            ->values();
        $results = [];
        $ready = 0;
        $warning = 0;

        foreach ($domains as $domain) {
            $result = $this->checkDomain($site, $domain);
            if ($result['status'] === 'ready') {
                $ready++;
            }
            if ($result['status'] === 'warning') {
                $warning++;
            }
            $results[] = $result;
        }

        $activeCount = $domains->where('status', SiteDomain::STATUS_ACTIVE)->count();
        $status = $ready === $activeCount && $activeCount > 0
            ? 'ready'
            : (($ready > 0 || $warning > 0) ? 'warning' : 'blocked');
        if ($site->status !== Site::STATUS_ACTIVE) {
            $status = 'blocked';
        }

        return [
            'status' => $status,
            'checked_at' => time(),
            'domains' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDomain(Site $site, SiteDomain $domain): array
    {
        $base = [
            'id' => (int) $domain->id,
            'domain' => (string) $domain->domain,
            'configured_status' => (string) $domain->status,
            'status' => 'blocked',
            'reason' => '',
            'addresses' => [],
            'http_status' => null,
            'resolved_site_code' => '',
        ];

        if ($site->status !== Site::STATUS_ACTIVE) {
            return array_merge($base, ['reason' => 'site_disabled']);
        }
        if ($domain->status !== SiteDomain::STATUS_ACTIVE) {
            return array_merge($base, ['status' => 'warning', 'reason' => 'domain_inactive']);
        }

        $addresses = $this->resolveAddresses((string) $domain->domain);
        if ($addresses === []) {
            return array_merge($base, ['reason' => 'dns_unresolved']);
        }

        $probe = $this->probe((string) $domain->domain);
        $httpStatus = (int) ($probe['status'] ?? 0);
        $payload = is_array($probe['payload'] ?? null) ? $probe['payload'] : [];
        $resolvedCode = trim((string) (data_get($payload, 'site_context.site_code')
            ?? data_get($payload, 'data.site_context.site_code')
            ?? ''));
        $base = array_merge($base, [
            'addresses' => $addresses,
            'http_status' => $httpStatus > 0 ? $httpStatus : null,
            'resolved_site_code' => $resolvedCode,
        ]);

        if ($httpStatus < 200 || $httpStatus >= 400) {
            return array_merge($base, ['reason' => trim((string) ($probe['error'] ?? 'http_unreachable')) ?: 'http_unreachable']);
        }
        if ($resolvedCode === '') {
            return array_merge($base, ['status' => 'warning', 'reason' => 'site_context_missing']);
        }
        if ($resolvedCode !== (string) $site->code) {
            return array_merge($base, ['status' => 'warning', 'reason' => 'site_context_mismatch']);
        }

        return array_merge($base, ['status' => 'ready', 'reason' => 'ok']);
    }

    /**
     * @return array<int, string>
     */
    private function resolveAddresses(string $domain): array
    {
        if ($this->dnsResolver) {
            return array_values(array_unique(array_filter(
                ($this->dnsResolver)($domain),
                static fn (mixed $address): bool => is_string($address) && trim($address) !== '',
            )));
        }
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return [$domain];
        }

        $ipv4 = @gethostbynamel($domain) ?: [];
        $records = @dns_get_record($domain, DNS_AAAA) ?: [];
        $ipv6 = array_values(array_filter(array_map(
            static fn (array $record): string => (string) ($record['ipv6'] ?? ''),
            $records,
        )));

        return array_values(array_unique(array_merge($ipv4, $ipv6)));
    }

    /**
     * @return array{status: int, payload: array<string, mixed>, error?: string}
     */
    private function probe(string $domain): array
    {
        $url = 'https://' . $domain . '/api/v1/guest/comm/config';
        try {
            if ($this->httpProbe) {
                $result = ($this->httpProbe)($url);
                return [
                    'status' => (int) ($result['status'] ?? 0),
                    'payload' => is_array($result['payload'] ?? null) ? $result['payload'] : [],
                    'error' => trim((string) ($result['error'] ?? '')),
                ];
            }

            $response = Http::acceptJson()->connectTimeout(4)->timeout(8)->get($url);

            return [
                'status' => $response->status(),
                'payload' => is_array($response->json()) ? $response->json() : [],
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 0,
                'payload' => [],
                'error' => 'request_failed',
            ];
        }
    }
}

<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Http\Request;

class SiteResolver
{
    public function resolveRequest(Request $request): array
    {
        $host = (string) ($request->headers->get('x-forwarded-host') ?: $request->headers->get('host', ''));

        return $this->resolveHost($host);
    }

    public function resolveHost(string $host): array
    {
        $domain = $this->normalizeHost($host);
        if ($domain !== '') {
            $row = SiteDomain::query()
                ->with('site')
                ->where('domain', $domain)
                ->where('status', SiteDomain::STATUS_ACTIVE)
                ->whereHas('site', function ($query): void {
                    $query->where('status', Site::STATUS_ACTIVE)
                        ->where('is_default', false);
                })
                ->first();

            if ($row && $row->site) {
                return $this->context($row->site, $row, 'domain');
            }
        }

        return $this->platformContext();
    }

    public function normalizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        $host = trim(explode(',', $host, 2)[0]);

        if (str_contains($host, '://')) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : $host;
        }

        $host = preg_split('/[\/?#]/', $host, 2)[0] ?? '';
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 1, $end - 1);
        } else {
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }

        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '') {
            return '';
        }

        if (function_exists('idn_to_ascii')) {
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = idn_to_ascii($host, 0, $variant);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }

    private function context(Site $site, ?SiteDomain $domain, string $source): array
    {
        return [
            'site_id' => (int) $site->id,
            'site_code' => (string) $site->code,
            'site_name' => (string) $site->name,
            'site_domain_id' => $domain ? (int) $domain->id : null,
            'domain' => $domain ? (string) $domain->domain : null,
            'is_default' => false,
            'source' => $source,
        ];
    }

    public function platformContext(): array
    {
        return [
            'site_id' => null,
            'site_code' => 'platform',
            'site_name' => '',
            'site_domain_id' => null,
            'domain' => null,
            'is_default' => false,
            'source' => 'platform',
        ];
    }
}

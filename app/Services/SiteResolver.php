<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                    $query->where('status', Site::STATUS_ACTIVE);
                })
                ->first();

            if ($row && $row->site) {
                return $this->context($row->site, $row, 'domain');
            }
        }

        return $this->context($this->defaultSite(), null, 'default');
    }

    public function defaultSite(): Site
    {
        $site = Site::query()
            ->where('is_default', true)
            ->where('status', Site::STATUS_ACTIVE)
            ->first();

        if ($site) {
            return $site;
        }

        return DB::transaction(function (): Site {
            $site = Site::query()
                ->where('is_default', true)
                ->where('status', Site::STATUS_ACTIVE)
                ->first();

            if ($site) {
                return $site;
            }

            $now = time();
            $site = Site::query()->where('code', 'default')->first();
            if ($site) {
                $site->fill([
                    'name' => $site->name ?: 'Default Site',
                    'status' => Site::STATUS_ACTIVE,
                    'is_default' => true,
                    'updated_at' => $now,
                ])->save();
            } else {
                $site = Site::query()->create([
                    'code' => 'default',
                    'name' => 'Default Site',
                    'status' => Site::STATUS_ACTIVE,
                    'is_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Site::query()
                ->where('id', '!=', $site->id)
                ->where('is_default', true)
                ->update([
                    'is_default' => false,
                    'updated_at' => $now,
                ]);

            return $site->refresh();
        });
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
            'is_default' => (bool) $site->is_default,
            'source' => $source,
        ];
    }
}

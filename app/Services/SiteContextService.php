<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\Request;

class SiteContextService
{
    public function resolve(Request $request, ?User $user = null): array
    {
        if (!$this->hasSiteTenantTables()) {
            return $this->fallbackPayload();
        }

        $user = $user ?: $request->user();
        if ($user instanceof User && $user->site_id) {
            $site = $this->siteQuery()
                ->where('id', (int) $user->site_id)
                ->where('status', Site::STATUS_ACTIVE)
                ->first();

            if ($site) {
                return $this->payload($site, null, 'user');
            }
        }

        $context = app(SiteResolver::class)->resolveRequest($request);
        $site = $this->siteQuery()->find((int) $context['site_id']);

        if (!$site) {
            $site = app(SiteResolver::class)->defaultSite()->load('setting');

            return $this->payload($site, null, 'default');
        }

        $domain = !empty($context['site_domain_id'])
            ? SiteDomain::query()->find((int) $context['site_domain_id'])
            : null;

        return $this->payload($site, $domain, (string) $context['source']);
    }

    public function applyToConfig(array $config, Request $request, ?User $user = null): array
    {
        if (!$this->hasSiteTenantTables()) {
            return $config;
        }

        $site = $this->resolve($request, $user);
        $shouldOverrideBrand = !empty($site['has_setting']) || empty($site['is_default']);

        if ($shouldOverrideBrand && !empty($site['site_name'])) {
            $config['app_name'] = $site['site_name'];
            $config['website_name'] = $site['site_name'];
        }
        if ($shouldOverrideBrand && !empty($site['logo_url'])) {
            $config['logo'] = $site['logo_url'];
        }
        if ($shouldOverrideBrand && !empty($site['landing_theme'])) {
            $config['landing_theme'] = $site['landing_theme'];
        }
        if ($shouldOverrideBrand && !empty($site['support_name'])) {
            $config['customer_service_name'] = $site['support_name'];
        }
        if ($shouldOverrideBrand && !empty($site['support_url'])) {
            $config['customer_service_url'] = $site['support_url'];
        }
        $config['site_context'] = $site;

        return $config;
    }

    private function payload(Site $site, ?SiteDomain $domain, string $source): array
    {
        $setting = $this->hasSiteSettingTable()
            ? ($site->relationLoaded('setting') ? $site->setting : $site->setting()->first())
            : null;
        $setting = $setting instanceof SiteSetting && $setting->enabled ? $setting : null;

        return [
            'id' => (int) $site->id,
            'site_id' => (int) $site->id,
            'site_code' => (string) $site->code,
            'site_name' => (string) ($setting?->site_name ?: $site->name),
            'source' => $source,
            'domain' => $domain ? (string) $domain->domain : null,
            'site_domain_id' => $domain ? (int) $domain->id : null,
            'is_default' => (bool) $site->is_default,
            'logo_url' => (string) ($setting?->logo_url ?? ''),
            'landing_theme' => (string) ($setting?->landing_theme ?? ''),
            'accent_color' => (string) ($setting?->accent_color ?? ''),
            'support_name' => (string) ($setting?->support_name ?? ''),
            'support_url' => (string) ($setting?->support_url ?? ''),
            'announcement' => (string) ($setting?->announcement ?? ''),
            'seo_title' => (string) ($setting?->seo_title ?? ''),
            'seo_description' => (string) ($setting?->seo_description ?? ''),
            'enabled' => $setting ? (bool) $setting->enabled : true,
            'has_setting' => $setting !== null,
            'created_at' => $this->timestampValue($site->created_at),
            'updated_at' => $this->timestampValue($setting?->updated_at ?? $site->updated_at),
        ];
    }

    private function siteQuery()
    {
        $query = Site::query();
        if ($this->hasSiteSettingTable()) {
            $query->with('setting');
        }

        return $query;
    }

    private function hasSiteTenantTables(): bool
    {
        return $this->hasTable('v2_site') && $this->hasTable('v2_site_domain');
    }

    private function hasSiteSettingTable(): bool
    {
        return $this->hasTable('v2_site_setting');
    }

    private function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function fallbackPayload(): array
    {
        return [
            'id' => null,
            'site_id' => null,
            'site_code' => 'default',
            'site_name' => '',
            'source' => 'legacy',
            'domain' => null,
            'site_domain_id' => null,
            'is_default' => true,
            'logo_url' => '',
            'landing_theme' => '',
            'accent_color' => '',
            'support_name' => '',
            'support_url' => '',
            'announcement' => '',
            'seo_title' => '',
            'seo_description' => '',
            'enabled' => true,
            'has_setting' => false,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    private function timestampValue($value): ?int
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return (int) $value;
    }
}

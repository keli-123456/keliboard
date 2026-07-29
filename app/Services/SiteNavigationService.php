<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteNavigation;
use App\Models\SiteNavigationDomain;
use App\Models\SiteNavigationLink;
use App\Models\User;
use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SiteNavigationService
{
    public function pageForRequest(Request $request): ?array
    {
        $host = app(SiteResolver::class)->normalizeHost((string) $request->getHost());
        if ($host === '') {
            return null;
        }

        try {
            $domain = SiteNavigationDomain::query()
                ->with(['navigation.site.setting', 'navigation.domains', 'navigation.links'])
                ->where('domain', $host)
                ->where('status', SiteNavigationDomain::STATUS_ACTIVE)
                ->first();
        } catch (\Throwable) {
            return null;
        }
        $navigation = $domain?->navigation;
        if (!$navigation instanceof SiteNavigation || !$navigation->enabled) {
            return null;
        }
        if ($navigation->site_id !== null && $navigation->site?->status !== Site::STATUS_ACTIVE) {
            return null;
        }

        return $this->pagePayload($navigation);
    }

    public function urlForSubscription(mixed $user, Request $request): ?string
    {
        $resolvedUser = $user instanceof User ? $user : null;
        $context = $resolvedUser
            ? app(NotificationSiteContextService::class)->forUser($resolvedUser, $request)
            : app(NotificationSiteContextService::class)->forRequest($request);
        $siteId = (int) ($context['site_id'] ?? 0);

        return $this->urlForSiteId($siteId > 0 ? $siteId : null);
    }

    public function urlForSiteId(?int $siteId): ?string
    {
        $siteId = max(0, (int) $siteId);
        $cached = Cache::remember(
            'site_navigation_url:v1:site:' . $siteId,
            30,
            fn (): array => ['url' => $this->resolveUrlForSiteId($siteId > 0 ? $siteId : null)]
        );

        return is_array($cached) && is_string($cached['url'] ?? null)
            ? $cached['url']
            : null;
    }

    private function resolveUrlForSiteId(?int $siteId): ?string
    {
        $navigation = $this->navigationForSiteId($siteId);
        if (!$navigation?->enabled) {
            return null;
        }

        $domain = $navigation->domains
            ->where('status', SiteNavigationDomain::STATUS_ACTIVE)
            ->sortBy([
                ['is_primary', 'desc'],
                ['sort', 'asc'],
                ['id', 'asc'],
            ])
            ->first();

        return $domain instanceof SiteNavigationDomain ? 'https://' . $domain->domain : null;
    }

    public function destinationsForSiteId(?int $siteId, ?SiteNavigation $navigation = null): array
    {
        $navigation ??= $this->navigationForSiteId($siteId);
        $destinations = [];

        if ($siteId !== null && $siteId > 0) {
            $siteDomains = SiteDomain::query()
                ->where('site_id', $siteId)
                ->where('status', SiteDomain::STATUS_ACTIVE)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get();
            $backupIndex = 0;
            foreach ($siteDomains as $domain) {
                $destinations[] = [
                    'label' => $domain->is_primary ? '推荐入口' : '备用入口 ' . (++$backupIndex),
                    'url' => 'https://' . $domain->domain,
                    'kind' => 'site',
                    'recommended' => (bool) $domain->is_primary,
                ];
            }
        } else {
            $appUrl = $this->httpsUrl((string) admin_setting('app_url', ''));
            if ($appUrl !== null) {
                $destinations[] = [
                    'label' => '推荐入口',
                    'url' => $appUrl,
                    'kind' => 'site',
                    'recommended' => true,
                ];
            }
        }

        foreach (app(WebsiteProxyEndpointService::class)->urlsForSiteId($siteId) as $index => $url) {
            $destinations[] = [
                'label' => '备用访问 ' . ($index + 1),
                'url' => $url,
                'kind' => 'proxy',
                'recommended' => false,
            ];
        }

        if ($navigation) {
            foreach ($navigation->links->where('enabled', true)->sortBy([
                ['sort', 'asc'],
                ['id', 'asc'],
            ]) as $link) {
                $destinations[] = [
                    'label' => (string) $link->label,
                    'url' => (string) $link->url,
                    'kind' => 'manual',
                    'recommended' => false,
                ];
            }
        }

        $seen = [];
        return array_values(array_filter($destinations, function (array $destination) use (&$seen): bool {
            $key = strtolower(rtrim((string) $destination['url'], '/'));
            if ($key === '' || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));
    }

    public function navigationForSiteId(?int $siteId): ?SiteNavigation
    {
        try {
            return SiteNavigation::query()
                ->with(['site.setting', 'domains', 'links'])
                ->where('scope_key', $this->scopeKey($siteId))
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public function scopeKey(?int $siteId): string
    {
        $siteId = max(0, (int) $siteId);
        return $siteId > 0 ? 'site:' . $siteId : 'platform';
    }

    private function pagePayload(SiteNavigation $navigation): array
    {
        $siteId = $navigation->site_id !== null ? (int) $navigation->site_id : null;
        $siteSetting = $navigation->site?->setting;
        $title = trim((string) ($navigation->title ?? ''));
        if ($title === '') {
            $title = $siteId
                ? trim((string) ($siteSetting?->site_name ?: $navigation->site?->name))
                : trim((string) admin_setting('app_name', ''));
        }
        if ($title === '') {
            $title = '地址导航';
        }

        $description = trim((string) ($navigation->description ?? ''));
        if ($description === '') {
            $description = '请选择可用地址继续访问';
        }

        $logoUrl = $siteId
            ? trim((string) ($siteSetting?->logo_url ?? ''))
            : trim((string) admin_setting('logo', ''));

        return [
            'title' => $title,
            'description' => $description,
            'announcement' => trim((string) ($navigation->announcement ?? '')),
            'logo_url' => $this->httpsUrl($logoUrl),
            'destinations' => $this->destinationsForSiteId($siteId, $navigation),
            'updated_at' => $this->timestampValue($navigation->updated_at),
        ];
    }

    private function httpsUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $value)) {
            return null;
        }

        $parts = parse_url($value);
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return null;
        }

        $parts['scheme'] = 'https';
        $path = (string) ($parts['path'] ?? '');
        $url = 'https://' . $host;
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            $url .= ':' . (int) $parts['port'];
        }
        $url .= $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }

    private function timestampValue(mixed $value): ?int
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

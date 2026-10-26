<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteNavigation;
use App\Models\SiteNavigationDomain;
use App\Models\SiteNavigationLink;
use App\Models\SiteSetting;
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
        if (!empty($context['agent_user_id'])) {
            return !empty($context['agent_domain_id'])
                ? $this->httpsUrl((string) ($context['app_url'] ?? ''))
                : null;
        }
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

        $destinations = $this->recommendedEntry($destinations, $navigation);

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

    private function recommendedEntry(array $destinations, ?SiteNavigation $navigation): array
    {
        $label = trim((string) ($navigation?->recommended_label ?? '')) ?: '推荐入口';
        $url = $this->publicUrl(trim((string) ($navigation?->recommended_url ?? '')));
        if ($url === null) {
            foreach ($destinations as &$destination) {
                if ($destination['recommended']) {
                    $destination['label'] = $label;
                }
            }
            unset($destination);
            return $destinations;
        }

        $kind = 'manual';
        foreach ($destinations as &$destination) {
            // Retain the real storefront identity only when this is the same URL.
            if ($destination['kind'] === 'site'
                && strtolower(rtrim($destination['url'], '/')) === strtolower(rtrim($url, '/'))) {
                $kind = 'site';
            }
            if ($destination['recommended']) {
                $destination['recommended'] = false;
                $destination['label'] = '站点入口';
            }
        }
        unset($destination);
        array_unshift($destinations, ['label' => $label, 'url' => $url, 'kind' => $kind, 'recommended' => true]);
        return $destinations;
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

        $destinations = $this->destinationsForSiteId($siteId, $navigation);

        return [
            'title' => $title,
            'description' => $description,
            'announcement' => trim((string) ($navigation->announcement ?? '')),
            'logo_url' => $this->httpsUrl($logoUrl),
            'destinations' => $destinations,
            'updated_at' => $this->timestampValue($navigation->updated_at),
            ...$this->pageInformation($siteId, $siteSetting, $destinations),
        ];
    }

    private function pageInformation(?int $siteId, ?SiteSetting $setting, array $destinations): array
    {
        $baseUrl = null;
        foreach (['site', 'proxy'] as $kind) {
            foreach ($destinations as $destination) {
                if ($destination['kind'] === $kind && ($url = $this->publicUrl($destination['url'])) !== null) {
                    $baseUrl = $url;
                    break 2;
                }
            }
        }

        $opportunities = [];
        $helpLinks = [];
        if ($baseUrl !== null) {
            if ((int) admin_setting('invite_gen_limit', 5) > 0 && (float) admin_setting('invite_commission', 10) > 0) {
                $opportunities[] = [
                    'kind' => 'referral', 'title' => '邀请返利',
                    'description' => '分享邀请链接，好友的符合规则订单可产生邀请佣金。',
                    'detail' => '佣金比例与结算条件以账户规则为准',
                    'url' => $this->storefrontUrl($baseUrl, 'referral-center'), 'action' => '查看返利规则',
                ];
            }
            if ((bool) admin_setting('agent_center_enable', false)) {
                $detail = '提交代理申请，由平台审核开通';
                if ((string) admin_setting('agent_center_unlock_mode', 'balance_threshold') === 'balance_threshold') {
                    $threshold = max(0, (int) admin_setting('agent_center_unlock_balance', 0));
                    $detail = $threshold > 0
                        ? '账户余额达到 ' . (string) admin_setting('currency_symbol', '¥') . number_format($threshold / 100, 2) . ' 可申请开通'
                        : '登录代理中心，查看开通条件';
                }
                $opportunities[] = [
                    'kind' => 'agent', 'title' => '代理合作',
                    'description' => '集中管理下级账户、套餐分配与后续服务。',
                    'detail' => $detail,
                    'url' => $this->storefrontUrl($baseUrl, 'agent-center'), 'action' => '了解代理合作',
                ];
            }
            $helpLinks[] = ['kind' => 'help', 'label' => '帮助中心', 'url' => $this->storefrontUrl($baseUrl, 'help-center')];
        }

        // Branch contact information must never fall back to the platform's contacts.
        $supportUrl = $siteId ? $setting?->support_url : admin_setting('customer_service_url', '');
        $supportName = $siteId ? $setting?->support_name : admin_setting('customer_service_name', '');
        $communityUrl = $siteId ? $setting?->telegram_discuss_link : admin_setting('telegram_discuss_link', '');
        foreach ([
            ['kind' => 'support', 'label' => trim((string) $supportName) ?: '联系客服', 'url' => $supportUrl],
            ['kind' => 'community', 'label' => 'Telegram 社群', 'url' => $communityUrl],
            ['kind' => 'terms', 'label' => '服务条款', 'url' => $siteId ? null : admin_setting('tos_url', '')],
        ] as $link) {
            if (($url = $this->publicUrl($link['url'])) !== null) {
                $helpLinks[] = [...$link, 'url' => $url];
            }
        }

        return ['opportunities' => $opportunities, 'help_links' => $helpLinks];
    }

    private function publicUrl(mixed $value): ?string
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);
        return $parts !== false && strtolower($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['pass']) ? $value : null;
    }

    private function storefrontUrl(string $baseUrl, string $route): string
    {
        $parts = parse_url($baseUrl);
        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim($parts['path'] ?? '', '/') . '/'
            . (isset($parts['query']) ? '?' . $parts['query'] : '') . '#/' . $route;
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

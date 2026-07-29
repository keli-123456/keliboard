<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentDomain;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteNavigation;
use App\Models\SiteNavigationDomain;
use App\Models\SiteNavigationLink;
use App\Services\SiteNavigationService;
use App\Services\SiteResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SiteNavigationController extends Controller
{
    public function fetch(SiteNavigationService $navigationService)
    {
        $navigationByScope = SiteNavigation::query()
            ->with(['domains', 'links'])
            ->get()
            ->keyBy('scope_key');

        $payload = [
            $this->payload(null, $navigationByScope->get('platform'), $navigationService),
        ];

        foreach (Site::query()
            ->with('setting')
            ->where('is_default', false)
            ->orderBy('id')
            ->get() as $site) {
            $payload[] = $this->payload(
                $site,
                $navigationByScope->get($navigationService->scopeKey((int) $site->id)),
                $navigationService
            );
        }

        return $this->success($payload);
    }

    public function save(Request $request, SiteNavigationService $navigationService)
    {
        $params = $request->validate([
            'site_id' => 'nullable|integer|min:1',
            'enabled' => 'required|boolean',
            'title' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:500',
            'announcement' => 'nullable|string|max:1000',
            'domains' => 'nullable|array|max:10',
            'domains.*.id' => 'nullable|integer|min:1',
            'domains.*.domain' => 'required|string|max:255',
            'domains.*.status' => 'nullable|string|in:active,disabled',
            'domains.*.is_primary' => 'nullable|boolean',
            'domains.*.sort' => 'nullable|integer|min:0|max:10000',
            'links' => 'nullable|array|max:20',
            'links.*.id' => 'nullable|integer|min:1',
            'links.*.label' => 'required|string|max:120',
            'links.*.url' => 'required|string|max:1000',
            'links.*.enabled' => 'nullable|boolean',
            'links.*.sort' => 'nullable|integer|min:0|max:10000',
        ]);

        $siteId = isset($params['site_id']) ? (int) $params['site_id'] : null;
        $site = $siteId !== null
            ? Site::query()->with('setting')->where('is_default', false)->find($siteId)
            : null;
        if ($siteId !== null && !$site) {
            throw new ApiException('Site does not exist');
        }

        $scopeKey = $navigationService->scopeKey($siteId);
        $existing = SiteNavigation::query()
            ->with(['domains', 'links'])
            ->where('scope_key', $scopeKey)
            ->first();
        $domains = $this->normalizeDomains((array) ($params['domains'] ?? []));
        $links = $this->normalizeLinks((array) ($params['links'] ?? []));
        if ((bool) $params['enabled'] && !collect($domains)->contains('status', SiteNavigationDomain::STATUS_ACTIVE)) {
            throw new ApiException('At least one active navigation domain is required');
        }

        $navigation = DB::transaction(function () use (
            $params,
            $siteId,
            $scopeKey,
            $existing,
            $domains,
            $links
        ): SiteNavigation {
            $navigation = $existing ?: new SiteNavigation();
            $now = time();
            if (!$navigation->exists) {
                $navigation->created_at = $now;
            }
            $navigation->scope_key = $scopeKey;
            $navigation->site_id = $siteId;
            $navigation->enabled = (bool) $params['enabled'];
            $navigation->title = $this->nullableString($params['title'] ?? null);
            $navigation->description = $this->nullableString($params['description'] ?? null);
            $navigation->announcement = $this->nullableString($params['announcement'] ?? null);
            $navigation->updated_at = $now;
            $navigation->save();

            $savedDomainIds = [];
            foreach ($domains as $domainData) {
                $domain = $domainData['id'] > 0
                    ? SiteNavigationDomain::query()
                        ->where('navigation_id', $navigation->id)
                        ->find($domainData['id'])
                    : new SiteNavigationDomain();
                if (!$domain) {
                    throw new ApiException('Navigation domain does not exist');
                }
                if (!$domain->exists) {
                    $domain->created_at = $now;
                }
                $domain->navigation_id = $navigation->id;
                $domain->domain = $domainData['domain'];
                $domain->status = $domainData['status'];
                $domain->is_primary = $domainData['is_primary'];
                $domain->sort = $domainData['sort'];
                $domain->updated_at = $now;
                $domain->save();
                $savedDomainIds[] = (int) $domain->id;
            }
            SiteNavigationDomain::query()
                ->where('navigation_id', $navigation->id)
                ->when($savedDomainIds !== [], fn ($query) => $query->whereNotIn('id', $savedDomainIds))
                ->delete();

            $savedLinkIds = [];
            foreach ($links as $linkData) {
                $link = $linkData['id'] > 0
                    ? SiteNavigationLink::query()
                        ->where('navigation_id', $navigation->id)
                        ->find($linkData['id'])
                    : new SiteNavigationLink();
                if (!$link) {
                    throw new ApiException('Navigation link does not exist');
                }
                if (!$link->exists) {
                    $link->created_at = $now;
                }
                $link->navigation_id = $navigation->id;
                $link->label = $linkData['label'];
                $link->url = $linkData['url'];
                $link->enabled = $linkData['enabled'];
                $link->sort = $linkData['sort'];
                $link->updated_at = $now;
                $link->save();
                $savedLinkIds[] = (int) $link->id;
            }
            SiteNavigationLink::query()
                ->where('navigation_id', $navigation->id)
                ->when($savedLinkIds !== [], fn ($query) => $query->whereNotIn('id', $savedLinkIds))
                ->delete();

            return $navigation->fresh(['domains', 'links']) ?: $navigation;
        });

        return $this->success($this->payload($site, $navigation, $navigationService));
    }

    private function normalizeDomains(array $domains): array
    {
        $result = [];
        $seen = [];
        $hasPrimary = false;
        $resolver = app(SiteResolver::class);
        $appHost = $resolver->normalizeHost((string) parse_url((string) admin_setting('app_url', ''), PHP_URL_HOST));

        foreach ($domains as $index => $domainData) {
            if (!is_array($domainData)) {
                continue;
            }
            $domain = $resolver->normalizeHost((string) ($domainData['domain'] ?? ''));
            if ($domain === '' || filter_var($domain, FILTER_VALIDATE_IP)) {
                throw new ApiException('Invalid navigation domain');
            }
            if (isset($seen[$domain])) {
                throw new ApiException('Duplicate navigation domain');
            }
            $seen[$domain] = true;

            $domainId = (int) ($domainData['id'] ?? 0);
            $alreadyUsed = SiteNavigationDomain::query()
                ->where('domain', $domain)
                ->when($domainId > 0, fn ($query) => $query->where('id', '<>', $domainId))
                ->exists();
            if ($alreadyUsed || SiteDomain::query()->where('domain', $domain)->exists() || $this->agentDomainExists($domain)) {
                throw new ApiException('Domain already assigned');
            }
            if ($appHost !== '' && $domain === $appHost) {
                throw new ApiException('Navigation domain must be separate from the main site domain');
            }

            $status = (string) ($domainData['status'] ?? SiteNavigationDomain::STATUS_ACTIVE);
            $isPrimary = (bool) ($domainData['is_primary'] ?? false);
            $result[] = [
                'id' => $domainId,
                'domain' => $domain,
                'status' => $status,
                'is_primary' => $isPrimary && !$hasPrimary,
                'sort' => (int) ($domainData['sort'] ?? $index),
            ];
            $hasPrimary = $hasPrimary || $isPrimary;
        }

        if (!$hasPrimary && $result !== []) {
            $result[0]['is_primary'] = true;
        }
        return $result;
    }

    private function normalizeLinks(array $links): array
    {
        $result = [];
        $seen = [];
        foreach ($links as $index => $linkData) {
            if (!is_array($linkData)) {
                continue;
            }
            $label = trim((string) ($linkData['label'] ?? ''));
            $url = trim((string) ($linkData['url'] ?? ''));
            if ($label === '' || !$this->validHttpsUrl($url)) {
                throw new ApiException('Navigation links must use a valid HTTPS URL');
            }
            $key = strtolower(rtrim($url, '/'));
            if (isset($seen[$key])) {
                throw new ApiException('Duplicate navigation link');
            }
            $seen[$key] = true;
            $result[] = [
                'id' => (int) ($linkData['id'] ?? 0),
                'label' => $label,
                'url' => $url,
                'enabled' => (bool) ($linkData['enabled'] ?? true),
                'sort' => (int) ($linkData['sort'] ?? $index),
            ];
        }
        return $result;
    }

    private function payload(?Site $site, ?SiteNavigation $navigation, SiteNavigationService $service): array
    {
        $siteId = $site ? (int) $site->id : null;
        $domains = $navigation?->domains
            ->sortBy([['is_primary', 'desc'], ['sort', 'asc'], ['id', 'asc']])
            ->values() ?? collect();
        $links = $navigation?->links
            ->sortBy([['sort', 'asc'], ['id', 'asc']])
            ->values() ?? collect();

        return [
            'site_id' => $siteId,
            'scope_key' => $service->scopeKey($siteId),
            'site_name' => $site?->name ?: (string) admin_setting('app_name', '主站'),
            'site_status' => $site?->status ?: Site::STATUS_ACTIVE,
            'enabled' => (bool) ($navigation?->enabled ?? false),
            'title' => (string) ($navigation?->title ?? ''),
            'description' => (string) ($navigation?->description ?? ''),
            'announcement' => (string) ($navigation?->announcement ?? ''),
            'domains' => $domains->map(fn (SiteNavigationDomain $domain): array => [
                'id' => (int) $domain->id,
                'domain' => (string) $domain->domain,
                'status' => (string) $domain->status,
                'is_primary' => (bool) $domain->is_primary,
                'sort' => (int) $domain->sort,
            ])->all(),
            'links' => $links->map(fn (SiteNavigationLink $link): array => [
                'id' => (int) $link->id,
                'label' => (string) $link->label,
                'url' => (string) $link->url,
                'enabled' => (bool) $link->enabled,
                'sort' => (int) $link->sort,
            ])->all(),
            'preview_url' => $service->urlForSiteId($siteId),
            'destinations' => $service->destinationsForSiteId($siteId, $navigation),
            'updated_at' => $this->timestampValue($navigation?->updated_at),
        ];
    }

    private function validHttpsUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && (string) ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
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

    private function agentDomainExists(string $domain): bool
    {
        try {
            return Schema::hasTable('v2_agent_domain')
                && AgentDomain::query()->where('domain', $domain)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}

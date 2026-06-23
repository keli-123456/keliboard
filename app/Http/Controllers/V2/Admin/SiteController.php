<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentDomain;
use App\Models\Payment;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteSetting;
use App\Services\SiteStorefrontService;
use App\Services\SiteResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{
    public function fetch()
    {
        $sites = Site::query()
            ->with(['domains' => function ($query): void {
                $query->orderByDesc('is_primary')->orderBy('id');
            }])
            ->where('is_default', false)
            ->orderBy('id')
            ->get()
            ->map(fn (Site $site): array => $this->sitePayload($site))
            ->values();

        return $this->success($sites);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'code' => 'required|string|max:64',
            'name' => 'required|string|max:120',
            'status' => 'nullable|string|in:active,disabled',
            'domains' => 'nullable|array',
            'domains.*.id' => 'nullable|integer',
            'domains.*.domain' => 'required|string|max:255',
            'domains.*.status' => 'nullable|string|in:pending,active,disabled',
            'domains.*.is_primary' => 'nullable|boolean',
        ]);

        $id = (int) ($params['id'] ?? 0);
        $code = $this->normalizeCode((string) ($params['code'] ?? ''));
        if ($code === '') {
            throw new ApiException('Site code is required');
        }

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('Site name is required');
        }

        $status = (string) ($params['status'] ?? Site::STATUS_ACTIVE);
        if (!in_array($status, [Site::STATUS_ACTIVE, Site::STATUS_DISABLED], true)) {
            throw new ApiException('Invalid site status');
        }

        $codeExists = Site::query()
            ->where('code', $code)
            ->when($id > 0, fn ($query) => $query->where('id', '<>', $id))
            ->exists();
        if ($codeExists) {
            throw new ApiException('Site code already exists');
        }

        $domains = $this->normalizeDomains((array) ($params['domains'] ?? []), $id);

        $site = DB::transaction(function () use ($id, $code, $name, $status, $domains): Site {
            $site = $id > 0
                ? Site::query()->where('is_default', false)->find($id)
                : new Site();
            if (!$site) {
                throw new ApiException('Site does not exist');
            }

            $now = time();
            if (!$site->exists) {
                $site->created_at = $now;
            }
            $site->code = $code;
            $site->name = $name;
            $site->status = $status;
            $site->is_default = false;
            $site->updated_at = $now;
            $site->save();

            $savedDomainIds = [];
            foreach ($domains as $domainData) {
                $domainId = (int) ($domainData['id'] ?? 0);
                $domain = $domainId > 0
                    ? SiteDomain::query()->where('site_id', $site->id)->find($domainId)
                    : new SiteDomain();

                if (!$domain) {
                    throw new ApiException('Domain does not exist');
                }

                if (!$domain->exists) {
                    $domain->created_at = $now;
                }
                $domain->site_id = $site->id;
                $domain->domain = $domainData['domain'];
                $domain->status = $domainData['status'];
                $domain->is_primary = $domainData['is_primary'];
                $domain->updated_at = $now;
                $domain->save();

                $savedDomainIds[] = (int) $domain->id;
            }

            SiteDomain::query()
                ->where('site_id', $site->id)
                ->when($savedDomainIds !== [], fn ($query) => $query->whereNotIn('id', $savedDomainIds))
                ->delete();

            return $site->fresh(['domains']) ?: $site;
        });

        $site->load(['domains' => function ($query): void {
            $query->orderByDesc('is_primary')->orderBy('id');
        }]);

        return $this->success($this->sitePayload($site));
    }

    public function commerce(Request $request)
    {
        $params = $request->validate([
            'site_id' => 'required|integer',
        ]);

        return $this->success($this->commercePayload($this->site((int) $params['site_id'])));
    }

    public function saveCommerce(Request $request)
    {
        $params = $request->validate([
            'site_id' => 'required|integer',
            'setting' => 'nullable|array',
            'setting.site_name' => 'nullable|string|max:120',
            'setting.logo_url' => 'nullable|string|max:500',
            'setting.landing_theme' => 'nullable|string|max:64',
            'setting.accent_color' => 'nullable|string|max:16',
            'setting.support_name' => 'nullable|string|max:120',
            'setting.support_url' => 'nullable|string|max:500',
            'setting.announcement' => 'nullable|string|max:1000',
            'setting.seo_title' => 'nullable|string|max:160',
            'setting.seo_description' => 'nullable|string|max:255',
            'setting.enabled' => 'nullable|boolean',
            'prices' => 'nullable|array',
            'prices.*.plan_id' => 'required|integer|min:1',
            'prices.*.period' => 'required|string|max:64',
            'prices.*.sale_price' => 'required|integer|min:0',
            'prices.*.enabled' => 'nullable|boolean',
            'overrides' => 'nullable|array',
            'overrides.*.plan_id' => 'required|integer|min:1',
            'overrides.*.display_name' => 'nullable|string|max:120',
            'payments' => 'nullable|array',
        ]);

        $site = $this->site((int) $params['site_id']);

        DB::transaction(function () use ($site, $params): void {
            if (array_key_exists('setting', $params) && is_array($params['setting'])) {
                $this->saveSetting($site, $params['setting']);
            }

            if (!empty($params['prices']) && is_array($params['prices'])) {
                app(SiteStorefrontService::class)->savePrices($site, $params['prices']);
            }

            if (array_key_exists('overrides', $params) && is_array($params['overrides'])) {
                app(SiteStorefrontService::class)->saveOverrides($site, $params['overrides']);
            }

        });

        return $this->success($this->commercePayload($site->fresh(['setting']) ?: $site));
    }

    private function normalizeDomains(array $domains, int $siteId): array
    {
        $result = [];
        $seen = [];
        $hasPrimary = false;
        $resolver = app(SiteResolver::class);

        foreach ($domains as $domain) {
            if (!is_array($domain)) {
                continue;
            }

            $normalized = $resolver->normalizeHost((string) ($domain['domain'] ?? ''));
            if ($normalized === '') {
                throw new ApiException('Invalid domain');
            }

            if (isset($seen[$normalized])) {
                throw new ApiException('Duplicate domain in request');
            }
            $seen[$normalized] = true;

            $domainId = (int) ($domain['id'] ?? 0);
            $exists = SiteDomain::query()
                ->where('domain', $normalized)
                ->when($domainId > 0, fn ($query) => $query->where('id', '<>', $domainId))
                ->when($domainId === 0 && $siteId > 0, fn ($query) => $query->where('site_id', '<>', $siteId))
                ->exists();

            if ($exists || $this->agentDomainExists($normalized)) {
                throw new ApiException('Domain already assigned');
            }

            $status = (string) ($domain['status'] ?? SiteDomain::STATUS_ACTIVE);
            if (!in_array($status, [SiteDomain::STATUS_PENDING, SiteDomain::STATUS_ACTIVE, SiteDomain::STATUS_DISABLED], true)) {
                throw new ApiException('Invalid domain status');
            }

            $isPrimary = (bool) ($domain['is_primary'] ?? false);
            $result[] = [
                'id' => $domainId,
                'domain' => $normalized,
                'status' => $status,
                'is_primary' => false,
            ];

            if ($isPrimary && !$hasPrimary) {
                $result[array_key_last($result)]['is_primary'] = true;
                $hasPrimary = true;
            }
        }

        if (!$hasPrimary && count($result) === 1) {
            $result[0]['is_primary'] = true;
        }

        return $result;
    }

    private function sitePayload(Site $site): array
    {
        return [
            'id' => (int) $site->id,
            'code' => (string) $site->code,
            'name' => (string) $site->name,
            'status' => (string) $site->status,
            'is_default' => false,
            'domains' => $site->domains
                ->map(fn (SiteDomain $domain): array => [
                    'id' => (int) $domain->id,
                    'site_id' => (int) $domain->site_id,
                    'domain' => (string) $domain->domain,
                    'status' => (string) $domain->status,
                    'is_primary' => (bool) $domain->is_primary,
                    'created_at' => $this->timestampValue($domain->created_at),
                    'updated_at' => $this->timestampValue($domain->updated_at),
                ])
                ->values()
                ->all(),
            'created_at' => $this->timestampValue($site->created_at),
            'updated_at' => $this->timestampValue($site->updated_at),
        ];
    }

    private function commercePayload(Site $site): array
    {
        $site->loadMissing(['setting']);

        return [
            'site' => $this->sitePayload($site->loadMissing('domains')),
            'setting' => $site->setting ? [
                'site_id' => (int) $site->setting->site_id,
                'site_name' => (string) ($site->setting->site_name ?? ''),
                'logo_url' => (string) ($site->setting->logo_url ?? ''),
                'landing_theme' => (string) ($site->setting->landing_theme ?? ''),
                'accent_color' => (string) ($site->setting->accent_color ?? ''),
                'support_name' => (string) ($site->setting->support_name ?? ''),
                'support_url' => (string) ($site->setting->support_url ?? ''),
                'announcement' => (string) ($site->setting->announcement ?? ''),
                'seo_title' => (string) ($site->setting->seo_title ?? ''),
                'seo_description' => (string) ($site->setting->seo_description ?? ''),
                'enabled' => (bool) $site->setting->enabled,
                'updated_at' => $this->timestampValue($site->setting->updated_at),
            ] : null,
            'prices' => app(SiteStorefrontService::class)->listPrices($site),
            'payment_policy' => [
                'mode' => 'platform_inherited',
                'description' => 'Site storefronts inherit enabled platform payment methods.',
            ],
            'payments' => [],
            'available_payments' => Payment::query()
                ->select(['id', 'name', 'payment', 'icon', 'enable', 'owner_type'])
                ->where('owner_type', Payment::OWNER_PLATFORM)
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->map(fn (Payment $payment): array => [
                    'id' => (int) $payment->id,
                    'name' => (string) $payment->name,
                    'payment' => (string) $payment->payment,
                    'icon' => (string) ($payment->icon ?? ''),
                    'enable' => (bool) $payment->enable,
                ])
                ->values()
                ->all(),
        ];
    }

    private function site(int $siteId): Site
    {
        $site = Site::query()
            ->with(['domains', 'setting'])
            ->where('is_default', false)
            ->find($siteId);
        if (!$site) {
            throw new ApiException('Site does not exist');
        }

        return $site;
    }

    private function saveSetting(Site $site, array $setting): void
    {
        $now = time();
        SiteSetting::query()->updateOrCreate(
            ['site_id' => $site->id],
            [
                'site_name' => $this->nullableString($setting['site_name'] ?? null),
                'logo_url' => $this->nullableString($setting['logo_url'] ?? null),
                'landing_theme' => $this->nullableString($setting['landing_theme'] ?? null),
                'accent_color' => $this->nullableString($setting['accent_color'] ?? null),
                'support_name' => $this->nullableString($setting['support_name'] ?? null),
                'support_url' => $this->nullableString($setting['support_url'] ?? null),
                'announcement' => $this->nullableString($setting['announcement'] ?? null),
                'seo_title' => $this->nullableString($setting['seo_title'] ?? null),
                'seo_description' => $this->nullableString($setting['seo_description'] ?? null),
                'enabled' => (bool) ($setting['enabled'] ?? true),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeCode(string $code): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($code))) ?: '';
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

    private function agentDomainExists(string $domain): bool
    {
        try {
            if (!app('db')->connection()->getSchemaBuilder()->hasTable('v2_agent_domain')) {
                return false;
            }

            return AgentDomain::query()->where('domain', $domain)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}

<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\SiteResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{
    public function fetch()
    {
        app(SiteResolver::class)->defaultSite();

        $sites = Site::query()
            ->with(['domains' => function ($query): void {
                $query->orderByDesc('is_primary')->orderBy('id');
            }])
            ->orderByDesc('is_default')
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
            'is_default' => 'nullable|boolean',
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
        $isDefault = (bool) ($params['is_default'] ?? false);

        $site = DB::transaction(function () use ($id, $code, $name, $status, $isDefault, $domains): Site {
            $site = $id > 0 ? Site::query()->find($id) : new Site();
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
            $site->is_default = $isDefault;
            $site->updated_at = $now;
            $site->save();

            if ($isDefault) {
                Site::query()
                    ->where('id', '<>', $site->id)
                    ->where('is_default', true)
                    ->update([
                        'is_default' => false,
                        'updated_at' => $now,
                    ]);
            }

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

            if ($exists) {
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
            'is_default' => (bool) $site->is_default,
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
}

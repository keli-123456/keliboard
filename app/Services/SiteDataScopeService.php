<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SiteDataScopeService
{
    public function siteIdForRequest(Request $request, ?User $user = null): ?int
    {
        if (!$this->hasTable('v2_site') || !$this->hasTable('v2_site_domain')) {
            return null;
        }

        $context = app(SiteContextService::class)->resolve($request, $user ?: $request->user());

        return $this->siteIdFromContext($context);
    }

    public function siteIdFromContext(?array $context): ?int
    {
        $siteId = $context['site_id'] ?? null;
        if (is_int($siteId)) {
            return $siteId > 0 ? $siteId : null;
        }

        if (is_string($siteId)) {
            $siteId = trim($siteId);
            if ($siteId !== '' && ctype_digit($siteId) && (int) $siteId > 0) {
                return (int) $siteId;
            }
        }

        return null;
    }

    public function applyNullableSiteScope(Builder $query, ?int $siteId, string $table, string $column = 'site_id'): void
    {
        if (!$this->hasColumn($table, $column)) {
            return;
        }

        $qualifiedColumn = $table . '.' . $column;
        if ($siteId === null) {
            $query->whereNull($qualifiedColumn);

            return;
        }

        $query->where(function (Builder $builder) use ($qualifiedColumn, $siteId): void {
            $builder->whereNull($qualifiedColumn)
                ->orWhere($qualifiedColumn, $siteId);
        });
    }

    public function applyNoticeScope(Builder $query, ?int $siteId): void
    {
        if (!$this->hasColumn('v2_notice', 'site_id')) {
            return;
        }

        if (!$this->hasColumn('v2_notice', 'scope_type')) {
            $this->applyNullableSiteScope($query, $siteId, 'v2_notice');

            return;
        }

        $scopeColumn = 'v2_notice.scope_type';
        $siteColumn = 'v2_notice.site_id';
        $query->where(function (Builder $builder) use ($scopeColumn, $siteColumn, $siteId): void {
            $builder->where($scopeColumn, 'global')
                ->orWhere(function (Builder $legacyGlobal) use ($scopeColumn, $siteColumn): void {
                    $legacyGlobal->whereNull($scopeColumn)
                        ->whereNull($siteColumn);
                });

            if ($siteId === null) {
                $builder->orWhere($scopeColumn, 'platform');

                return;
            }

            $builder->orWhere(function (Builder $siteBuilder) use ($scopeColumn, $siteColumn, $siteId): void {
                $siteBuilder->where($siteColumn, $siteId)
                    ->where(function (Builder $typedSite) use ($scopeColumn): void {
                        $typedSite->where($scopeColumn, 'site')
                            ->orWhereNull($scopeColumn);
                    });
            });
        });
    }

    public function hasColumn(string $table, string $column): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}

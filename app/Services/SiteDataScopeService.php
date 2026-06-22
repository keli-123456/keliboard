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
        if ($siteId === null || !$this->hasColumn($table, $column)) {
            return;
        }

        $qualifiedColumn = $table . '.' . $column;
        $query->where(function (Builder $builder) use ($qualifiedColumn, $siteId): void {
            $builder->whereNull($qualifiedColumn)
                ->orWhere($qualifiedColumn, $siteId);
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

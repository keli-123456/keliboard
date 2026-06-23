<?php

namespace App\Services;

use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SiteUserScopeService
{
    public function context(?Request $request = null): array
    {
        if (!$this->canScopeUsers()) {
            return [
                'enabled' => false,
                'site_id' => null,
                'is_default' => false,
                'source' => 'legacy',
            ];
        }

        $request = $request ?: $this->currentRequest();
        if (!$request) {
            return [
                'enabled' => false,
                'site_id' => null,
                'is_default' => false,
                'source' => 'missing_request',
            ];
        }

        $context = app(SiteContextService::class)->resolve($request);
        if (empty($context['site_id'])) {
            return [
                'enabled' => true,
                'site_id' => null,
                'is_default' => false,
                'source' => $context['source'] ?? 'platform',
            ];
        }

        return [
            ...$context,
            'enabled' => true,
            'site_id' => (int) $context['site_id'],
            'is_default' => (bool) ($context['is_default'] ?? false),
        ];
    }

    public function scopeUserQuery(Builder $query, ?Request $request = null): Builder
    {
        $context = $this->context($request);
        if (empty($context['enabled'])) {
            return $query;
        }

        $siteId = $context['site_id'] ?? null;

        return $this->scopeUserQueryForSiteId($query, $siteId !== null ? (int) $siteId : null);
    }

    public function scopeUserQueryForSiteId(Builder $query, ?int $siteId, ?bool $isDefault = null): Builder
    {
        if (!$this->canScopeUsers()) {
            return $query;
        }

        if (!$siteId) {
            return $query->whereNull('site_id');
        }

        return $query->where('site_id', $siteId);
    }

    public function findUserByEmail(string $email, ?Request $request = null): ?User
    {
        return $this->scopeUserQuery(User::query(), $request)
            ->where('email', $email)
            ->first();
    }

    public function userAttributes(?Request $request = null): array
    {
        $context = $this->context($request);
        if (empty($context['enabled'])) {
            return [];
        }

        return ['site_id' => empty($context['site_id']) ? null : (int) $context['site_id']];
    }

    public function cacheKey(string $key, string $email, ?Request $request = null): string
    {
        return CacheKey::get($key, $this->cacheIdentity($email, $request));
    }

    public function cacheIdentity(string $email, ?Request $request = null): string
    {
        $context = $this->context($request);
        if (empty($context['enabled'])) {
            return $email;
        }

        if (empty($context['site_id'])) {
            return 'site:platform:' . $email;
        }

        return 'site:' . (int) $context['site_id'] . ':' . $email;
    }

    private function canScopeUsers(): bool
    {
        return $this->hasTable('v2_site')
            && $this->hasTable('v2_site_domain')
            && $this->hasTable('v2_user')
            && $this->hasColumn('v2_user', 'site_id');
    }

    private function currentRequest(): ?Request
    {
        try {
            $request = request();
            return $request instanceof Request ? $request : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}

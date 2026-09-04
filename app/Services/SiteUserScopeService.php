<?php

namespace App\Services;

use App\Models\AgentUser;
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
            throw new \LogicException('Site-scoped user operations require an HTTP request.');
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
        $user = $this->scopeUserQuery(User::query(), $request)
            ->where('email', $email)
            ->first();

        return $user instanceof User ? $user : null;
    }

    public function findAuthenticatableUserByEmail(string $email, ?Request $request = null): ?User
    {
        $request = $request ?: $this->currentRequest();
        $agentContext = $request ? app(AgentDomainResolver::class)->resolveRequest($request) : null;

        if (!empty($agentContext['agent_user_id'])) {
            if (!$this->hasTable('v2_agent_user')) {
                return null;
            }

            $user = User::query()
                ->where('email', $email)
                ->whereIn('id', AgentUser::query()
                    ->select('sub_user_id')
                    ->where('agent_user_id', (int) $agentContext['agent_user_id']))
                ->first();

            return $user instanceof User ? $user : null;
        }

        return $this->findUserByEmail($email, $request);
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
        $request = $request ?: $this->currentRequest();
        $context = $this->context($request);
        if (empty($context['enabled'])) {
            return $email;
        }

        $agentContext = $request ? app(AgentDomainResolver::class)->resolveRequest($request) : null;
        if (!empty($agentContext['agent_user_id'])) {
            return 'agent:' . (int) $agentContext['agent_user_id'] . ':' . $email;
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
            return request();
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasTable(string $table): bool
    {
        return app('db')->connection()->getSchemaBuilder()->hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return app('db')->connection()->getSchemaBuilder()->hasColumn($table, $column);
    }
}

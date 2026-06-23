<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentDomain;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;

class NotificationSiteContextService
{
    public function __construct(
        private AgentCommerceContextResolver $agentResolver,
        private AgentSiteSettingService $agentSettingService
    ) {}

    public function forRequest(?Request $request = null, ?User $user = null): array
    {
        $request = $request ?: $this->currentRequest();
        $user = $user ?: ($request?->user() instanceof User ? $request->user() : null);

        return $this->resolve($user, $request);
    }

    public function forUser(User $user, ?Request $request = null): array
    {
        return $this->resolve($user, $request ?: $this->currentRequest());
    }

    public function forTicket(Ticket $ticket, ?User $user = null): array
    {
        $user = $user ?: ($ticket->relationLoaded('user') && $ticket->user instanceof User
            ? $ticket->user
            : User::query()->find((int) $ticket->user_id));

        $agentContext = null;
        if (!empty($ticket->agent_user_id)) {
            $agentContext = [
                'agent_user_id' => (int) $ticket->agent_user_id,
                'agent_domain_id' => $ticket->agent_domain_id !== null ? (int) $ticket->agent_domain_id : null,
                'domain' => $this->agentDomain((int) $ticket->agent_domain_id)?->domain ?? '',
                'source' => 'ticket',
            ];
        }

        $siteContext = null;
        if (!empty($ticket->site_id)) {
            $siteContext = $this->sitePayloadForId((int) $ticket->site_id);
        }

        return $this->resolve($user, null, $agentContext, $siteContext);
    }

    public function templateValues(array $context, array $values = []): array
    {
        return array_merge($values, [
            'name' => $context['app_name'],
            'url' => $context['app_url'],
            'app_name' => $context['app_name'],
            'app_url' => $context['app_url'],
            'support_name' => $context['support_name'],
            'support_url' => $context['support_url'],
        ]);
    }

    public function dispatchContext(array $context): array
    {
        return [
            'brand_source' => $context['brand_source'],
            'site_id' => $context['site_id'],
            'site_domain_id' => $context['site_domain_id'],
            'agent_user_id' => $context['agent_user_id'],
            'agent_domain_id' => $context['agent_domain_id'],
            'domain' => $context['domain'],
            'app_name' => $context['app_name'],
            'app_url' => $context['app_url'],
        ];
    }

    private function resolve(?User $user, ?Request $request = null, ?array $agentContext = null, ?array $siteContext = null): array
    {
        $context = $this->baseContext();
        $sitePayload = $siteContext ?: $this->resolveSitePayload($user, $request);
        if ($sitePayload) {
            $context = $this->applySitePayload($context, $sitePayload);
        }

        $agentContext = $agentContext ?: $this->resolveAgentContext($user, $request);
        if ($agentContext) {
            $context = $this->applyAgentPayload($context, $agentContext);
        }

        return $context;
    }

    private function baseContext(): array
    {
        return [
            'app_name' => (string) admin_setting('app_name', 'XBoard'),
            'app_url' => rtrim((string) admin_setting('app_url', ''), '/'),
            'support_name' => '',
            'support_url' => '',
            'site_id' => null,
            'site_domain_id' => null,
            'agent_user_id' => null,
            'agent_domain_id' => null,
            'domain' => '',
            'brand_source' => 'main',
        ];
    }

    private function resolveSitePayload(?User $user, ?Request $request): ?array
    {
        if ($user instanceof User && !empty($user->site_id)) {
            return $this->sitePayloadForId((int) $user->site_id);
        }

        if ($request && $this->hasTable('v2_site') && $this->hasTable('v2_site_domain')) {
            $context = app(SiteContextService::class)->resolve($request, $user);

            return empty($context['site_id']) ? null : $context;
        }

        return null;
    }

    private function sitePayloadForId(int $siteId): ?array
    {
        if (!$this->hasTable('v2_site')) {
            return null;
        }

        $site = Site::query()
            ->with($this->hasTable('v2_site_setting') ? ['setting'] : [])
            ->where('id', $siteId)
            ->where('status', Site::STATUS_ACTIVE)
            ->where('is_default', false)
            ->first();

        if (!$site) {
            return null;
        }

        $domain = $this->primarySiteDomain($siteId);
        $setting = $site->relationLoaded('setting') ? $site->setting : null;
        $setting = $setting instanceof SiteSetting && $setting->enabled ? $setting : null;

        return [
            'site_id' => (int) $site->id,
            'site_domain_id' => $domain ? (int) $domain->id : null,
            'site_name' => (string) ($setting?->site_name ?: $site->name),
            'domain' => $domain ? (string) $domain->domain : '',
            'support_name' => (string) ($setting?->support_name ?? ''),
            'support_url' => (string) ($setting?->support_url ?? ''),
            'source' => 'site',
        ];
    }

    private function applySitePayload(array $context, array $site): array
    {
        $siteName = trim((string) ($site['site_name'] ?? ''));
        if ($siteName !== '') {
            $context['app_name'] = $siteName;
        }

        $siteDomain = trim((string) ($site['domain'] ?? ''));
        if ($siteDomain !== '') {
            $context['app_url'] = $this->urlFromDomain($siteDomain);
            $context['domain'] = $siteDomain;
        }

        $context['site_id'] = $this->positiveIntOrNull($site['site_id'] ?? $site['id'] ?? null);
        $context['site_domain_id'] = $this->positiveIntOrNull($site['site_domain_id'] ?? null);
        $context['support_name'] = trim((string) ($site['support_name'] ?? $context['support_name']));
        $context['support_url'] = trim((string) ($site['support_url'] ?? $context['support_url']));
        $context['brand_source'] = 'site';

        return $context;
    }

    private function resolveAgentContext(?User $user, ?Request $request): ?array
    {
        if ($request) {
            return $this->agentResolver->resolveRequest($request, $user);
        }

        if ($user instanceof User) {
            return $this->agentResolver->resolveUser($user);
        }

        return null;
    }

    private function applyAgentPayload(array $context, array $agentContext): array
    {
        $agentUserId = $this->positiveIntOrNull($agentContext['agent_user_id'] ?? null);
        if (!$agentUserId) {
            return $context;
        }

        $setting = $this->hasTable('v2_agent_site_setting')
            ? $this->agentSettingService->resolve($agentContext)
            : [];
        $domain = trim((string) ($agentContext['domain'] ?? ''));
        $agentDomainId = $this->positiveIntOrNull($agentContext['agent_domain_id'] ?? null);
        if ($domain === '' && $agentDomainId) {
            $domain = (string) ($this->agentDomain($agentDomainId)?->domain ?? '');
        }
        if ($domain === '') {
            $primaryDomain = $this->primaryAgentDomain($agentUserId);
            if ($primaryDomain) {
                $domain = (string) $primaryDomain->domain;
                $agentDomainId = (int) $primaryDomain->id;
            }
        }

        $siteName = trim((string) ($setting['site_name'] ?? ''));
        if ($siteName !== '') {
            $context['app_name'] = $siteName;
        }
        if ($domain !== '') {
            $context['app_url'] = $this->urlFromDomain($domain);
            $context['domain'] = $domain;
        }

        $supportName = trim((string) ($setting['support_name'] ?? ''));
        if ($supportName !== '') {
            $context['support_name'] = $supportName;
        }
        $supportUrl = trim((string) ($setting['support_url'] ?? ''));
        if ($supportUrl !== '') {
            $context['support_url'] = $supportUrl;
        }

        $context['agent_user_id'] = $agentUserId;
        $context['agent_domain_id'] = $agentDomainId;
        $context['brand_source'] = 'agent';

        return $context;
    }

    private function primarySiteDomain(int $siteId): ?SiteDomain
    {
        if (!$this->hasTable('v2_site_domain')) {
            return null;
        }

        return SiteDomain::query()
            ->where('site_id', $siteId)
            ->where('status', SiteDomain::STATUS_ACTIVE)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();
    }

    private function primaryAgentDomain(int $agentUserId): ?AgentDomain
    {
        if (!$this->hasTable('v2_agent_domain')) {
            return null;
        }

        return AgentDomain::query()
            ->where('agent_user_id', $agentUserId)
            ->where('status', AgentDomain::STATUS_ACTIVE)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();
    }

    private function agentDomain(int $agentDomainId): ?AgentDomain
    {
        if (!$agentDomainId || !$this->hasTable('v2_agent_domain')) {
            return null;
        }

        return AgentDomain::query()->find($agentDomainId);
    }

    private function urlFromDomain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return rtrim((string) admin_setting('app_url', ''), '/');
        }

        if (str_starts_with($domain, 'http://') || str_starts_with($domain, 'https://')) {
            return rtrim($domain, '/');
        }

        return 'https://' . $domain;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
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
}

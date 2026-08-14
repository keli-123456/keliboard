<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Knowledge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class KnowledgeContextService
{
    public function __construct(
        private AgentSiteContextService $agentContextService,
        private SiteContextService $siteContextService
    ) {}

    public function resolve(Request $request, User $user): array
    {
        $agent = $this->agentContextService->resolve($request, $user);
        $site = $this->siteContextService->resolve($request, $user);

        $agentUserId = $this->nullableInt($agent['agent_user_id'] ?? null);
        $agentDomainId = $this->nullableInt($agent['agent_domain_id'] ?? null);
        $siteId = $agentUserId === null ? $this->nullableInt($site['site_id'] ?? null) : null;

        return [
            'scope_type' => $agentUserId !== null
                ? Knowledge::SCOPE_AGENT
                : ($siteId !== null ? Knowledge::SCOPE_SITE : Knowledge::SCOPE_PLATFORM),
            'site_id' => $siteId,
            'agent_user_id' => $agentUserId,
            'agent_domain_id' => $agentDomainId,
            'site_name' => $this->firstString(
                $agent['site_name'] ?? null,
                $site['site_name'] ?? null,
                admin_setting('app_name', 'XBoard')
            ),
            'site_url' => rtrim($request->getSchemeAndHttpHost(), '/'),
            'support_name' => $this->firstString(
                $agent['support_name'] ?? null,
                $site['support_name'] ?? null,
                admin_setting('customer_service_name', '')
            ),
            'support_url' => $this->firstString(
                $agent['support_url'] ?? null,
                $site['support_url'] ?? null,
                admin_setting('customer_service_url', '')
            ),
            'telegram_url' => $this->firstString(
                $site['telegram_discuss_link'] ?? null,
                admin_setting('telegram_discuss_link', '')
            ),
        ];
    }

    public function applyScope(Builder $query, array $context): Builder
    {
        return $query->where(function (Builder $builder) use ($context): void {
            $builder->where(function (Builder $global): void {
                $global->where('scope_type', Knowledge::SCOPE_GLOBAL)
                    ->orWhereNull('scope_type');
            });

            if (($context['scope_type'] ?? '') === Knowledge::SCOPE_AGENT) {
                $builder->orWhere(function (Builder $agent) use ($context): void {
                    $agent->where('scope_type', Knowledge::SCOPE_AGENT)
                        ->where('agent_user_id', (int) $context['agent_user_id'])
                        ->where(function (Builder $domain) use ($context): void {
                            $domain->whereNull('agent_domain_id');
                            if (!empty($context['agent_domain_id'])) {
                                $domain->orWhere('agent_domain_id', (int) $context['agent_domain_id']);
                            }
                        });
                });
                return;
            }

            if (($context['scope_type'] ?? '') === Knowledge::SCOPE_SITE) {
                $builder->orWhere(function (Builder $site) use ($context): void {
                    $site->where('scope_type', Knowledge::SCOPE_SITE)
                        ->where('site_id', (int) $context['site_id']);
                });
                return;
            }

            $builder->orWhere('scope_type', Knowledge::SCOPE_PLATFORM);
        });
    }

    private function firstString(mixed ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }
}

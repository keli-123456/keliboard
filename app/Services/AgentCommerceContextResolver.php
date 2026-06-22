<?php

namespace App\Services;

use App\Models\AgentUser;
use App\Models\User;
use Illuminate\Http\Request;

class AgentCommerceContextResolver
{
    public const SOURCE_USER_BINDING = 'user_binding';
    public const SOURCE_DOMAIN = 'domain';

    public function resolveRequest(Request $request, ?User $user = null): ?array
    {
        $resolvedUser = $user ?: $request->user();
        $userContext = null;
        if ($resolvedUser instanceof User) {
            $userContext = $this->resolveUser($resolvedUser);
        }

        $domainContext = app(AgentDomainResolver::class)->resolveRequest($request);
        if ($domainContext) {
            if (!$userContext || (int) $userContext['agent_user_id'] === (int) $domainContext['agent_user_id']) {
                return array_merge($domainContext, [
                    'source' => self::SOURCE_DOMAIN,
                ]);
            }
        }

        return $userContext;
    }

    public function resolveUser(User $user): ?array
    {
        if (!$this->hasTable('v2_agent_user')) {
            return null;
        }

        $ownership = AgentUser::query()
            ->where('sub_user_id', $user->id)
            ->first();

        if (!$ownership) {
            return null;
        }

        return [
            'agent_user_id' => (int) $ownership->agent_user_id,
            'agent_domain_id' => null,
            'domain' => '',
            'is_primary' => false,
            'source' => self::SOURCE_USER_BINDING,
        ];
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

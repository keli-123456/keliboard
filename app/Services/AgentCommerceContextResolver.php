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
        if ($resolvedUser instanceof User) {
            $context = $this->resolveUser($resolvedUser);
            if ($context) {
                return $context;
            }
        }

        $domainContext = app(AgentDomainResolver::class)->resolveRequest($request);
        if (!$domainContext) {
            return null;
        }

        return array_merge($domainContext, [
            'source' => self::SOURCE_DOMAIN,
        ]);
    }

    public function resolveUser(User $user): ?array
    {
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
}

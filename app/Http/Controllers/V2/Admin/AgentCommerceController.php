<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Services\AgentCenterService;
use App\Services\AgentDomainResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentCommerceController extends Controller
{
    public function domains()
    {
        $domains = AgentDomain::query()
            ->with('agent:id,email')
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn (AgentDomain $domain): array => $this->domainPayload($domain));

        return $this->success($domains);
    }

    public function saveDomain(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'agent_user_id' => 'required|integer|exists:v2_user,id',
            'domain' => 'required|string|max:255',
            'remark' => 'nullable|string|max:255',
            'is_primary' => 'boolean',
        ]);

        $normalizedDomain = app(AgentDomainResolver::class)->normalizeHost((string) $params['domain']);
        if ($normalizedDomain === '') {
            throw new ApiException('Invalid domain');
        }

        $agentUserId = (int) $params['agent_user_id'];
        $this->assertActiveAgent($agentUserId);

        $id = (int) ($params['id'] ?? 0);
        $exists = AgentDomain::query()
            ->where('domain', $normalizedDomain)
            ->when($id > 0, fn ($query) => $query->where('id', '<>', $id))
            ->exists();
        if ($exists) {
            throw new ApiException('Domain already assigned');
        }

        $domain = DB::transaction(function () use ($request, $params, $normalizedDomain, $agentUserId, $id): AgentDomain {
            $domain = $id > 0 ? AgentDomain::query()->find($id) : new AgentDomain();
            if (!$domain) {
                throw new ApiException('Domain does not exist');
            }

            $isPrimary = (bool) ($params['is_primary'] ?? false);
            if ($isPrimary) {
                AgentDomain::query()
                    ->where('agent_user_id', $agentUserId)
                    ->when($id > 0, fn ($query) => $query->where('id', '<>', $id))
                    ->update(['is_primary' => false, 'updated_at' => time()]);
            }

            $domain->agent_user_id = $agentUserId;
            $domain->domain = $normalizedDomain;
            $domain->status = $domain->status ?: AgentDomain::STATUS_ACTIVE;
            $domain->is_primary = $isPrimary;
            $domain->remark = $params['remark'] ?? null;
            if (!$domain->exists) {
                $domain->created_by_admin_id = $request->user()?->id;
                $domain->created_at = time();
            }
            $domain->updated_at = time();
            $domain->save();

            return $domain;
        });

        return $this->success($this->domainPayload($domain->fresh(['agent:id,email']) ?: $domain));
    }

    public function enableDomain(int $id)
    {
        return $this->setDomainStatus($id, AgentDomain::STATUS_ACTIVE);
    }

    public function disableDomain(int $id)
    {
        return $this->setDomainStatus($id, AgentDomain::STATUS_DISABLED);
    }

    public function deleteDomain(int $id)
    {
        $domain = AgentDomain::query()->find($id);
        if (!$domain) {
            throw new ApiException('Domain does not exist');
        }

        $domain->delete();

        return $this->success(true);
    }

    private function setDomainStatus(int $id, string $status)
    {
        $domain = AgentDomain::query()->find($id);
        if (!$domain) {
            throw new ApiException('Domain does not exist');
        }

        $domain->status = $status;
        $domain->updated_at = time();
        $domain->save();

        return $this->success($this->domainPayload($domain->fresh(['agent:id,email']) ?: $domain));
    }

    private function assertActiveAgent(int $agentUserId): void
    {
        $active = AgentProfile::query()
            ->where('user_id', $agentUserId)
            ->where('status', AgentCenterService::STATUS_ACTIVE)
            ->exists();

        if (!$active) {
            throw new ApiException('Agent permission is not active');
        }
    }

    private function domainPayload(AgentDomain $domain): array
    {
        return [
            'id' => (int) $domain->id,
            'agent_user_id' => (int) $domain->agent_user_id,
            'agent_email' => (string) ($domain->agent?->email ?? ''),
            'domain' => (string) $domain->domain,
            'status' => (string) $domain->status,
            'is_primary' => (bool) $domain->is_primary,
            'remark' => $domain->remark,
            'created_by_admin_id' => $domain->created_by_admin_id ? (int) $domain->created_by_admin_id : null,
            'created_at' => $domain->created_at ? (int) $domain->created_at : null,
            'updated_at' => $domain->updated_at ? (int) $domain->updated_at : null,
        ];
    }
}

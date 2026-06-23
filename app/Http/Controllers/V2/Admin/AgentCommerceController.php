<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\SiteDomain;
use App\Models\User;
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
        if ($exists || $this->siteDomainExists($normalizedDomain)) {
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

        $this->assertDomainNotUsedByEnabledPayment((int) $domain->id);

        $domain->delete();

        return $this->success(true);
    }

    public function payments(Request $request)
    {
        $payments = Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->orderBy('id', 'desc')
            ->limit($this->limitFromRequest($request))
            ->get();

        $agentEmails = $this->userEmailMap($payments->pluck('owner_id')->filter()->unique()->all());
        $domainNames = $this->domainNameMap($payments->pluck('owner_domain_id')->filter()->unique()->all());

        return $this->success($payments->map(fn (Payment $payment): array => [
            'id' => (int) $payment->id,
            'agent_user_id' => $this->nullableInt($payment->owner_id),
            'agent_email' => $agentEmails[(int) $payment->owner_id] ?? '',
            'owner_domain_id' => $this->nullableInt($payment->owner_domain_id),
            'owner_domain' => $domainNames[(int) $payment->owner_domain_id] ?? '',
            'payment' => (string) $payment->payment,
            'name' => (string) $payment->name,
            'icon' => $payment->icon,
            'enable' => (bool) $payment->enable,
            'notify_domain' => $payment->notify_domain,
            'sort' => $this->nullableInt($payment->sort),
            'created_at' => $this->timestampValue($payment->created_at),
            'updated_at' => $this->timestampValue($payment->updated_at),
        ])->values());
    }

    public function holds(Request $request)
    {
        $holds = AgentBalanceHold::query()
            ->with(['agent:id,email', 'order.user:id,email'])
            ->orderBy('id', 'desc')
            ->limit($this->limitFromRequest($request))
            ->get();

        return $this->success($holds->map(fn (AgentBalanceHold $hold): array => [
            'id' => (int) $hold->id,
            'agent_user_id' => (int) $hold->agent_user_id,
            'agent_email' => (string) ($hold->agent?->email ?? ''),
            'order_id' => (int) $hold->order_id,
            'trade_no' => (string) $hold->trade_no,
            'amount' => (int) $hold->amount,
            'status' => (string) $hold->status,
            'failure_reason' => $this->snapshotStringValue($hold->metadata, 'failure_reason'),
            'expires_at' => $this->timestampValue($hold->expires_at),
            'captured_at' => $this->timestampValue($hold->captured_at),
            'released_at' => $this->timestampValue($hold->released_at),
            'order_status' => $this->nullableInt($hold->order?->status),
            'order_total_amount' => $this->nullableInt($hold->order?->total_amount),
            'buyer_user_id' => $this->nullableInt($hold->order?->user_id),
            'buyer_email' => (string) ($hold->order?->user?->email ?? ''),
            'created_at' => $this->timestampValue($hold->created_at),
            'updated_at' => $this->timestampValue($hold->updated_at),
        ])->values());
    }

    public function orders(Request $request)
    {
        $contexts = AgentOrderContext::query()
            ->with(['agent:id,email', 'domain:id,domain', 'hold:id,status', 'payment:id,name,payment', 'order.user:id,email'])
            ->orderBy('id', 'desc')
            ->limit($this->limitFromRequest($request))
            ->get();

        return $this->success($contexts->map(fn (AgentOrderContext $context): array => [
            'id' => (int) $context->id,
            'order_id' => (int) $context->order_id,
            'trade_no' => (string) $context->trade_no,
            'agent_user_id' => (int) $context->agent_user_id,
            'agent_email' => (string) ($context->agent?->email ?? ''),
            'agent_domain_id' => $this->nullableInt($context->agent_domain_id),
            'agent_domain' => (string) ($context->domain?->domain ?? ''),
            'source' => $this->snapshotStringValue($context->domain_snapshot, 'source'),
            'payment_id' => $this->nullableInt($context->payment_id),
            'payment_name' => (string) ($context->payment?->name ?? ''),
            'payment_code' => (string) ($context->payment?->payment ?? ''),
            'buyer_user_id' => $this->nullableInt($context->order?->user_id),
            'buyer_email' => (string) ($context->order?->user?->email ?? ''),
            'sale_amount' => (int) $context->sale_amount,
            'cost_amount' => (int) $context->cost_amount,
            'hold_id' => $this->nullableInt($context->hold_id),
            'hold_status' => (string) ($context->hold?->status ?? ''),
            'order_status' => $this->nullableInt($context->order?->status),
            'order_total_amount' => $this->nullableInt($context->order?->total_amount),
            'status' => (string) $context->status,
            'failure_reason' => $this->snapshotStringValue($context->payment_snapshot, 'failure_reason'),
            'created_at' => $this->timestampValue($context->created_at),
            'updated_at' => $this->timestampValue($context->updated_at),
        ])->values());
    }

    private function setDomainStatus(int $id, string $status)
    {
        $domain = AgentDomain::query()->find($id);
        if (!$domain) {
            throw new ApiException('Domain does not exist');
        }

        if ($status === AgentDomain::STATUS_DISABLED) {
            $this->assertDomainNotUsedByEnabledPayment((int) $domain->id);
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
            'source' => $this->domainSource($domain),
            'verification_type' => $domain->verification_type,
            'verified_at' => $this->timestampValue($domain->verified_at),
            'last_checked_at' => $this->timestampValue($domain->last_checked_at),
            'verification_error' => $domain->verification_error,
            'created_by_admin_id' => $domain->created_by_admin_id !== null ? (int) $domain->created_by_admin_id : null,
            'created_by_agent_id' => $domain->created_by_agent_id !== null ? (int) $domain->created_by_agent_id : null,
            'created_at' => $domain->created_at ? (int) $domain->created_at : null,
            'updated_at' => $domain->updated_at ? (int) $domain->updated_at : null,
        ];
    }

    private function domainSource(AgentDomain $domain): string
    {
        if ($domain->created_by_agent_id !== null) {
            return 'agent';
        }

        if ($domain->created_by_admin_id !== null) {
            return 'admin';
        }

        return 'unknown';
    }

    private function assertDomainNotUsedByEnabledPayment(int $domainId): void
    {
        $used = Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->where('owner_domain_id', $domainId)
            ->where('enable', true)
            ->exists();

        if ($used) {
            throw new ApiException('Domain is used by an enabled payment method');
        }
    }

    private function limitFromRequest(Request $request): int
    {
        $limit = (int) $request->input('limit', 100);

        return max(1, min(200, $limit));
    }

    /**
     * @param array<int|string|null> $ids
     * @return array<int, string>
     */
    private function userEmailMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->pluck('email', 'id')
            ->map(fn ($email): string => (string) $email)
            ->all();
    }

    /**
     * @param array<int|string|null> $ids
     * @return array<int, string>
     */
    private function domainNameMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        return AgentDomain::query()
            ->whereIn('id', $ids)
            ->pluck('domain', 'id')
            ->map(fn ($domain): string => (string) $domain)
            ->all();
    }

    private function nullableInt($value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function snapshotStringValue($snapshot, string $key): string
    {
        $value = data_get($snapshot, $key, '');
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            try {
                return (string) $value;
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
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

    private function siteDomainExists(string $domain): bool
    {
        try {
            if (!app('db')->connection()->getSchemaBuilder()->hasTable('v2_site_domain')) {
                return false;
            }

            return SiteDomain::query()->where('domain', $domain)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}

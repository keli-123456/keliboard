<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentDomain;
use App\Models\Payment;
use App\Services\AgentOperationsService;
use Illuminate\Http\Request;

class AgentOperationsController extends Controller
{
    public function summary(Request $request)
    {
        return $this->success($this->service()->adminSummary());
    }

    public function agents(Request $request)
    {
        return $this->success($this->service()->adminAgents($request->all()));
    }

    public function agent(Request $request, int $agentUserId)
    {
        return $this->success($this->service()->adminAgentDetail($agentUserId));
    }

    public function agentOrders(Request $request, int $agentUserId)
    {
        return $this->success($this->service()->adminOrdersForAgent($agentUserId, $request->all()));
    }

    public function updateAgentCostSite(Request $request, int $agentUserId)
    {
        $siteId = $request->input('site_id');
        if ($siteId === null || $siteId === '') {
            $siteId = null;
        } elseif (!is_numeric($siteId)) {
            throw new ApiException('Cost site is not available');
        } else {
            $siteId = (int) $siteId;
        }

        return $this->success($this->service()->updateAgentCostSite($agentUserId, $siteId));
    }

    public function disablePayment(int $paymentId)
    {
        return $this->setPaymentEnabled($paymentId, false);
    }

    public function enablePayment(int $paymentId)
    {
        return $this->setPaymentEnabled($paymentId, true);
    }

    public function disableDomain(int $domainId)
    {
        $domain = AgentDomain::query()->find($domainId);
        if (!$domain) {
            throw new ApiException('Domain does not exist');
        }

        $domain->status = AgentDomain::STATUS_DISABLED;
        $domain->updated_at = time();
        $domain->save();

        return $this->success($this->domainPayload($domain));
    }

    private function service(): AgentOperationsService
    {
        return app(AgentOperationsService::class);
    }

    private function setPaymentEnabled(int $paymentId, bool $enabled)
    {
        $payment = Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->find($paymentId);
        if (!$payment) {
            throw new ApiException('Agent payment does not exist');
        }

        $payment->enable = $enabled;
        $payment->updated_at = time();
        $payment->save();

        return $this->success($this->paymentPayload($payment));
    }

    private function paymentPayload(Payment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'agent_user_id' => $this->nullableInt($payment->owner_id),
            'owner_domain_id' => $this->nullableInt($payment->owner_domain_id),
            'payment' => (string) $payment->payment,
            'name' => (string) $payment->name,
            'enable' => (bool) $payment->enable,
            'updated_at' => $this->timestampValue($payment->updated_at),
        ];
    }

    private function domainPayload(AgentDomain $domain): array
    {
        return [
            'id' => (int) $domain->id,
            'agent_user_id' => (int) $domain->agent_user_id,
            'domain' => (string) $domain->domain,
            'status' => (string) $domain->status,
            'updated_at' => $this->timestampValue($domain->updated_at),
        ];
    }

    private function nullableInt($value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function timestampValue($value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

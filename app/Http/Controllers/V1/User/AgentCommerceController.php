<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\AgentDomain;
use App\Services\AgentPaymentService;
use App\Services\AgentStorefrontService;
use Illuminate\Http\Request;

class AgentCommerceController extends Controller
{
    public function domains(Request $request)
    {
        return $this->success($this->domainList((int) $request->user()->id));
    }

    public function availablePaymentMethods(Request $request)
    {
        return $this->success($this->paymentService()->availableMethods());
    }

    public function payments(Request $request)
    {
        return $this->success($this->paymentService()->list($request->user()));
    }

    public function paymentForm(Request $request)
    {
        $params = $request->validate([
            'payment' => 'required|string|max:64',
            'id' => 'nullable|integer',
        ]);

        return $this->success($this->paymentService()->form(
            $request->user(),
            (string) $params['payment'],
            isset($params['id']) ? (int) $params['id'] : null
        ));
    }

    public function savePayment(Request $request, ?int $id = null)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'owner_domain_id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'payment' => 'required|string|max:64',
            'config' => 'required|array',
            'notify_domain' => 'nullable|url',
            'handling_fee_fixed' => 'nullable|integer|min:0',
            'handling_fee_percent' => 'nullable|numeric|between:0,100',
            'enable' => 'boolean',
            'sort' => 'nullable|integer',
        ]);

        if ($id !== null) {
            $params['id'] = $id;
        }

        return $this->success($this->paymentService()->save($request->user(), $params));
    }

    public function togglePayment(Request $request, int $id)
    {
        return $this->success($this->paymentService()->toggle($request->user(), $id));
    }

    public function deletePayment(Request $request, int $id)
    {
        return $this->success($this->paymentService()->delete($request->user(), $id));
    }

    public function prices(Request $request)
    {
        return $this->success($this->storefrontService()->listPrices($request->user()));
    }

    public function savePrices(Request $request)
    {
        $params = $request->validate([
            'items' => 'required|array',
            'items.*.plan_id' => 'required|integer|min:1',
            'items.*.period' => 'required|string|max:64',
            'items.*.sale_price' => 'required|integer|min:0',
            'items.*.enabled' => 'boolean',
        ]);

        return $this->success($this->storefrontService()->savePrices($request->user(), $params['items']));
    }

    public function commerceSummary(Request $request)
    {
        return $this->success([
            'domains' => $this->domainList((int) $request->user()->id),
            'payments' => $this->paymentService()->list($request->user()),
            'prices' => $this->storefrontService()->listPrices($request->user()),
        ]);
    }

    private function paymentService(): AgentPaymentService
    {
        return app(AgentPaymentService::class);
    }

    private function storefrontService(): AgentStorefrontService
    {
        return app(AgentStorefrontService::class);
    }

    private function domainList(int $agentUserId): array
    {
        return AgentDomain::query()
            ->where('agent_user_id', $agentUserId)
            ->orderByDesc('is_primary')
            ->orderBy('domain')
            ->get()
            ->map(fn (AgentDomain $domain): array => [
                'id' => (int) $domain->id,
                'domain' => (string) $domain->domain,
                'status' => (string) $domain->status,
                'is_primary' => (bool) $domain->is_primary,
                'remark' => $domain->remark,
            ])
            ->values()
            ->all();
    }
}

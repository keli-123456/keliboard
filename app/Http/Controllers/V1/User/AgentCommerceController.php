<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Services\AgentCenterService;
use App\Services\AgentDomainSelfService;
use App\Services\AgentPaymentService;
use App\Services\AgentSiteSettingService;
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

    public function saveDomain(Request $request)
    {
        $params = $request->validate([
            'domain' => 'required|string|max:255',
            'remark' => 'nullable|string|max:255',
        ]);

        return $this->success($this->domainService()->createPending(
            $request->user(),
            (string) $params['domain'],
            $params['remark'] ?? null
        ));
    }

    public function verifyDomain(Request $request, int $id)
    {
        return $this->success($this->domainService()->verify($request->user(), $id));
    }

    public function deleteDomain(Request $request, int $id)
    {
        return $this->success($this->domainService()->delete($request->user(), $id));
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

    public function siteSettings(Request $request)
    {
        return $this->success([
            'settings' => $this->siteSettingService()->list($request->user()),
        ]);
    }

    public function saveSiteSetting(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'agent_domain_id' => 'nullable|integer',
            'site_name' => 'nullable|string|max:80',
            'logo_url' => 'nullable|string|max:500',
            'landing_theme' => 'nullable|string|max:32',
            'accent_color' => 'nullable|string|max:16',
            'support_name' => 'nullable|string|max:80',
            'support_url' => 'nullable|string|max:500',
            'announcement' => 'nullable|string|max:500',
            'seo_title' => 'nullable|string|max:120',
            'seo_description' => 'nullable|string|max:255',
            'enabled' => 'boolean',
        ]);

        return $this->success($this->siteSettingService()->save($request->user(), $params));
    }

    public function commerceSummary(Request $request)
    {
        $domains = $this->domainList((int) $request->user()->id);

        return $this->success([
            'domains' => $domains,
            'payment_domains' => $this->paymentDomainList($domains),
            'domain_limit' => $this->domainService()->domainLimit(),
            'payments' => $this->paymentService()->list($request->user()),
            'prices' => $this->storefrontService()->listPrices($request->user()),
            'site_settings' => $this->siteSettingService()->list($request->user()),
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

    private function domainService(): AgentDomainSelfService
    {
        return app(AgentDomainSelfService::class);
    }

    private function siteSettingService(): AgentSiteSettingService
    {
        return app(AgentSiteSettingService::class);
    }

    private function domainList(int $agentUserId): array
    {
        $this->assertActiveAgent($agentUserId);
        $domainService = $this->domainService();

        return AgentDomain::query()
            ->where('agent_user_id', $agentUserId)
            ->orderByDesc('is_primary')
            ->orderBy('domain')
            ->get()
            ->map(fn (AgentDomain $domain): array => $domainService->payload($domain))
            ->values()
            ->all();
    }

    private function paymentDomainList(array $domains): array
    {
        return collect($domains)
            ->filter(fn (array $domain): bool => ($domain['status'] ?? null) === AgentDomain::STATUS_ACTIVE)
            ->values()
            ->all();
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
}

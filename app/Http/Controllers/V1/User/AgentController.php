<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\AgentCenterService;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function overview(Request $request)
    {
        return $this->success($this->service()->overview($request->user()));
    }

    public function unlock(Request $request)
    {
        return $this->success($this->service()->unlock($request->user()));
    }

    public function users(Request $request)
    {
        return $this->success($this->service()->listUsers($request->user()));
    }

    public function createUser(Request $request)
    {
        $params = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6|max:128',
            'remark' => 'nullable|string|max:255',
        ]);

        return $this->success($this->service()->createSubordinate($request->user(), $params));
    }

    public function subscribeLink(Request $request, int $id)
    {
        return $this->success($this->service()->subscribeLink($request->user(), $id));
    }

    public function assignPlanPreview(Request $request, int $id)
    {
        $params = $request->validate([
            'plan_id' => 'required|integer|min:1',
            'period' => 'required|string|max:64',
        ]);

        return $this->success($this->service()->previewAssignPlan($request->user(), $id, $params));
    }

    public function assignPlan(Request $request, int $id)
    {
        $params = $request->validate([
            'plan_id' => 'required|integer|min:1',
            'period' => 'required|string|max:64',
        ]);

        return $this->success($this->service()->assignPlan($request->user(), $id, $params));
    }

    public function resetTrafficPreview(Request $request, int $id)
    {
        return $this->success($this->service()->previewResetTraffic($request->user(), $id));
    }

    public function resetTraffic(Request $request, int $id)
    {
        return $this->success($this->service()->resetTraffic($request->user(), $id));
    }

    public function ledger(Request $request)
    {
        $limit = (int) $request->input('limit', 50);
        return $this->success($this->service()->ledger($request->user(), $limit));
    }

    private function service(): AgentCenterService
    {
        return app(AgentCenterService::class);
    }
}

<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Services\AgentCenterService;
use App\Services\AgentOperationsService;
use Illuminate\Http\Request;

class AgentOperationsController extends Controller
{
    public function summary(Request $request)
    {
        $this->assertActiveAgent((int) $request->user()->id);

        return $this->success($this->service()->agentSummary($request->user()));
    }

    public function orders(Request $request)
    {
        $this->assertActiveAgent((int) $request->user()->id);

        return $this->success($this->service()->agentOrders($request->user(), $request->all()));
    }

    public function order(Request $request, string $tradeNo)
    {
        $this->assertActiveAgent((int) $request->user()->id);

        return $this->success($this->service()->agentOrderDetail($request->user(), $tradeNo));
    }

    private function service(): AgentOperationsService
    {
        return app(AgentOperationsService::class);
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

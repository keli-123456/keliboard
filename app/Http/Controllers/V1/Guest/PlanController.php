<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Services\AgentStorefrontService;
use App\Services\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{

    protected $planService;
    public function __construct(PlanService $planService)
    {
        $this->planService = $planService;
    }
    public function fetch(Request $request)
    {
        $plan = $this->planService->getAvailablePlans();
        $plan = app(AgentStorefrontService::class)->plansForRequest($request, $plan);
        return $this->success(PlanResource::collection($plan));
    }
}

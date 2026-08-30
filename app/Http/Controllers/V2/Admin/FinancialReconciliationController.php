<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\FinancialReconciliationService;
use Illuminate\Http\Request;

class FinancialReconciliationController extends Controller
{
    public function overview(Request $request, FinancialReconciliationService $service)
    {
        $filters = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
            'scope' => 'nullable|in:all,platform,site,agent',
            'site_id' => 'nullable|integer|min:1',
            'agent_user_id' => 'nullable|integer|min:1',
            'category' => 'nullable|in:all,order,payment,refund,commission,agent,gift_card',
            'severity' => 'nullable|in:all,high,medium,low',
            'keyword' => 'nullable|string|max:120',
        ]);

        return $this->success($service->overview($filters));
    }
}

<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserBusinessTimelineService;
use App\Services\UserSubscriptionDiagnosticsService;
use Illuminate\Http\Request;

class UserInsightController extends Controller
{
    public function subscription(Request $request, UserSubscriptionDiagnosticsService $service)
    {
        $request->validate(['id' => 'required|integer|min:1', 'flag' => 'required|string|max:160|regex:/^[a-zA-Z0-9 ._\/-]+$/']);
        $user = $this->findUser($request);
        return $this->success($service->inspect($user, $request))->header('Cache-Control', 'private, no-store');
    }

    public function timeline(Request $request, UserBusinessTimelineService $service)
    {
        $request->validate(['id' => 'required|integer|min:1', 'page' => 'nullable|integer|min:1|max:10000',
            'per_page' => 'nullable|integer|min:1|max:50']);
        $user = $this->findUser($request);
        return $this->success($service->inspect($user, $request->integer('page', 1), $request->integer('per_page', 20)))
            ->header('Cache-Control', 'private, no-store');
    }

    private function findUser(Request $request): User
    {
        return User::query()->findOrFail($request->integer('id'), ['id', 'site_id', 'plan_id', 'group_id',
            'expired_at', 'banned', 'u', 'd', 'transfer_enable', 'created_at']);
    }
}

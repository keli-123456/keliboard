<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\AgentCollectionService;
use App\Services\AgentProfitService;
use Illuminate\Http\Request;

class AgentProfitController extends Controller
{
    private function adminId(Request $request): int
    {
        abort_unless((bool) $request->user()->is_admin, 403);
        return (int) $request->user()->id;
    }

    public function show(Request $request, int $agentUserId)
    {
        $this->adminId($request);
        return $this->success([
            'collection' => app(AgentCollectionService::class)->view($agentUserId),
            'wallet' => app(AgentProfitService::class)->summary($agentUserId),
            'channels' => Payment::where('owner_type', Payment::OWNER_PLATFORM)->where('payment', '!=', 'balance')->orderBy('sort')->get(['id', 'name', 'enable']),
        ]);
    }

    public function configure(Request $request, int $agentUserId)
    {
        $adminId = $this->adminId($request);
        $data = $request->validate(AgentCollectionService::validationRules());
        return $this->success(app(AgentCollectionService::class)->configure($agentUserId, $data, $adminId));
    }

    public function policy(Request $request)
    {
        $this->adminId($request);
        return $this->success([
            'policy' => app(AgentCollectionService::class)->policy(),
            'channels' => Payment::where('owner_type', Payment::OWNER_PLATFORM)->where('payment', '!=', 'balance')
                ->orderBy('sort')->get(['id', 'name', 'enable']),
        ]);
    }

    public function configurePolicy(Request $request)
    {
        $adminId = $this->adminId($request);
        $data = $request->validate(array_merge(AgentCollectionService::validationRules(), [
            'enabled' => 'required|boolean', 'use_global' => 'required|boolean',
            'revision' => 'required|integer|min:0|max:4294967294', 'confirm' => 'required|accepted',
        ]));
        return $this->success(app(AgentCollectionService::class)->configurePolicy($data, $adminId));
    }

    public function withdrawals(Request $request)
    {
        $this->adminId($request);
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:100000', 'agent_user_id' => 'nullable|integer|min:1']);
        return $this->success(app(AgentProfitService::class)->withdrawals($data['agent_user_id'] ?? null, $data['page'] ?? 1));
    }

    public function review(Request $request, int $id)
    {
        $adminId = $this->adminId($request);
        $data = $request->validate([
            'action' => 'required|in:approve,paid,reject',
            'reference' => 'nullable|string|max:255|required_if:action,paid',
            'note' => 'nullable|string|max:500|required_if:action,reject',
        ]);
        return $this->success(app(AgentProfitService::class)->review(
            $id, $data['action'], $adminId, $data['reference'] ?? '', $data['note'] ?? ''
        ));
    }
}

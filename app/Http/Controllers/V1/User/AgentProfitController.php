<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Services\AgentCollectionService;
use App\Services\AgentProfitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentProfitController extends Controller
{
    public function overview(Request $request)
    {
        $id = (int) $request->user()->id;
        AgentProfile::where('user_id', $id)->firstOrFail();
        return $this->success([
            'collection' => app(AgentCollectionService::class)->view($id),
            'wallet' => app(AgentProfitService::class)->summary($id),
        ]);
    }

    public function mode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:self,platform']);
        return $this->success(app(AgentCollectionService::class)->setMode((int) $request->user()->id, $data['mode']));
    }

    public function earnings(Request $request)
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $query = DB::table('v2_agent_profit')->where('agent_user_id', $request->user()->id);
        $total = $query->count();
        return $this->success(['items' => $query->orderByDesc('id')->forPage($data['page'] ?? 1, 20)->get(), 'total' => $total]);
    }

    public function withdrawals(Request $request)
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        return $this->success(app(AgentProfitService::class)->withdrawals((int) $request->user()->id, $data['page'] ?? 1));
    }

    public function withdraw(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:1|max:100000000',
            'method' => 'required|in:alipay,bank',
            'account' => 'required|string|max:500',
            'request_key' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,64}\z/'],
        ]);
        return $this->success(app(AgentProfitService::class)->requestWithdrawal((int) $request->user()->id, $data));
    }
}

<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Jobs\ApplyPlanToUsersJob;
use App\Jobs\RecalculateNextResetAtJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->withCount([
                'users',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->get();

        return $this->success($plans);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }
            $oldResetMethod = $plan->reset_traffic_method;
            
            DB::beginTransaction();
            try {
                $plan->update($params);
                $resetMethodChanged = array_key_exists('reset_traffic_method', $params)
                    && $oldResetMethod !== $plan->reset_traffic_method;
                DB::commit();
                if ($resetMethodChanged) {
                    RecalculateNextResetAtJob::dispatch((int) $plan->id);
                }
                if ($request->boolean('force_update')) {
                    return $this->success($this->dispatchPlanApply($plan->fresh() ?? $plan));
                }
                return $this->success(true);
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }
        if (!Plan::create($params)) {
            return $this->fail([500, '创建失败']);
        }
        return $this->success(true);
    }

    public function applyUsers(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
        ]);

        $plan = Plan::find((int) $params['id']);
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            return $this->success($this->dispatchPlanApply($plan));
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '同步失败']);
        }
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }
        
        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }
        
        return $this->success($plan->delete());
    }

    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                $plan = Plan::find($v);
                if (!$plan || !$plan->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    private function dispatchPlanApply(Plan $plan): array
    {
        ApplyPlanToUsersJob::dispatch((int) $plan->id);

        return [
            'queued_users' => (int) $plan->users()->count(),
        ];
    }
}

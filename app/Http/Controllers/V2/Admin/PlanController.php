<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\UserSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function __construct(
        private UserSyncService $userSyncService,
    ) {
    }

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
            
            DB::beginTransaction();
            try {
                $plan->update($params);
                DB::commit();
                if ($request->boolean('force_update')) {
                    $result = $this->applyPlanToUsers($plan->fresh() ?? $plan);
                    return $this->success($result);
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
            return $this->success($this->applyPlanToUsers($plan));
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
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
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

    private function applyPlanToUsers(Plan $plan): array
    {
        $processedUsers = 0;
        $attributes = $this->planUserAttributes($plan);

        User::query()
            ->where('plan_id', $plan->id)
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users) use (&$processedUsers, $attributes) {
                $userIds = $users->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->filter(fn(int $id) => $id > 0)
                    ->values()
                    ->all();

                if (empty($userIds)) {
                    return;
                }

                User::query()
                    ->whereIn('id', $userIds)
                    ->update($attributes);

                User::query()
                    ->whereIn('id', $userIds)
                    ->get()
                    ->each(function (User $user): void {
                        $this->userSyncService->syncUser($user, 'plan_apply');
                    });

                $processedUsers += count($userIds);
            });

        return [
            'processed_users' => $processedUsers,
        ];
    }

    private function planUserAttributes(Plan $plan): array
    {
        return [
            'group_id' => $plan->group_id,
            'transfer_enable' => (int) $plan->transfer_enable * 1073741824,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => $plan->device_limit,
        ];
    }
}

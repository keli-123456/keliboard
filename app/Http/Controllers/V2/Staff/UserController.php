<?php

namespace App\Http\Controllers\V2\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SubscriptionRiskContextService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public static function transformUserData(User $user): array
    {
        $plan = $user->relationLoaded('plan') ? $user->plan : null;

        return [
            'id' => (int) $user->id,
            'email' => (string) $user->email,
            'site_id' => $user->site_id !== null ? (int) $user->site_id : null,
            'plan_id' => $user->plan_id !== null ? (int) $user->plan_id : null,
            'group_id' => $user->group_id !== null ? (int) $user->group_id : null,
            'transfer_enable' => $user->transfer_enable !== null ? (int) $user->transfer_enable : null,
            'u' => $user->u !== null ? (int) $user->u : null,
            'd' => $user->d !== null ? (int) $user->d : null,
            'expired_at' => $user->expired_at !== null ? (int) $user->expired_at : null,
            'banned' => (bool) $user->banned,
            'balance' => $user->balance !== null ? ($user->balance / 100) : null,
            'commission_balance' => $user->commission_balance !== null ? ($user->commission_balance / 100) : null,
            'is_staff' => (bool) $user->is_staff,
            'created_at' => is_numeric($user->created_at) ? (int) $user->created_at : $user->created_at,
            'updated_at' => is_numeric($user->updated_at) ? (int) $user->updated_at : $user->updated_at,
            'plan' => $plan ? [
                'id' => (int) $plan->id,
                'name' => (string) $plan->name,
            ] : null,
            'site' => $user->relationLoaded('site') && $user->site ? [
                'id' => (int) $user->site->id,
                'code' => (string) $user->site->code,
                'name' => (string) $user->site->name,
            ] : null,
        ];
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
        ], [
            'id.required' => '用户ID不能为空',
        ]);

        $user = $this->applySiteScope(
            User::with(['plan:id,name', 'site:id,code,name']),
            (string) $request->input('site_scope', 'all')
        )->find($request->integer('id'));
        if (!$user) {
            return $this->fail([404, '用户不存在']);
        }

        return $this->success($this->transformUserDataWithRisk($user));
    }

    /** @return array<string, mixed> */
    private function transformUserDataWithRisk(User $user): array
    {
        $data = self::transformUserData($user);
        $data['risk_context'] = app(SubscriptionRiskContextService::class)->build(
            (int) $user->id,
            (string) $user->email
        );

        return $data;
    }

    private function applySiteScope($query, string $siteScope)
    {
        $siteScope = trim($siteScope);
        if ($siteScope === 'platform') {
            return $query->whereNull('site_id');
        }
        if (ctype_digit($siteScope) && (int) $siteScope > 0) {
            return $query->where('site_id', (int) $siteScope);
        }

        return $query;
    }

    public function getUserInfoByEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email:strict',
        ], [
            'email.required' => '邮箱不能为空',
        ]);

        $email = (string) $request->input('email');
        $siteScope = trim((string) $request->input('site_scope', 'all'));
        $users = $this->applySiteScope(
            User::with(['plan:id,name', 'site:id,code,name'])->where('email', $email),
            $siteScope
        )->limit(2)->get();

        if ($users->isEmpty()) {
            return $this->fail([404, '用户不存在']);
        }
        if (($siteScope === '' || $siteScope === 'all') && $users->count() > 1) {
            return $this->fail([409, '该邮箱存在于多个站点，请选择站点后查询']);
        }

        return $this->success($this->transformUserDataWithRisk($users->first()));
    }
}


<?php

namespace App\Http\Controllers\V2\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        ];
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
        ], [
            'id.required' => '用户ID不能为空',
        ]);

        $user = User::with('plan:id,name')->find($request->integer('id'));
        if (!$user) {
            return $this->fail([404, '用户不存在']);
        }

        return $this->success(self::transformUserData($user));
    }

    public function getUserInfoByEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email:strict',
        ], [
            'email.required' => '邮箱不能为空',
        ]);

        $email = (string) $request->input('email');
        $user = User::with('plan:id,name')->where('email', $email)->first();
        if (!$user) {
            return $this->fail([404, '用户不存在']);
        }

        return $this->success(self::transformUserData($user));
    }
}


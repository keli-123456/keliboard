<?php

namespace App\Http\Controllers\V1\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\NodeResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function bootstrap(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'email',
                'transfer_enable',
                'last_login_at',
                'created_at',
                'banned',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid',
                'token',
                'u',
                'd',
                'device_limit',
                'speed_limit',
                'next_reset_at',
            ])
            ->first();

        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }

        $userInfo = [
            'email' => $user->email,
            'transfer_enable' => $user->transfer_enable,
            'last_login_at' => $user->last_login_at,
            'created_at' => $user->created_at,
            'banned' => $user->banned,
            'remind_expire' => $user->remind_expire,
            'remind_traffic' => $user->remind_traffic,
            'expired_at' => $user->expired_at,
            'balance' => $user->balance,
            'commission_balance' => $user->commission_balance,
            'plan_id' => $user->plan_id,
            'discount' => $user->discount,
            'commission_rate' => $user->commission_rate,
            'telegram_id' => $user->telegram_id,
            'uuid' => $user->uuid,
            'avatar_url' => 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon',
        ];

        $subscribe = [
            'plan_id' => $user->plan_id,
            'token' => $user->token,
            'expired_at' => $user->expired_at,
            'u' => $user->u,
            'd' => $user->d,
            'transfer_enable' => $user->transfer_enable,
            'email' => $user->email,
            'uuid' => $user->uuid,
            'device_limit' => $user->device_limit,
            'speed_limit' => $user->speed_limit,
            'next_reset_at' => $user->next_reset_at,
        ];

        if ($user->plan_id) {
            $plan = Plan::find($user->plan_id);
            if (!$plan) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            $subscribe['plan'] = $plan;
        }

        $subscribe['subscribe_url'] = Helper::getSubscribeUrl($user->token);
        $userService = new UserService();
        $subscribe['reset_day'] = $userService->getResetDay($user);
        $subscribe = HookManager::filter('user.subscribe.response', $subscribe);

        $servers = [];
        if ($userService->isAvailable($user)) {
            $servers = ServerService::getAvailableServers($user);
        }
        $serverData = NodeResource::collection($servers)->resolve($request);

        return $this->success([
            'app' => [
                'name' => admin_setting('app_name', 'Xboard'),
                'url' => admin_setting('app_url'),
                'logo' => admin_setting('logo'),
                'tos_url' => admin_setting('tos_url'),
            ],
            'user' => $userInfo,
            'servers' => $serverData,
            'subscribe' => $subscribe,
        ]);
    }
}


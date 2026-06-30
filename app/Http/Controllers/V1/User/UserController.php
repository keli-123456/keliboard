<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Http\Resources\PlanResource;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentCommerceContextResolver;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Services\SubscriptionProxy\SubscriptionProxyProbeService;
use App\Services\TenantPlanCatalogService;
use App\Services\TenantPlanPricingService;
use App\Services\UserOnlineService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    protected $loginService;

    public function __construct(
        LoginService $loginService
    ) {
        $this->loginService = $loginService;
    }

    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $authService = new AuthService($user);
        return $this->success($authService->getSessions());
    }

    public function removeActiveSession(Request $request)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $authService = new AuthService($user);
        return $this->success($authService->removeSession($request->input('session_id')));
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user()?->id ? true : false
        ];
        if ($request->user()?->is_admin) {
            $data['is_admin'] = true;
        }
        return $this->success($data);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $request->input('old_password'),
                $user->password
            )
        ) {
            return $this->fail([400, __('The old password is wrong')]);
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            return $this->fail([400, __('Save failed')]);
        }
        return $this->success(true);
    }

    public function info(Request $request)
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
                'auto_renew_enable',
                'auto_renew_period',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $user['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        return $this->success($user);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            User::where('invite_user_id', $request->user()->id)
                ->count()
        ];
        return $this->success($stat);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'email',
                'uuid',
                'device_limit',
                'speed_limit',
                'next_reset_at'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        if ($user->plan_id) {
            $plan = Plan::find($user->plan_id);
            if (!$plan) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            $plan = $this->decorateSubscribePlan($request, $plan);
            $user['plan'] = $plan;
        }
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        $subscriptionProxy = app(SubscriptionProxyProbeService::class)->userPayload((string) $user['token']);
        $user['subscription_proxy'] = $subscriptionProxy;
        if (!empty($subscriptionProxy['subscribe_url'])) {
            $user['accelerated_subscribe_url'] = $subscriptionProxy['subscribe_url'];
        }
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        $user = HookManager::filter('user.subscribe.response', $user);
        if (isset($user['plan'])) {
            $user['plan'] = $this->normalizeSubscribePlan($request, $user['plan']);
        }
        return $this->success($user);
    }

    public function getOnlineDevices(Request $request)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }

        return $this->success(UserOnlineService::getUserDeviceIps((int) $user->id));
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            return $this->fail([400, __('Reset failed')]);
        }
        return $this->success(Helper::getSubscribeUrl($user->token));
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'remind_expire',
            'remind_traffic',
            'auto_renew_enable',
            'auto_renew_period',
        ]);

        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        try {
            $this->validateAutoRenewSettings($user, $updateData);
            $user->update($updateData);
        } catch (\Exception $e) {
            return $this->fail([400, $e->getMessage() ?: __('Save failed')]);
        }

        return $this->success(true);
    }

    protected function validateAutoRenewSettings(User $user, array $updateData): void
    {
        if (!array_key_exists('auto_renew_enable', $updateData) && !array_key_exists('auto_renew_period', $updateData)) {
            return;
        }

        $autoRenewEnable = array_key_exists('auto_renew_enable', $updateData)
            ? (bool) $updateData['auto_renew_enable']
            : (bool) $user->auto_renew_enable;

        if (!$autoRenewEnable) {
            return;
        }

        $autoRenewPeriod = array_key_exists('auto_renew_period', $updateData)
            ? $updateData['auto_renew_period']
            : $user->auto_renew_period;

        if (!$user->plan_id || !$user->expired_at) {
            throw new \RuntimeException(__('Current subscription does not support auto renewal'));
        }

        $plan = Plan::find($user->plan_id);
        if (!$plan || !$plan->renew) {
            throw new \RuntimeException(__('Current subscription does not support auto renewal'));
        }

        if (!User::isAutoRenewPeriod($autoRenewPeriod)) {
            throw new \RuntimeException(__('Incorrect auto renewal period'));
        }

        $periodKey = \App\Services\PlanService::getPeriodKey($autoRenewPeriod);
        if (in_array($periodKey, [Plan::PERIOD_ONETIME, Plan::PERIOD_RESET_TRAFFIC], true)) {
            throw new \RuntimeException(__('Current subscription does not support auto renewal'));
        }

        try {
            $amount = app(TenantPlanPricingService::class)->amountForUser($user, $plan, $periodKey);
        } catch (\Throwable) {
            $amount = 0;
        }

        if ($amount <= 0) {
            throw new \RuntimeException(__('This payment period cannot be renewed automatically'));
        }
    }

    protected function decorateSubscribePlan(Request $request, Plan $plan): Plan
    {
        return app(TenantPlanCatalogService::class)->decorateCurrentPlan($request, $plan, $request->user());
    }

    public function transfer(UserTransfer $request)
    {
        $transferAmount = (int) $request->input('transfer_amount');
        $requestUserId = (int) $request->user()->id;

        $result = DB::transaction(function () use ($requestUserId, $transferAmount) {
            $user = User::where('id', $requestUserId)->lockForUpdate()->first();
            if (!$user) {
                return [
                    'status' => 'missing',
                ];
            }

            if (app(AgentCommerceContextResolver::class)->resolveUser($user) !== null) {
                return [
                    'status' => 'agent_subordinate',
                ];
            }

            $commissionBalance = (int) $user->commission_balance;
            $balance = (int) $user->balance;

            if ($transferAmount > $commissionBalance) {
                return [
                    'status' => 'insufficient',
                    'data' => [
                        'commission_balance' => $commissionBalance,
                        'balance' => $balance,
                    ],
                ];
            }

            $user->commission_balance = $commissionBalance - $transferAmount;
            $user->balance = $balance + $transferAmount;
            if (!$user->save()) {
                return [
                    'status' => 'failed',
                ];
            }

            return [
                'status' => 'success',
                'data' => [
                    'transferred_amount' => $transferAmount,
                    'commission_balance' => (int) $user->commission_balance,
                    'balance' => (int) $user->balance,
                ],
            ];
        });

        if ($result['status'] === 'missing') {
            return $this->fail([400, __('The user does not exist')]);
        }
        if ($result['status'] === 'agent_subordinate') {
            return $this->fail([403, __('代理下级用户不参与普通邀请返利')]);
        }
        if ($result['status'] === 'insufficient') {
            return $this->fail([400, __('Insufficient commission balance')], $result['data']);
        }
        if ($result['status'] === 'failed') {
            return $this->fail([400, __('Transfer failed')]);
        }

        return $this->success($result['data']);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }

    protected function normalizeSubscribePlan(Request $request, mixed $plan): mixed
    {
        if ($plan instanceof Plan) {
            return array_merge($plan->toArray(), PlanResource::make($plan)->toArray($request));
        }

        if ($plan instanceof Arrayable) {
            $raw = $plan->toArray();
        } elseif (is_array($plan)) {
            $raw = $plan;
        } else {
            return $plan;
        }

        if (!array_key_exists('id', $raw) || !array_key_exists('name', $raw)) {
            return $plan;
        }

        if ($this->isNormalizedSubscribePlan($raw)) {
            return $raw;
        }

        return array_merge($raw, PlanResource::make($raw)->toArray($request));
    }

    protected function isNormalizedSubscribePlan(array $plan): bool
    {
        return array_key_exists('available_periods', $plan)
            || array_key_exists('recurring_periods', $plan)
            || array_key_exists('has_recurring_price', $plan)
            || array_key_exists('has_onetime_price', $plan);
    }
}

<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentUser;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CouponService
{
    public $coupon;
    public string $code;
    public $planId;
    public $userId;
    public $period;

    public function __construct($code)
    {
        $this->code = (string) $code;
        $this->coupon = Coupon::where('code', $this->code)
            ->lockForUpdate()
            ->orderBy('id')
            ->first();
    }

    public function use(Order $order): bool
    {
        $this->setPlanId($order->plan_id);
        $this->setUserId($order->user_id);
        $this->setPeriod($order->period);
        $this->check();
        switch ($this->coupon->type) {
            case 1:
                $order->discount_amount = max(0, (int) $this->coupon->value);
                break;
            case 2:
                $order->discount_amount = OrderService::percentageOfAmount((int) $order->total_amount, $this->coupon->value);
                break;
        }
        if ($order->discount_amount > $order->total_amount) {
            $order->discount_amount = $order->total_amount;
        }
        if ($this->coupon->limit_use !== NULL) {
            if ($this->coupon->limit_use <= 0)
                return false;
            $this->coupon->limit_use = $this->coupon->limit_use - 1;
            if (!$this->coupon->save()) {
                return false;
            }
        }
        return true;
    }

    public function getId()
    {
        return $this->coupon->id;
    }

    public function getCoupon()
    {
        return $this->coupon;
    }

    public function setPlanId($planId)
    {
        $this->planId = $planId;
    }

    public function setUserId($userId)
    {
        $this->userId = $userId;
        $this->resolveCouponForUser();
    }

    public function setPeriod($period)
    {
        if ($period) {
            $this->period = PlanService::getPeriodKey($period);
        }
    }

    public function checkLimitUseWithUser(): bool
    {
        $usedCount = Order::where('coupon_id', $this->coupon->id)
            ->where('user_id', $this->userId)
            ->whereNotIn('status', [0, 2])
            ->count();
        if ($usedCount >= $this->coupon->limit_use_with_user)
            return false;
        return true;
    }

    public function check()
    {
        if (!$this->coupon || !$this->coupon->show) {
            throw new ApiException(__('Invalid coupon'));
        }
        $this->checkScopeEligibility();
        if ($this->coupon->limit_use <= 0 && $this->coupon->limit_use !== NULL) {
            throw new ApiException(__('This coupon is no longer available'));
        }
        if (time() < $this->coupon->started_at) {
            throw new ApiException(__('This coupon has not yet started'));
        }
        if (time() > $this->coupon->ended_at) {
            throw new ApiException(__('This coupon has expired'));
        }
        if ($this->coupon->limit_plan_ids && $this->planId) {
            if (!in_array($this->planId, $this->coupon->limit_plan_ids)) {
                throw new ApiException(__('The coupon code cannot be used for this subscription'));
            }
        }
        if ($this->coupon->limit_period && $this->period) {
            if (!in_array($this->period, $this->coupon->limit_period)) {
                throw new ApiException(__('The coupon code cannot be used for this period'));
            }
        }
        if ($this->coupon->limit_use_with_user !== NULL && $this->userId) {
            if (!$this->checkLimitUseWithUser()) {
                throw new ApiException(__('The coupon can only be used :limit_use_with_user per person', [
                    'limit_use_with_user' => $this->coupon->limit_use_with_user
                ]));
            }
        }
    }

    private function resolveCouponForUser(): void
    {
        $coupons = Coupon::where('code', $this->code)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        if ($coupons->isEmpty()) {
            $this->coupon = null;
            return;
        }

        $user = $this->userId ? User::query()->find($this->userId) : null;
        $agentUserIds = $user
            ? AgentUser::query()
                ->where('sub_user_id', $user->id)
                ->pluck('agent_user_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $match = $coupons->first(function (Coupon $coupon) use ($agentUserIds): bool {
            $scope = Coupon::normalizeScopeType($coupon->scope_type ?? Coupon::SCOPE_GLOBAL);
            return $scope === Coupon::SCOPE_AGENT
                && in_array((int) $coupon->agent_user_id, $agentUserIds, true);
        });

        if (!$match && $user && $user->site_id) {
            $match = $coupons->first(function (Coupon $coupon) use ($user): bool {
                $scope = Coupon::normalizeScopeType($coupon->scope_type ?? Coupon::SCOPE_GLOBAL);
                return $scope === Coupon::SCOPE_SITE
                    && (int) $coupon->site_id === (int) $user->site_id;
            });
        }

        if (!$match) {
            $match = $coupons->first(function (Coupon $coupon): bool {
                return Coupon::normalizeScopeType($coupon->scope_type ?? Coupon::SCOPE_GLOBAL) === Coupon::SCOPE_GLOBAL;
            });
        }

        $this->coupon = $match ?: $coupons->first();
    }

    private function checkScopeEligibility(): void
    {
        $scope = Coupon::normalizeScopeType($this->coupon->scope_type ?? Coupon::SCOPE_GLOBAL);
        if ($scope === Coupon::SCOPE_GLOBAL) {
            return;
        }

        $user = $this->userId ? User::query()->find($this->userId) : null;
        if (!$user) {
            throw new ApiException(__('Invalid coupon'));
        }

        if ($scope === Coupon::SCOPE_SITE) {
            $siteId = (int) ($this->coupon->site_id ?? 0);
            if ($siteId <= 0 || (int) ($user->site_id ?? 0) !== $siteId) {
                throw new ApiException('This coupon is not available for the current site');
            }
            return;
        }

        $agentUserId = (int) ($this->coupon->agent_user_id ?? 0);
        $owned = $agentUserId > 0 && AgentUser::query()
            ->where('agent_user_id', $agentUserId)
            ->where('sub_user_id', $user->id)
            ->exists();

        if (!$owned) {
            throw new ApiException('This coupon is only available for the specified agent users');
        }
    }
}

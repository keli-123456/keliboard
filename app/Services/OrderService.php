<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\PlanService;

class OrderService
{
    const STR_TO_TIME = [
        Plan::PERIOD_MONTHLY => 1,
        Plan::PERIOD_QUARTERLY => 3,
        Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12,
        Plan::PERIOD_TWO_YEARLY => 24,
        Plan::PERIOD_THREE_YEARLY => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Create an order from a request.
     *
     * @param User $user
     * @param Plan $plan
     * @param string $period
     * @param string|null $couponCode
     * @return Order
     * @throws ApiException
     */
    public static function createFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode = null,
    ): Order {
        $userService = app(UserService::class);
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $period);
        HookManager::call('order.create.before', [$user, $plan, $period, $couponCode]);

        return DB::transaction(function () use ($user, $plan, $period, $couponCode, $userService) {
            $user = User::whereKey($user->id)
                ->lockForUpdate()
                ->first();
            if (!$user) {
                throw new ApiException(__('User does not exist'));
            }
            self::assertNoIncompleteOrder($user->id);

            $newPeriod = PlanService::getPeriodKey($period);

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $newPeriod,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => self::amountToCents($plan->prices[$newPeriod] ?? 0),
            ]);

            $orderService = new self($order);

            if ($couponCode) {
                $orderService->applyCoupon($couponCode);
            }

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user);
            $orderService->setInvite(user: $user);

            if ($user->balance && $order->total_amount > 0) {
                $orderService->handleUserBalance($user, $userService);
            }

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            HookManager::call('order.create.after', $order);
            // 兼容旧钩子
            HookManager::call('order.after_create', $order);

            return $order;
        });
    }

    public static function createRechargeOrder(User $user, int $amount, int $bonusAmount = 0): Order
    {
        return DB::transaction(function () use ($user, $amount, $bonusAmount) {
            $user = User::whereKey($user->id)
                ->lockForUpdate()
                ->first();
            if (!$user) {
                throw new ApiException(__('User does not exist'));
            }
            self::assertNoIncompleteOrder($user->id);

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => 0,
                'period' => 'recharge',
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => $amount,
                'bonus_amount' => max(0, $bonusAmount),
                'type' => Order::TYPE_RECHARGE,
            ]);

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            return $order;
        });
    }

    public static function assertNoIncompleteOrder(int $userId): void
    {
        $order = Order::whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->where('user_id', $userId)
            ->first();
        if ($order) {
            throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
        }
    }

    public static function amountToCents(int|float|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return max(0, (int) round(((float) $amount) * 100));
    }

    public static function percentageOfAmount(int $amount, int|float|string|null $percentage): int
    {
        if ($amount <= 0 || $percentage === null || $percentage === '') {
            return 0;
        }

        return max(0, (int) round($amount * (((float) $percentage) / 100)));
    }

    public function open(): void
    {
        $eventId = 0;
        $opened = false;

        DB::transaction(function () use (&$eventId, &$opened) {
            $order = Order::whereKey($this->order->id)
                ->lockForUpdate()
                ->first();
            if (!$order) {
                throw new \RuntimeException('订单不存在');
            }
            if ((int) $order->status === Order::STATUS_COMPLETED) {
                $this->order = $order;
                return;
            }
            if ((int) $order->status !== Order::STATUS_PROCESSING) {
                $this->order = $order;
                return;
            }

            $this->order = $order;
            $this->user = User::whereKey($order->user_id)
                ->lockForUpdate()
                ->first();
            if (!$this->user) {
                throw new \RuntimeException('用户不存在');
            }

            $isRechargeOrder = $this->isRechargeOrder($order);
            $plan = $isRechargeOrder ? null : Plan::find($order->plan_id);
            if (!$isRechargeOrder && !$plan) {
                throw new \RuntimeException('套餐不存在');
            }

            HookManager::call('order.open.before', $order);

            if ($isRechargeOrder) {
                $this->user->balance += (int) $order->total_amount + (int) $order->bonus_amount;
            } else {
                match ((string) $order->period) {
                    Plan::PERIOD_ONETIME => $this->buyByOneTime($plan),
                    Plan::PERIOD_RESET_TRAFFIC => app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER),
                    default => $this->buyByPeriod($order, $plan),
                };

                $this->setSpeedLimit($plan->speed_limit);
                $this->setDeviceLimit($plan->device_limit);
            }

            if (!$this->user->save()) {
                throw new \RuntimeException('用户信息保存失败');
            }

            $order->status = Order::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \RuntimeException('订单信息保存失败');
            }

            $eventId = match ((int) $order->type) {
                Order::TYPE_NEW_PURCHASE => admin_setting('new_order_event_id', 0),
                Order::TYPE_RENEWAL => admin_setting('renew_order_event_id', 0),
                Order::TYPE_UPGRADE => admin_setting('change_order_event_id', 0),
                Order::TYPE_DISCOUNT_UPGRADE => admin_setting('change_order_event_id', 0),
                default => 0,
            };
            $opened = true;
        });

        if (!$opened) {
            return;
        }

        if ($eventId) {
            $this->openEvent($eventId);
        }

        HookManager::call('order.open.after', $this->order);
    }

    private function isRechargeOrder(Order $order): bool
    {
        return (int) $order->plan_id === 0 || (string) $order->period === 'recharge';
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === Plan::PERIOD_RESET_TRAFFIC) {
            $order->type = Order::TYPE_RESET_TRAFFIC;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int) admin_setting('plan_change_enable', 1))
                throw new ApiException('目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = Order::TYPE_UPGRADE;
        } else if (($user->expired_at === null || $user->expired_at > time()) && $order->plan_id == $user->plan_id) { // 用户订阅未过期或按流量订阅 且购买订阅与当前订阅相同 === 续费
            $order->type = Order::TYPE_RENEWAL;
        } else { // 新购
            $order->type = Order::TYPE_NEW_PURCHASE;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        $baseAmount = max(0, (int) $order->total_amount);
        $discountAmount = max(0, (int) $order->discount_amount);

        if ($user->discount) {
            $discountAmount += self::percentageOfAmount($baseAmount, $user->discount);
        }

        $order->discount_amount = min($baseAmount, $discountAmount);
        $order->total_amount = max(0, $baseAmount - $order->discount_amount);
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0))
            return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter)
            return;
        $commissionType = (int) $inviter->commission_type;
        if ($commissionType === User::COMMISSION_TYPE_SYSTEM) {
            $commissionType = (bool) admin_setting('commission_first_time_enable', true) ? User::COMMISSION_TYPE_ONETIME : User::COMMISSION_TYPE_PERIOD;
        }
        $isCommission = false;
        switch ($commissionType) {
            case User::COMMISSION_TYPE_PERIOD:
                $isCommission = true;
                break;
            case User::COMMISSION_TYPE_ONETIME:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission)
            return;
        if ($inviter->commission_rate) {
            $order->commission_balance = self::percentageOfAmount((int) $order->total_amount, $inviter->commission_rate);
        } else {
            $order->commission_balance = self::percentageOfAmount((int) $order->total_amount, admin_setting('invite_commission', 10));
        }
    }

    private function haveValidOrder(User $user): Order|null
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->first();
    }

    public function paid(string $callbackNo)
    {
        try {
            $tradeNo = DB::transaction(function () use ($callbackNo) {
                $order = Order::whereKey($this->order->id)
                    ->lockForUpdate()
                    ->first();
                if (!$order) {
                    throw new \RuntimeException('订单不存在');
                }
                $this->order = $order;
                if ((int) $order->status !== Order::STATUS_PENDING) {
                    return null;
                }

                app(AgentCommerceService::class)->captureForPaidOrder($order);

                $order->status = Order::STATUS_PROCESSING;
                $order->paid_at = time();
                $order->callback_no = $callbackNo;
                if (!$order->save()) {
                    throw new \RuntimeException('订单信息保存失败');
                }

                return $order->trade_no;
            });

            if ($tradeNo) {
                OrderHandleJob::dispatchSync($tradeNo);
            }
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
        return true;
    }

    public function cancel(): bool
    {
        try {
            $cancelled = DB::transaction(function () {
                $order = Order::whereKey($this->order->id)
                    ->lockForUpdate()
                    ->first();
                if (!$order) {
                    throw new \Exception('Order not found.');
                }
                $this->order = $order;
                if ((int) $order->status !== Order::STATUS_PENDING) {
                    return false;
                }

                HookManager::call('order.cancel.before', $order);

                $order->status = Order::STATUS_CANCELLED;
                if (!$order->save()) {
                    throw new \Exception('Failed to save order status.');
                }
                if ($order->balance_amount) {
                    $userService = new UserService();
                    if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                        throw new \Exception('Failed to add balance.');
                    }
                }

                return true;
            });
            if (!$cancelled) {
                return false;
            }
            HookManager::call('order.cancel.after', $this->order);
            return true;
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function setDeviceLimit($deviceLimit)
    {
        $this->user->device_limit = $deviceLimit;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if (in_array((int) $order->type, [Order::TYPE_UPGRADE, Order::TYPE_DISCOUNT_UPGRADE], true)) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        // 从一次性转换到循环或者新购的时候，重置流量
        if ($this->user->expired_at === NULL || $order->type === Order::TYPE_NEW_PURCHASE)
            app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Plan $plan)
    {
        app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    /**
     * 计算套餐到期时间
     * @param string $periodKey
     * @param int $timestamp
     * @return int
     * @throws ApiException
     */
    private function getTime(string $periodKey, ?int $timestamp = null): int
    {
        $timestamp = $timestamp < time() ? time() : $timestamp;
        $periodKey = PlanService::getPeriodKey($periodKey);

        if (isset(self::STR_TO_TIME[$periodKey])) {
            $months = self::STR_TO_TIME[$periodKey];
            return Carbon::createFromTimestamp($timestamp)->addMonths($months)->timestamp;
        }

        throw new ApiException('无效的套餐周期');
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
                break;
        }
    }

    protected function applyCoupon(string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($this->order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $this->order->coupon_id = $couponService->getId();
    }

    /**
     * Summary of handleUserBalance
     * @param User $user
     * @param UserService $userService
     * @return void
     */
    protected function handleUserBalance(User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $this->order->total_amount;

        if ($remainingBalance >= 0) {
            if (!$userService->addBalance($this->order->user_id, -$this->order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $this->order->total_amount;
            $this->order->total_amount = 0;
        } else {
            if (!$userService->addBalance($this->order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $user->balance;
            $this->order->total_amount = $this->order->total_amount - $user->balance;
        }
    }

}

<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderUpgradeConfirm;
use App\Http\Requests\User\OrderUpgradePreview;
use App\Http\Requests\User\OrderSave;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCommerceContextResolver;
use App\Services\CouponService;
use App\Services\AgentCommerceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\RechargeBonusService;
use App\Services\SiteCommerceService;
use App\Services\SiteDataScopeService;
use App\Services\OrderUpgradeService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'status' => 'nullable|integer|in:0,1,2,3',
        ]);
        $query = Order::with('plan')
            ->where('user_id', $request->user()->id)
            ->when($request->input('status') !== null, function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            });
        $this->applyUserSiteScope($request, $query);
        $orders = $query
            ->orderBy('created_at', 'DESC')
            ->get();

        return $this->success(OrderResource::collection($orders));
    }

    public function detail(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);
        $query = Order::with(['payment', 'plan'])
            ->where('user_id', $request->user()->id)
            ->where('trade_no', $request->input('trade_no'));
        $this->applyUserSiteScope($request, $query);
        $order = $query
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        $order['try_out_plan_id'] = (int) admin_setting('try_out_plan_id');
        if (!$order->plan && (int) $order->plan_id !== 0) {
            return $this->fail([400, __('Subscription plan does not exist')]);
        }
        return $this->success(OrderResource::make($order));
    }

    public function save(OrderSave $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:App\Models\Plan,id',
            'period' => 'required|string'
        ]);

        $user = User::findOrFail($request->user()->id);
        $userService = app(UserService::class);

        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $plan = Plan::findOrFail($request->input('plan_id'));
        $planService = new PlanService($plan);

        $hasTenantContext = (bool) app(AgentCommerceContextResolver::class)->resolveRequest($request, $user)
            || (bool) app(SiteCommerceService::class)->contextForRequest($request, $user);
        $planService->validatePurchase($user, $request->input('period'), $hasTenantContext);

        $agentOrder = app(AgentCommerceService::class)->createOrderFromRequest(
            $user,
            $plan,
            $request->input('period'),
            $request->input('coupon_code'),
            $request
        );
        if ($agentOrder) {
            return $this->success($agentOrder->trade_no);
        }

        $order = app(SiteCommerceService::class)->createOrderFromRequest(
            $user,
            $plan,
            $request->input('period'),
            $request->input('coupon_code'),
            $request
        );

        return $this->success($order->trade_no);
    }

    public function recharge(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1|max:100000'
        ]);

        $user = User::findOrFail($request->user()->id);
        $userService = app(UserService::class);

        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $amount = (int) round(((float) $request->input('amount')) * 100);
        if ($amount < 100) {
            throw new ApiException(__('Recharge amount must be at least 1'));
        }

        $bonusAmount = app(RechargeBonusService::class)->calculateBonus($amount);
        $agentOrder = app(AgentCommerceService::class)->createRechargeOrderFromRequest($user, $amount, $bonusAmount, $request);
        if ($agentOrder) {
            return $this->success($agentOrder->trade_no);
        }

        $order = app(SiteCommerceService::class)->createRechargeOrderFromRequest($user, $amount, $bonusAmount, $request);
        return $this->success($order->trade_no);
    }

    public function previewUpgrade(OrderUpgradePreview $request, OrderUpgradeService $orderUpgradeService)
    {
        $user = User::findOrFail($request->user()->id);
        $targetPlan = Plan::findOrFail((int) $request->input('target_plan_id'));

        return $this->success(
            $orderUpgradeService->previewUpgrade(
                $user,
                $targetPlan,
                (string) $request->input('period'),
                $request
            )
        );
    }

    public function confirmUpgrade(OrderUpgradeConfirm $request, OrderUpgradeService $orderUpgradeService)
    {
        $user = User::findOrFail($request->user()->id);
        $order = $orderUpgradeService->confirmUpgrade($user, (string) $request->input('quote_token'));

        return $this->success([
            'trade_no' => $order->trade_no,
            'order_type' => 'discount_upgrade',
            'payable_amount' => (int) $order->total_amount,
        ]);
    }

    protected function applyCoupon(Order $order, string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $order->coupon_id = $couponService->getId();
    }

    protected function handleUserBalance(Order $order, User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $order->total_amount;

        if ($remainingBalance > 0) {
            if (!$userService->addBalance($order->user_id, -$order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $order->total_amount;
            $order->total_amount = 0;
        } else {
            if (!$userService->addBalance($order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $user->balance;
            $order->total_amount = $order->total_amount - $user->balance;
        }
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $query = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->where('status', 0);
        $this->applyUserSiteScope($request, $query);
        $order = $query
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        // free process
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no))
                return $this->fail([400, '支付失败']);
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || !$payment->enable) {
            return $this->fail([400, __('Payment method is not available')]);
        }
        $agentCommerce = app(AgentCommerceService::class);
        if ((int) $order->plan_id === 0 && $payment->payment === 'balance') {
            return $this->fail([400, __('Balance payment is not available for recharge orders')]);
        }
        $handlingAmount = null;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $handlingAmount = (int) round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        try {
            app(SiteCommerceService::class)->assertPaymentAvailableForOrder($order, $payment);
            $order = $agentCommerce->assignPaymentForCheckout($order, $payment, $handlingAmount);
            $returnBaseUrl = $agentCommerce->paymentReturnBaseUrlForOrder($order, $payment, $request);
        } catch (ApiException $exception) {
            return $this->fail([400, $exception->getMessage()]);
        }
        try {
            $paymentService = new PaymentService($payment->payment, $payment->id);
            $result = $paymentService->pay([
                'trade_no' => $tradeNo,
                'total_amount' => isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
                'user_id' => $order->user_id,
                'stripe_token' => $request->input('token'),
                'return_base_url' => $returnBaseUrl
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Payment checkout request failed', [
                'trade_no' => $tradeNo,
                'payment_id' => $payment->id,
                'payment' => $payment->payment,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            return $this->fail([400, $this->paymentFailureMessage($exception)]);
        }
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    private function paymentFailureMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            return '支付请求失败，请稍后重试或联系管理员';
        }

        return mb_substr($message, 0, 500);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $query = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id);
        $this->applyUserSiteScope($request, $query);
        $order = $query
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        return $this->success($order->status);
    }

    public function getPaymentMethod(Request $request)
    {
        $agentCommerce = app(AgentCommerceService::class);
        if ($agentCommerce->agentUserIdForPaymentMethods($request)) {
            return $this->success($agentCommerce->availablePaymentMethodsForRequest($request));
        }

        return $this->success(app(SiteCommerceService::class)->availablePaymentMethodsForRequest($request));
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $query = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user()->id);
        $this->applyUserSiteScope($request, $query);
        $order = $query
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        if ($order->status !== 0) {
            return $this->fail([400, __('You can only cancel pending orders')]);
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, __('Cancel failed')]);
        }
        return $this->success(true);
    }

    private function applyUserSiteScope(Request $request, $query): void
    {
        $siteScope = app(SiteDataScopeService::class);
        $siteScope->applyNullableSiteScope(
            $query,
            $siteScope->siteIdForRequest($request, $request->user()),
            'v2_order'
        );
    }
}

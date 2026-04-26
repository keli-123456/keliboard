<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            if (is_string($verify)) {
                return $verify;
            }
            if (!is_array($verify)) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            HookManager::call('payment.notify.verified', $verify);
            if (!$this->handle($verify, $paymentService)) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle(array $verify, PaymentService $paymentService): bool
    {
        if (empty($verify['trade_no']) || empty($verify['callback_no'])) {
            Log::warning('Payment notify missing required fields', ['verify' => $verify]);
            return false;
        }

        $tradeNo = (string) $verify['trade_no'];
        $callbackNo = (string) $verify['callback_no'];
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            Log::warning('Payment notify order not found', ['trade_no' => $tradeNo]);
            return false;
        }
        if ($order->status !== Order::STATUS_PENDING) {
            return true;
        }

        if (!$this->verifyPaymentMethod($order, $paymentService)) {
            return false;
        }

        if (!$this->verifyPaidAmount($order, $verify)) {
            return false;
        }

        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        $order->refresh();
        HookManager::call('payment.notify.success', $order);
        return true;
    }

    private function verifyPaymentMethod(Order $order, PaymentService $paymentService): bool
    {
        $paymentId = $paymentService->getPaymentId();
        if (!$paymentId || (int) $order->payment_id === $paymentId) {
            return true;
        }

        Log::warning('Payment notify payment method mismatch', [
            'trade_no' => $order->trade_no,
            'order_payment_id' => $order->payment_id,
            'callback_payment_id' => $paymentId,
        ]);
        return false;
    }

    private function verifyPaidAmount(Order $order, array $verify): bool
    {
        if (!array_key_exists('paid_amount', $verify)) {
            Log::warning('Payment notify missing paid amount', ['trade_no' => $order->trade_no]);
            return false;
        }

        $paidAmount = (int) $verify['paid_amount'];
        $expectedAmount = (int) $order->total_amount + (int) $order->handling_amount;
        if ($paidAmount === $expectedAmount) {
            return true;
        }

        Log::warning('Payment notify amount mismatch', [
            'trade_no' => $order->trade_no,
            'paid_amount' => $paidAmount,
            'expected_amount' => $expectedAmount,
        ]);
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\Order;

class AgentOrderStatusResolver
{
    public const HOLD_STATUS_MISSING = 'missing';
    public const CAPTURE_STATUS_NOT_CAPTURED = 'not_captured';

    /**
     * @return array{hold_status: string, capture_status: string, margin_amount: int, abnormal_flags: array<int, string>}
     */
    public function resolve(AgentOrderContext $context): array
    {
        $hold = $context->hold;
        $payment = $context->payment;
        $order = $context->order;
        $flags = [];

        $holdStatus = $hold?->status ?? self::HOLD_STATUS_MISSING;
        if ($hold === null) {
            $flags[] = 'hold_missing';
        } else {
            if (
                $hold->status === AgentBalanceHold::STATUS_PENDING
                && $hold->expires_at !== null
                && (int) $hold->expires_at < time()
            ) {
                $holdStatus = AgentBalanceHold::STATUS_EXPIRED;
                $flags[] = 'hold_expired';
            }

            if ((int) $hold->amount !== (int) $context->cost_amount) {
                $flags[] = 'hold_amount_mismatch';
            }
        }

        if ($payment !== null && $payment->enable === false) {
            $flags[] = 'payment_disabled';
        }

        $captureStatus = $this->captureStatus($context, $hold);
        if ($order !== null && (int) $order->status === Order::STATUS_COMPLETED && $captureStatus !== AgentBalanceHold::STATUS_CAPTURED) {
            $flags[] = 'ledger_missing';
        }

        return [
            'hold_status' => $holdStatus,
            'capture_status' => $captureStatus,
            'margin_amount' => (int) $context->sale_amount - (int) $context->cost_amount,
            'abnormal_flags' => array_values(array_unique($flags)),
        ];
    }

    private function captureStatus(AgentOrderContext $context, ?AgentBalanceHold $hold): string
    {
        if ($hold === null) {
            return self::CAPTURE_STATUS_NOT_CAPTURED;
        }

        if ($hold->status === AgentBalanceHold::STATUS_FAILED) {
            return AgentBalanceHold::STATUS_FAILED;
        }

        if ($hold->status === AgentBalanceHold::STATUS_RELEASED) {
            return AgentBalanceHold::STATUS_RELEASED;
        }

        if (
            $context->status === AgentOrderContext::STATUS_PAID
            && $hold->status === AgentBalanceHold::STATUS_CAPTURED
        ) {
            return AgentBalanceHold::STATUS_CAPTURED;
        }

        return self::CAPTURE_STATUS_NOT_CAPTURED;
    }
}

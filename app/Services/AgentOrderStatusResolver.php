<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class AgentOrderStatusResolver
{
    public const HOLD_STATUS_MISSING = 'missing';
    public const HOLD_STATUS_NOT_REQUIRED = 'not_required';
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
        $requiresHold = (int) $context->cost_amount > 0 || $context->hold_id !== null;

        $holdStatus = $hold?->status ?? self::HOLD_STATUS_MISSING;
        if ($hold === null) {
            if ($requiresHold) {
                $flags[] = 'hold_missing';
            } else {
                $holdStatus = self::HOLD_STATUS_NOT_REQUIRED;
            }
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

            if (
                $order !== null
                && (int) $order->status === Order::STATUS_CANCELLED
                && $hold->status === AgentBalanceHold::STATUS_PENDING
            ) {
                $flags[] = 'cancelled_with_pending_hold';
            }
        }

        if (
            $payment !== null
            && ($payment->enable === false || $payment->trashed())
            && $order !== null
            && in_array((int) $order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], true)
        ) {
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

    public function filterAbnormal(Builder $query, bool $abnormal = true): Builder
    {
        $table = (new AgentOrderContext())->getTable();
        $captured = AgentOrderContext::query()->select('id')->where('status', AgentOrderContext::STATUS_PAID)
            ->where(function (Builder $q): void {
                $q->whereHas('hold', fn (Builder $h) => $h->where('status', AgentBalanceHold::STATUS_CAPTURED))
                    ->orWhere(fn (Builder $free) => $free->whereNull('hold_id')->where('cost_amount', '<=', 0));
            });
        $flags = AgentOrderContext::query()->select('id')->where(function (Builder $q) use ($table, $captured): void {
            $q->where(fn (Builder $missing) => $missing->whereDoesntHave('hold')
                ->where(fn (Builder $required) => $required->where('cost_amount', '>', 0)->orWhereNotNull('hold_id')))
                ->orWhereHas('hold', fn (Builder $h) => $h->where('status', AgentBalanceHold::STATUS_PENDING)
                    ->whereNotNull('expires_at')->where('expires_at', '<', time()))
                ->orWhereHas('hold', fn (Builder $h) => $h->whereColumn('amount', '!=', $table . '.cost_amount'))
                ->orWhere(fn (Builder $cancelled) => $cancelled
                    ->whereHas('order', fn (Builder $o) => $o->where('status', Order::STATUS_CANCELLED))
                    ->whereHas('hold', fn (Builder $h) => $h->where('status', AgentBalanceHold::STATUS_PENDING)))
                ->orWhere(fn (Builder $disabled) => $disabled
                    ->whereHas('order', fn (Builder $o) => $o->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING]))
                    ->whereHas('payment', fn (Builder $p) => $p->where(fn (Builder $inactive) =>
                        $inactive->where('enable', false)->orWhereNotNull('deleted_at'))))
                ->orWhere(fn (Builder $ledger) => $ledger
                    ->whereHas('order', fn (Builder $o) => $o->where('status', Order::STATUS_COMPLETED))
                    ->whereNotIn('id', $captured));
        });
        return $abnormal ? $query->whereIn($table . '.id', $flags) : $query->whereNotIn($table . '.id', $flags);
    }

    private function captureStatus(AgentOrderContext $context, ?AgentBalanceHold $hold): string
    {
        if ($hold === null) {
            if ((int) $context->cost_amount <= 0 && $context->hold_id === null && $context->status === AgentOrderContext::STATUS_PAID) {
                return AgentBalanceHold::STATUS_CAPTURED;
            }

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

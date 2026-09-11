<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class PaymentCollectionPolicyService
{
    private const PUBLIC_FIELDS = [
        'id', 'name', 'payment', 'icon', 'handling_fee_fixed', 'handling_fee_percent',
        'owner_type', 'owner_id', 'owner_domain_id',
    ];

    public function validate(?array $policy): array
    {
        $policy = array_replace(['daily_target' => 0, 'reached_action' => 'demote', 'windows' => []], $policy ?? []);
        $validated = Validator::make(['collection_policy' => $policy], [
            'collection_policy' => 'array:daily_target,reached_action,windows',
            'collection_policy.daily_target' => 'required|integer|min:0|max:1000000000000',
            'collection_policy.reached_action' => 'required|in:demote,pause',
            'collection_policy.windows' => 'present|array|list|max:12',
            'collection_policy.windows.*' => 'array:start,end',
            'collection_policy.windows.*.start' => ['required', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'collection_policy.windows.*.end' => ['required', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', 'different:collection_policy.windows.*.start'],
        ])->validate()['collection_policy'];
        // Accept legacy clients' pause value, but persist only a sorting preference.
        $validated['reached_action'] = 'demote';
        return $validated;
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone', 'Asia/Shanghai'));
    }

    public function dailyTotals(Collection $payments, CarbonImmutable $now): array
    {
        $ids = $payments->where('payment', '!=', 'balance')->pluck('id')->all();
        if (!$ids) return [];

        // Gross confirmed CNY receipts, independent of later fulfillment/refund status.
        return Order::query()->useWritePdo()->whereIn('payment_id', $ids)
            ->where('paid_at', '>=', $now->startOfDay()->timestamp)
            ->where('paid_at', '<', $now->startOfDay()->addDay()->timestamp)
            ->where('total_amount', '>', 0)
            ->selectRaw('payment_id, SUM(total_amount + COALESCE(handling_amount, 0)) AS collected')
            ->groupBy('payment_id')->pluck('collected', 'payment_id')
            ->map(fn ($amount): int => (int) $amount)->all();
    }

    public function state(Payment $payment, int $collected, ?CarbonImmutable $now = null): array
    {
        $now ??= $this->now();
        $policy = $payment->collection_policy ?? [];
        $target = $payment->payment === 'balance' ? 0 : (int) ($policy['daily_target'] ?? 0);
        $windows = $policy['windows'] ?? [];
        $reached = $target > 0 && $collected >= $target;
        $open = $this->isOpen($now, $windows);
        $status = !$payment->enable ? 'disabled' : (!$open ? 'outside_window' : ($reached ? 'demoted' : 'available'));
        $next = null;
        if ($payment->enable && ($reached || !$open)) {
            $next = $this->nextOpen($reached ? $now->startOfDay()->addDay() : $now, $windows)->timestamp;
        }
        return [
            'available' => (bool) $payment->enable,
            'status' => $status,
            'scheduled' => count($windows) > 0,
            'in_window' => $open,
            'reached' => $reached,
            'today_amount' => $collected,
            'remaining_amount' => $target > 0 ? max(0, $target - $collected) : null,
            'next_available_at' => null,
            'next_priority_at' => $next,
            'day_ends_at' => $now->startOfDay()->addDay()->timestamp,
            'timezone' => $now->timezoneName,
        ];
    }

    public function checkoutState(Payment $payment): array
    {
        $now = $this->now();
        $needsTotal = (int) ($payment->collection_policy['daily_target'] ?? 0) > 0;
        $totals = $needsTotal ? $this->dailyTotals(collect([$payment]), $now) : [];
        return $this->state($payment, $totals[$payment->id] ?? 0, $now);
    }

    public function publicMethods(Collection $payments): Collection
    {
        $now = $this->now();
        $limited = $payments->filter(fn (Payment $payment): bool => (int) ($payment->collection_policy['daily_target'] ?? 0) > 0);
        $totals = $this->dailyTotals($limited, $now);
        $priority = static fn (array $state): int => ($state['reached'] || !$state['in_window']) ? 2 : ($state['scheduled'] ? 0 : 1);
        return $payments->map(function (Payment $payment) use ($now, $totals): array {
            return ['payment' => $payment, 'state' => $this->state($payment, $totals[$payment->id] ?? 0, $now)];
        })->filter(fn (array $row): bool => $row['state']['available'])
            ->sort(function (array $a, array $b) use ($priority): int {
                return [$priority($a['state']), $a['payment']->sort ?? -1, $a['payment']->id]
                    <=> [$priority($b['state']), $b['payment']->sort ?? -1, $b['payment']->id];
            })->map(fn (array $row): Payment => (clone $row['payment'])->setVisible(self::PUBLIC_FIELDS))->values();
    }

    private function isOpen(CarbonImmutable $now, array $windows): bool
    {
        if (!$windows) return true;
        $time = $now->format('H:i');
        foreach ($windows as $window) {
            $start = $window['start'];
            $end = $window['end'];
            if ($start < $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end)) return true;
        }
        return false;
    }

    private function nextOpen(CarbonImmutable $from, array $windows): CarbonImmutable
    {
        if ($this->isOpen($from, $windows)) return $from;
        $candidates = [];
        foreach ($windows as $window) {
            $start = $from->setTimeFromTimeString($window['start']);
            $candidates[] = $start > $from ? $start : $start->addDay();
        }
        usort($candidates, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->timestamp <=> $b->timestamp);
        return $candidates[0];
    }
}

<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AgentPrepaidService
{
    private const FUNDS = 'v2_agent_prepaid_fund';
    private const ALLOCATIONS = 'v2_agent_prepaid_allocation';

    public function balance(int $userId, int $agentId): int
    {
        if (!DB::getSchemaBuilder()->hasTable(self::FUNDS)) {
            return 0;
        }
        return (int) DB::table(self::FUNDS)->where('user_id', $userId)->where('agent_user_id', $agentId)
            ->whereNull('reversed_at')->sum('remaining_amount');
    }

    public function forUser(?User $user): ?array
    {
        return $user ? $this->view($user, app(AgentCommerceContextResolver::class)->resolveUser($user)) : null;
    }

    public function forRequest(\Illuminate\Http\Request $request): ?array
    {
        $user = $request->user();
        return $user ? $this->view($user, app(AgentCommerceContextResolver::class)->resolveRequest($request, $user)) : null;
    }

    private function view(User $user, ?array $context): ?array
    {
        if (!$context) {
            return null;
        }
        $agentId = (int) $context['agent_user_id'];
        $settings = app(AgentCollectionService::class)->settings($agentId);
        $balance = $this->balance((int) $user->id, $agentId);
        if ($settings->mode !== 'platform' && $balance === 0) {
            return null;
        }
        return ['agent_user_id' => $agentId, 'balance' => $balance, 'active' => $settings->mode === 'platform'];
    }

    // Callers hold the order and buyer locks; allocations retain the exact original funding and fee.
    public function deposit(Order $order): bool
    {
        $context = $this->platformContext($order);
        if (!$context || (int) $order->plan_id !== 0) {
            return false;
        }
        if ((int) $order->bonus_amount !== 0 || (int) $order->total_amount <= 0
            || $order->refund_disposed_at || (int) $order->refund_amount > 0
            || !$order->paid_at || $context->status !== AgentOrderContext::STATUS_PAID) {
            throw new ApiException('代收充值来源无效');
        }
        $this->assertFunding($order, $context);
        if (DB::table(self::FUNDS)->where('order_id', $order->id)->exists()) {
            return true;
        }
        $fee = app(AgentCollectionService::class)->fee((int) $order->total_amount,
            app(AgentCollectionService::class)->forOrder($context));
        if ($fee >= (int) $order->total_amount) {
            throw new ApiException('充值金额不足以覆盖代收服务费');
        }
        DB::table(self::FUNDS)->insert([
            'agent_user_id' => $context->agent_user_id, 'user_id' => $order->user_id, 'order_id' => $order->id,
            'amount' => $order->total_amount, 'fee_amount' => $fee,
            'remaining_amount' => $order->total_amount, 'remaining_fee' => $fee,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        return true;
    }

    public function reserve(Order $order, int $agentId, int $amount): int
    {
        if ($amount === 0) {
            return 0;
        }
        if ($amount < 0) {
            throw new ApiException('代收余额金额无效');
        }
        User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
        $existing = DB::table(self::ALLOCATIONS)->where('order_id', $order->id)->get();
        if ($existing->isNotEmpty()) {
            if ((int) $existing->sum('amount') !== $amount || $existing->contains(fn ($row) => $row->status !== 'reserved')) {
                throw new ApiException('代收余额预占状态不匹配');
            }
            return (int) $existing->sum('fee_amount');
        }
        $funds = DB::table(self::FUNDS)->where('user_id', $order->user_id)->where('agent_user_id', $agentId)
            ->whereNull('reversed_at')->where('remaining_amount', '>', 0)->orderBy('id')->lockForUpdate()->get();
        $remaining = $amount;
        $totalFee = 0;
        foreach ($funds as $fund) {
            if ($remaining === 0) {
                break;
            }
            $take = min($remaining, (int) $fund->remaining_amount);
            $fee = intdiv((int) $fund->remaining_fee * $take + (int) $fund->remaining_amount - 1, (int) $fund->remaining_amount);
            DB::table(self::FUNDS)->where('id', $fund->id)->update([
                'remaining_amount' => (int) $fund->remaining_amount - $take,
                'remaining_fee' => (int) $fund->remaining_fee - $fee, 'updated_at' => time(),
            ]);
            DB::table(self::ALLOCATIONS)->insert([
                'order_id' => $order->id, 'fund_id' => $fund->id, 'amount' => $take,
                'fee_amount' => $fee, 'status' => 'reserved', 'created_at' => time(), 'updated_at' => time(),
            ]);
            $totalFee += $fee;
            $remaining -= $take;
        }
        if ($remaining !== 0) {
            throw new ApiException('代收余额不足，请先充值');
        }
        return $totalFee;
    }

    public function assertFunding(Order $order, AgentOrderContext $context, bool $checkUpgrade = true): int
    {
        if ($checkUpgrade) {
            $this->assertUpgradeSources($order, (int) $context->agent_user_id);
        }
        $external = (int) $order->total_amount;
        $prepaid = (int) $order->balance_amount;
        if ((int) $order->handling_amount < 0) {
            throw new ApiException('代收订单手续费不能为负数');
        }
        if ($external < 0 || $prepaid < 0 || (int) $context->sale_amount <= 0 || $external + $prepaid !== (int) $context->sale_amount) {
            throw new ApiException('平台代收订单支付来源不匹配');
        }
        if ($external > 0 && (!$order->payment_id || (int) $order->payment_id !== (int) $context->payment_id
            || ($context->payment_snapshot['owner_type'] ?? null) !== 'platform')) {
            throw new ApiException('平台代收订单支付来源不匹配');
        }
        $fee = $external > 0 ? app(AgentCollectionService::class)->fee($external,
            app(AgentCollectionService::class)->forOrder($context)) : 0;
        if ($prepaid === 0) {
            return $fee;
        }
        if (($context->pricing_snapshot['prepaid_amount'] ?? 0) !== $prepaid
            || !DB::getSchemaBuilder()->hasTable(self::ALLOCATIONS)) {
            throw new ApiException('历史或赠送余额不能计入代收收益');
        }
        $rows = DB::table(self::ALLOCATIONS . ' as a')->join(self::FUNDS . ' as f', 'a.fund_id', '=', 'f.id')
            ->join('v2_order as r', 'f.order_id', '=', 'r.id')->where('a.order_id', $order->id)
            ->get(['a.amount', 'a.fee_amount', 'a.status', 'f.agent_user_id', 'f.user_id', 'f.reversed_at',
                'r.status as recharge_status', 'r.refund_disposed_at', 'r.refund_amount']);
        if ((int) $rows->sum('amount') !== $prepaid || $rows->contains(fn ($row) =>
            !in_array($row->status, ['reserved', 'captured'], true) || $row->reversed_at
            || (int) $row->user_id !== (int) $order->user_id || (int) $row->agent_user_id !== (int) $context->agent_user_id
            || (int) $row->recharge_status !== Order::STATUS_COMPLETED || $row->refund_disposed_at || (int) $row->refund_amount > 0)) {
            throw new ApiException('代收余额来源已失效，请联系站点客服');
        }
        return $fee + (int) $rows->sum('fee_amount');
    }

    public function assertUpgradeSources(Order $order, int $agentId): void
    {
        $queue = array_map('intval', $order->upgrade_source_order_ids ?? []);
        $seen = [(int) $order->id => true];
        while ($queue) {
            $id = array_shift($queue);
            if (isset($seen[$id]) || count($seen) > 100) {
                throw new ApiException('升级来源链无效');
            }
            $seen[$id] = true;
            $source = Order::whereKey($id)->where('user_id', $order->user_id)->first();
            $context = $source ? app(AgentCommerceService::class)->contextForOrder($source) : null;
            if (!$source || !$context || (int) $context->agent_user_id !== $agentId
                || $context->status !== AgentOrderContext::STATUS_PAID
                || (int) $source->status !== Order::STATUS_COMPLETED || $source->refund_disposed_at || (int) $source->refund_amount > 0) {
                throw new ApiException('升级旧订单已失效或无法核实成本，请联系站点客服');
            }
            if (app(AgentCollectionService::class)->isPlatform($context)) {
                $this->assertFunding($source, $context, false);
            }
            array_push($queue, ...array_map('intval', $source->upgrade_source_order_ids ?? []));
        }
    }

    public function capture(Order $order, AgentOrderContext $context): void
    {
        User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
        $this->assertFunding($order, $context);
        if ((int) $order->balance_amount > 0) {
            DB::table(self::ALLOCATIONS)->where('order_id', $order->id)->where('status', 'reserved')
                ->update(['status' => 'captured', 'updated_at' => time()]);
        }
    }

    public function release(Order $order): bool
    {
        $context = $this->platformContext($order);
        if (!$context || (int) $order->balance_amount === 0) {
            return false;
        }
        User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
        if (!DB::getSchemaBuilder()->hasTable(self::ALLOCATIONS)) {
            throw new ApiException('代收余额记录缺失');
        }
        $rows = DB::table(self::ALLOCATIONS)->where('order_id', $order->id)->orderBy('fund_id')->lockForUpdate()->get();
        if ((int) $rows->sum('amount') !== (int) $order->balance_amount) {
            throw new ApiException('代收余额预占记录缺失');
        }
        foreach ($rows as $row) {
            if ($row->status !== 'reserved') {
                continue;
            }
            $fund = DB::table(self::FUNDS)->where('id', $row->fund_id)->lockForUpdate()->firstOrFail();
            if (!$fund->reversed_at) {
                DB::table(self::FUNDS)->where('id', $fund->id)->update([
                    'remaining_amount' => (int) $fund->remaining_amount + (int) $row->amount,
                    'remaining_fee' => (int) $fund->remaining_fee + (int) $row->fee_amount, 'updated_at' => time(),
                ]);
            }
            DB::table(self::ALLOCATIONS)->where('id', $row->id)->update(['status' => 'released', 'updated_at' => time()]);
        }
        return true;
    }

    public function reverseRecharge(Order $order): array
    {
        if (!DB::getSchemaBuilder()->hasTable(self::FUNDS)) {
            return [];
        }
        $fund = DB::table(self::FUNDS)->where('order_id', $order->id)->lockForUpdate()->first();
        if (!$fund) {
            return [];
        }
        DB::table(self::FUNDS)->where('id', $fund->id)->update([
            'remaining_amount' => 0, 'remaining_fee' => 0, 'reversed_at' => $fund->reversed_at ?: time(), 'updated_at' => time(),
        ]);
        return DB::table(self::ALLOCATIONS)->where('fund_id', $fund->id)->where('status', 'captured')->pluck('order_id')->all();
    }

    public function hasLiabilities(int $agentId): bool
    {
        if (!DB::getSchemaBuilder()->hasTable(self::FUNDS)) {
            return false;
        }
        return DB::table(self::FUNDS)->where('agent_user_id', $agentId)->whereNull('reversed_at')->where('remaining_amount', '>', 0)->exists()
            || DB::table('v2_agent_order_context as c')->join('v2_order as o', 'c.order_id', '=', 'o.id')
                ->where('c.agent_user_id', $agentId)->where('c.pricing_snapshot->collection->mode', 'platform')
                ->whereIn('o.status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])->exists();
    }

    private function platformContext(Order $order): ?AgentOrderContext
    {
        if (!DB::getSchemaBuilder()->hasTable('v2_agent_order_context')) {
            return null;
        }
        $context = app(AgentCommerceService::class)->contextForOrder($order);
        return app(AgentCollectionService::class)->isPlatform($context) ? $context : null;
    }
}

<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AgentProfitService
{
    private const WALLET = 'v2_agent_profit_wallet';
    private const PROFIT = 'v2_agent_profit';
    private const WITHDRAWAL = 'v2_agent_profit_withdrawal';

    // Order transitions always lock order -> wallet. Withdrawals lock wallet only.
    public function accrue(Order $order): void
    {
        if (!DB::getSchemaBuilder()->hasTable('v2_agent_order_context')) {
            return;
        }
        $context = app(AgentCommerceService::class)->contextForOrder($order);
        $collection = app(AgentCollectionService::class);
        if (!$collection->isPlatform($context) || (int) $order->status !== Order::STATUS_COMPLETED) {
            return;
        }
        if (!$order->paid_at || $order->refund_disposed_at || (int) $order->refund_amount > 0) {
            return;
        }
        if ((int) $order->balance_amount !== 0 || (int) $order->plan_id === 0
            || (int) $order->total_amount !== (int) $context->sale_amount
            || (int) $order->payment_id !== (int) $context->payment_id
            || ($context->payment_snapshot['owner_type'] ?? null) !== 'platform') {
            throw new ApiException('平台代收资金来源校验失败，订单暂不结算');
        }
        $wallet = $this->lockWallet((int) $context->agent_user_id);
        if (DB::table(self::PROFIT)->where('order_id', $order->id)->exists()) {
            return;
        }
        $snapshot = $collection->forOrder($context);
        $fee = $collection->fee((int) $context->sale_amount, $snapshot);
        $amount = (int) $context->sale_amount - (int) $context->cost_amount - $fee;
        if ($amount < 0) {
            throw new ApiException('代收售价不足以覆盖成本与服务费');
        }
        $now = time();
        DB::table(self::PROFIT)->insert([
            'agent_user_id' => $context->agent_user_id, 'order_id' => $order->id, 'trade_no' => $order->trade_no,
            'sale_amount' => $context->sale_amount, 'cost_amount' => $context->cost_amount,
            'fee_amount' => $fee, 'amount' => $amount, 'status' => 'pending',
            'available_at' => $now + (int) $snapshot['settlement_days'] * 86400,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $before = clone $wallet;
        $wallet->pending += $amount;
        $this->saveWallet($wallet, $before, 'order:' . $order->id . ':accrue');
    }

    public function reverse(Order $order, int $actorId = 0): void
    {
        if (!DB::getSchemaBuilder()->hasTable(self::PROFIT)) {
            return;
        }
        $profit = DB::table(self::PROFIT)->where('order_id', $order->id)->first();
        if (!$profit || $profit->status === 'reversed') {
            return;
        }
        $wallet = $this->lockWallet((int) $profit->agent_user_id);
        $profit = DB::table(self::PROFIT)->where('id', $profit->id)->lockForUpdate()->first();
        if ($profit->status === 'reversed') {
            return;
        }
        $before = clone $wallet;
        if ($profit->status === 'pending') {
            $wallet->pending -= (int) $profit->amount;
        } else {
            $deduct = min((int) $wallet->available, (int) $profit->amount);
            $wallet->available -= $deduct;
            $wallet->debt += (int) $profit->amount - $deduct;
        }
        DB::table(self::PROFIT)->where('id', $profit->id)->update(['status' => 'reversed', 'updated_at' => time()]);
        $this->saveWallet($wallet, $before, 'order:' . $order->id . ':reverse', $actorId);
    }

    public function settle(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->first();
            if (!$order) {
                return false;
            }
            if ($order->refund_disposed_at || (int) $order->refund_amount > 0) {
                $this->reverse($order);
                return false;
            }
            if ((int) $order->status !== Order::STATUS_COMPLETED) {
                return false;
            }
            $profit = DB::table(self::PROFIT)->where('order_id', $orderId)->first();
            if (!$profit || $profit->status !== 'pending' || (int) $profit->available_at > time()) {
                return false;
            }
            $wallet = $this->lockWallet((int) $profit->agent_user_id);
            $before = clone $wallet;
            $wallet->pending -= (int) $profit->amount;
            $this->creditAvailable($wallet, (int) $profit->amount);
            DB::table(self::PROFIT)->where('id', $profit->id)->update(['status' => 'available', 'updated_at' => time()]);
            $this->saveWallet($wallet, $before, 'order:' . $orderId . ':settle');
            return true;
        }, 3);
    }

    public function requestWithdrawal(int $agentId, array $data): array
    {
        return DB::transaction(function () use ($agentId, $data) {
            app(AgentCollectionService::class)->assertActive($agentId);
            $wallet = $this->lockWallet($agentId);
            $fingerprint = hash_hmac('sha256', json_encode([
                (int) $data['amount'], $data['method'], trim($data['account']),
            ], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $previous = DB::table(self::WITHDRAWAL)->where('agent_user_id', $agentId)
                ->where('request_key', $data['request_key'])->first();
            if ($previous) {
                if (!hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new ApiException('请勿使用相同请求编号提交不同提现申请');
                }
                return $this->withdrawalView($previous);
            }
            $amount = (int) $data['amount'];
            $minimum = (int) app(AgentCollectionService::class)->settings($agentId)->minimum_withdrawal;
            if ($amount <= 0 || $amount < $minimum || $amount > (int) $wallet->available || (int) $wallet->debt > 0) {
                throw new ApiException('收益不足、低于最低提现金额或存在待抵扣收益');
            }
            $before = clone $wallet;
            $wallet->available -= $amount;
            $wallet->frozen += $amount;
            $id = DB::table(self::WITHDRAWAL)->insertGetId([
                'agent_user_id' => $agentId, 'request_key' => $data['request_key'], 'fingerprint' => $fingerprint,
                'amount' => $amount, 'method' => $data['method'], 'account' => Crypt::encryptString(trim($data['account'])),
                'status' => 'pending', 'created_at' => time(), 'updated_at' => time(),
            ]);
            $this->saveWallet($wallet, $before, 'withdrawal:' . $id . ':request', $agentId);
            return $this->withdrawalView(DB::table(self::WITHDRAWAL)->where('id', $id)->first());
        }, 3);
    }

    public function review(int $id, string $action, int $adminId, string $reference, string $note): array
    {
        return DB::transaction(function () use ($id, $action, $adminId, $reference, $note) {
            $record = DB::table(self::WITHDRAWAL)->where('id', $id)->first();
            if (!$record) {
                throw new ApiException('提现申请不存在');
            }
            $wallet = $this->lockWallet((int) $record->agent_user_id);
            $record = DB::table(self::WITHDRAWAL)->where('id', $id)->lockForUpdate()->first();
            $targets = ['approve' => 'approved', 'paid' => 'paid', 'reject' => 'rejected'];
            $target = $targets[$action] ?? null;
            if (!$target) {
                throw new ApiException('审核操作无效');
            }
            if ($record->status === $target) {
                if ($target === 'paid' && $record->reference !== trim($reference)) {
                    throw new ApiException('该申请已记录其他打款凭证');
                }
                return $this->withdrawalView($record);
            }
            if (in_array($record->status, ['paid', 'rejected'], true)
                || ($action === 'paid' && $record->status !== 'approved')
                || ($action === 'approve' && $record->status !== 'pending')) {
                throw new ApiException('提现状态已变化，请刷新后重试');
            }
            if ($action !== 'reject') {
                app(AgentCollectionService::class)->assertActive((int) $record->agent_user_id);
                if ((int) $wallet->debt > 0) {
                    throw new ApiException('存在退款待抵扣收益，暂不可审核通过或确认打款');
                }
            }
            if (($action === 'paid' && trim($reference) === '') || ($action === 'reject' && trim($note) === '')) {
                throw new ApiException('请填写打款凭证或驳回原因');
            }
            $before = clone $wallet;
            if ($action === 'paid' || $action === 'reject') {
                $wallet->frozen -= (int) $record->amount;
                if ($action === 'reject') {
                    $this->creditAvailable($wallet, (int) $record->amount);
                }
            }
            DB::table(self::WITHDRAWAL)->where('id', $id)->update([
                'status' => $target, 'reference' => $action === 'paid' ? trim($reference) : null,
                'note' => $note, 'reviewed_by' => $adminId, 'updated_at' => time(),
            ]);
            $this->saveWallet($wallet, $before, 'withdrawal:' . $id . ':' . $target, $adminId);
            return $this->withdrawalView(DB::table(self::WITHDRAWAL)->where('id', $id)->first());
        }, 3);
    }

    public function summary(int $agentId): array
    {
        $wallet = DB::table(self::WALLET)->where('agent_user_id', $agentId)->first();
        return array_merge(array_fill_keys(['pending', 'available', 'frozen', 'debt'], 0), $wallet ? $this->state($wallet) : []);
    }

    public function hasCollectionHistory(int $userId): bool
    {
        if (!DB::getSchemaBuilder()->hasTable(self::PROFIT)) {
            return false;
        }
        return DB::table('v2_agent_order_context')->where('pricing_snapshot->collection->mode', 'platform')
            ->where(fn ($q) => $q->where('agent_user_id', $userId)
                ->orWhereIn('order_id', Order::where('user_id', $userId)->select('id')))->exists()
            || DB::table(self::WITHDRAWAL)->where('agent_user_id', $userId)->exists();
    }

    public function withdrawals(?int $agentId, int $page = 1): array
    {
        $query = DB::table(self::WITHDRAWAL)->when($agentId !== null, fn ($q) => $q->where('agent_user_id', $agentId));
        $total = $query->count();
        $rows = $query->orderByDesc('id')->forPage($page, 20)->get();
        return ['items' => $rows->map(fn ($r) => $this->withdrawalView($r))->all(), 'total' => $total, 'page' => $page];
    }

    private function withdrawalView(object $record): array
    {
        $result = (array) $record;
        unset($result['fingerprint'], $result['request_key']);
        foreach (['id', 'agent_user_id', 'amount', 'created_at', 'updated_at'] as $field) {
            $result[$field] = (int) $result[$field];
        }
        $result['account'] = Crypt::decryptString($record->account);
        return $result;
    }

    private function lockWallet(int $agentId): object
    {
        DB::table(self::WALLET)->insertOrIgnore(['agent_user_id' => $agentId, 'updated_at' => time()]);
        return DB::table(self::WALLET)->where('agent_user_id', $agentId)->lockForUpdate()->first();
    }

    private function creditAvailable(object $wallet, int $amount): void
    {
        $offset = min((int) $wallet->debt, $amount);
        $wallet->debt -= $offset;
        $wallet->available += $amount - $offset;
    }

    private function state(object $wallet): array
    {
        return array_map('intval', array_intersect_key((array) $wallet, array_flip(['pending', 'available', 'frozen', 'debt'])));
    }

    private function saveWallet(object $wallet, object $before, string $key, int $actorId = 0): void
    {
        $state = $this->state($wallet);
        if (min($state) < 0) {
            throw new \RuntimeException('Agent profit balance invariant violated');
        }
        DB::table(self::WALLET)->where('agent_user_id', $wallet->agent_user_id)->update($state + ['updated_at' => time()]);
        DB::table('v2_agent_profit_ledger')->insert([
            'agent_user_id' => $wallet->agent_user_id, 'event_key' => $key,
            'before_state' => json_encode($this->state($before), JSON_THROW_ON_ERROR),
            'after_state' => json_encode($state, JSON_THROW_ON_ERROR),
            'actor_id' => $actorId ?: null, 'created_at' => time(),
        ]);
    }
}

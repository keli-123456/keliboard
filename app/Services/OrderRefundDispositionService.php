<?php

namespace App\Services;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\User;
use App\Models\UserSyncState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderRefundDispositionService
{
    public const CREDIT_BALANCE = 'balance';
    public const CREDIT_COMMISSION_BALANCE = 'commission_balance';

    /**
     * @return array<string, mixed>
     */
    public function preview(Order $order, int $page = 1): array
    {
        $user = User::query()->find($order->user_id);
        if (!$user) {
            throw new \InvalidArgumentException('订单用户不存在');
        }
        if ($user->is_admin || $user->is_staff) {
            throw new \InvalidArgumentException('不能通过退款处置封禁管理员或员工');
        }
        if (in_array((int) $order->status, [Order::STATUS_PENDING, Order::STATUS_CANCELLED], true)) {
            throw new \InvalidArgumentException('只能处置已支付或已完成的订单');
        }
        return app(AgentRefundDispositionService::class)->preview($order, $user, $page);
    }

    public function dispose(Order $order, int $adminId, array $options = []): array
    {
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('管理员身份无效');
        }
        return DB::transaction(function () use ($order, $adminId, $options): array {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedOrder) {
                throw new \InvalidArgumentException('订单不存在');
            }

            $user = User::query()
                ->whereKey($lockedOrder->user_id)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                throw new \InvalidArgumentException('订单用户不存在');
            }

            if ((bool) $user->is_admin || (bool) $user->is_staff) {
                throw new \InvalidArgumentException('不能通过退款处置封禁管理员或员工');
            }

            if ($lockedOrder->refund_disposed_at) {
                return $this->result($lockedOrder, $user, true);
            }

            if (in_array((int) $lockedOrder->status, [
                Order::STATUS_PENDING,
                Order::STATUS_CANCELLED,
            ], true)) {
                throw new \InvalidArgumentException('只能处置已支付或已完成的订单');
            }

            $agentDisposition = app(AgentRefundDispositionService::class)->apply($lockedOrder, $user, $options, $adminId);
            app(AgentProfitService::class)->reverseAffectedOrders($lockedOrder, $adminId);
            $reversedAmount = $this->reverseCommissionLogs($lockedOrder, $adminId);
            $now = time();

            $lockedOrder->commission_status = Order::COMMISSION_STATUS_INVALID;
            $lockedOrder->refund_amount = max(
                (int) ($lockedOrder->refund_amount ?? 0),
                max(0, (int) $lockedOrder->total_amount)
            );
            $lockedOrder->commission_reversed_amount = $reversedAmount;
            $lockedOrder->refund_disposed_at = $now;
            $lockedOrder->refund_disposed_by = $adminId;
            if ($agentDisposition !== null) {
                $lockedOrder->agent_refund_disposition = $agentDisposition;
            }
            $lockedOrder->saveOrFail();

            $hasSyncStates = Schema::hasTable('user_sync_states');
            if ($hasSyncStates) {
                UserSyncState::query()->firstOrCreate(
                    ['user_id' => $user->id], app(UserSyncService::class)->computeSnapshot($user)
                );
            }
            $user->banned = true;
            $user->banned_reason = sprintf('退款订单处置：%s', $lockedOrder->trade_no);
            $user->saveOrFail();
            if ($hasSyncStates) {
                app(UserSyncService::class)->syncUser($user, 'refund_disposition');
            }
            if (Schema::hasTable('personal_access_tokens')) {
                (new AuthService($user))->removeAllSessions();
            }

            return $this->result($lockedOrder, $user, false);
        }, 3);
    }

    private function reverseCommissionLogs(Order $order, int $adminId): int
    {
        $logs = CommissionLog::query()
            ->where('trade_no', $order->trade_no)
            ->whereNull('reversed_at')
            ->lockForUpdate()
            ->get();

        $reversedAmount = 0;
        $now = time();

        foreach ($logs as $log) {
            $amount = max(0, (int) $log->get_amount);
            if ($amount <= 0) {
                $this->markLogReversed($log, $adminId, $now, $this->resolveCreditTarget($log));
                continue;
            }

            $inviter = User::query()
                ->whereKey($log->invite_user_id)
                ->lockForUpdate()
                ->first();

            $creditTarget = $this->resolveCreditTarget($log);
            if ($inviter) {
                $inviter->{$creditTarget} = (int) $inviter->{$creditTarget} - $amount;
                $inviter->saveOrFail();
            }

            $this->markLogReversed($log, $adminId, $now, $creditTarget);
            $reversedAmount += $amount;
        }

        return $reversedAmount;
    }

    private function resolveCreditTarget(CommissionLog $log): string
    {
        if (in_array($log->credited_to, [
            self::CREDIT_BALANCE,
            self::CREDIT_COMMISSION_BALANCE,
        ], true)) {
            return $log->credited_to;
        }

        return (int) admin_setting('withdraw_close_enable', 0)
            ? self::CREDIT_BALANCE
            : self::CREDIT_COMMISSION_BALANCE;
    }

    private function markLogReversed(
        CommissionLog $log,
        int $adminId,
        int $reversedAt,
        string $creditTarget
    ): void {
        $log->credited_to = $creditTarget;
        $log->reversed_at = $reversedAt;
        $log->reversed_by_admin_id = $adminId;
        $log->saveOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Order $order, User $user, bool $alreadyProcessed): array
    {
        return [
            'already_processed' => $alreadyProcessed,
            'order_id' => (int) $order->id,
            'trade_no' => (string) $order->trade_no,
            'user_id' => (int) $user->id,
            'user_email' => (string) $user->email,
            'user_banned' => (bool) $user->banned,
            'agent_disposition' => $order->agent_refund_disposition,
            'refund_amount' => (int) ($order->refund_amount ?? 0),
            'commission_reversed_amount' => (int) ($order->commission_reversed_amount ?? 0),
            'commission_status' => $order->commission_status !== null
                ? (int) $order->commission_status
                : null,
            'disposed_at' => $order->refund_disposed_at
                ? (int) $order->refund_disposed_at
                : null,
            'disposed_by' => $order->refund_disposed_by
                ? (int) $order->refund_disposed_by
                : null,
        ];
    }
}

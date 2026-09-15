<?php

namespace App\Console\Commands;

use App\Services\ReferralEligibilityService;
use App\Models\CommissionLog;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckCommission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(): int
    {
        $this->autoCheck();
        $this->autoPayCommission();

        return self::SUCCESS;
    }

    public function autoCheck()
    {
        if ((int)admin_setting('commission_auto_check_enable', 1)) {
            $query = Order::where('commission_status', Order::COMMISSION_STATUS_PENDING)
                ->whereNotNull('invite_user_id')
                ->where('status', Order::STATUS_COMPLETED)
                ->where('updated_at', '<=', strtotime('-3 day', time()));

            app(ReferralEligibilityService::class)->excludeAgentOrders($query)->whereNull('refund_disposed_at')
                ->where(fn ($q) => $q->whereNull('refund_amount')->orWhere('refund_amount', 0))->update([
                'commission_status' => Order::COMMISSION_STATUS_PROCESSING
            ]);
        }
    }

    public function autoPayCommission()
    {
        $query = Order::where('commission_status', Order::COMMISSION_STATUS_PROCESSING)
            ->whereNotNull('invite_user_id')
            ->select(['id', 'trade_no', 'user_id', 'invite_user_id', 'commission_status', 'commission_balance']);

        app(ReferralEligibilityService::class)->excludeAgentOrders($query)->chunkById(200, function ($orders): void {
            foreach ($orders as $order) {
                try {
                    $this->payHandle($order->invite_user_id, $order);
                } catch (\Throwable $e) {
                    Log::error('Auto pay commission failed', [
                        'order_id' => $order->id,
                        'trade_no' => $order->trade_no ?? null,
                        'invite_user_id' => $order->invite_user_id ?? null,
                        'commission_status' => $order->commission_status ?? null,
                        'commission_balance' => $order->commission_balance ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    public function payHandle($inviteUserId, Order $order)
    {
        return DB::transaction(function () use ($inviteUserId, $order): bool {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$order || (int) $order->commission_status !== Order::COMMISSION_STATUS_PROCESSING) return false;
            if ((int) $order->invite_user_id !== (int) $inviteUserId || !$inviteUserId
                || !in_array((int) $order->status, [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED], true)
                || !$order->paid_at || $order->refund_disposed_at || (int) $order->refund_amount > 0) {
                throw new \RuntimeException('Commission order is not eligible for settlement');
            }
            $eligibility = app(ReferralEligibilityService::class);
            $participants = User::whereIn('id', [$order->user_id, $order->invite_user_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $buyer = $participants->get($order->user_id);
            if (!$buyer || $eligibility->excludesOrder($order, true)) {
                throw new \RuntimeException('Agent orders and subordinate referrals require review');
            }
            if ((int) $order->actual_commission_balance !== 0
                || CommissionLog::where('trade_no', $order->trade_no)->lockForUpdate()->first(['id'])) {
                throw new \RuntimeException('Commission ledger already exists; manual reconciliation required');
            }
            $budget = (int) $order->commission_balance;
            $fundedAmount = (int) $order->total_amount + (int) $order->balance_amount;
            if ($budget < 0 || $budget > $fundedAmount || $budget > intdiv(PHP_INT_MAX, 100)) {
                throw new \RuntimeException('Commission budget exceeds funded order amount');
            }
            $shares = (int) admin_setting('commission_distribution_enable', 0)
                ? [admin_setting('commission_distribution_l1', 0), admin_setting('commission_distribution_l2', 0), admin_setting('commission_distribution_l3', 0)]
                : [100];
            foreach ($shares as $share) {
                if (filter_var($share, FILTER_VALIDATE_INT) === false || (int) $share < 0 || (int) $share > 100) {
                    throw new \RuntimeException('Invalid commission distribution percentage');
                }
            }
            $shares = array_map('intval', $shares);
            if (array_sum($shares) > 100) throw new \RuntimeException('Commission distribution exceeds its budget');

            // Validate the entire payable chain before touching any wallet.
            $recipients = [];
            $seen = [(int) $buyer->id => true];
            foreach ($shares as $share) {
                if (!$inviteUserId) break;
                if (isset($seen[(int) $inviteUserId])) throw new \RuntimeException('Cyclic commission attribution');
                $seen[(int) $inviteUserId] = true;
                $inviter = User::whereKey($inviteUserId)->lockForUpdate()->first();
                if (!$inviter || $eligibility->isAgentUser((int) $inviteUserId, true)) {
                    throw new \RuntimeException('Commission recipient is missing or an agent subordinate');
                }
                $recipients[] = [$inviter, intdiv($budget * $share, 100)];
                $inviteUserId = $inviter->invite_user_id;
            }
            $field = (int) admin_setting('withdraw_close_enable', 0) ? 'balance' : 'commission_balance';
            $total = 0;
            foreach ($recipients as [$inviter, $amount]) {
                if ($amount === 0) continue;
                $inviter->{$field} = (int) $inviter->{$field} + $amount;
                $inviter->saveOrFail();
                CommissionLog::create([
                    'invite_user_id' => $inviter->id, 'user_id' => $order->user_id,
                    'trade_no' => $order->trade_no, 'order_amount' => $order->total_amount,
                    'get_amount' => $amount, 'credited_to' => $field,
                ]);
                $total += $amount;
            }
            $order->actual_commission_balance = $total;
            $order->commission_status = Order::COMMISSION_STATUS_VALID;
            $order->saveOrFail();
            return true;
        }, 3);
    }

}

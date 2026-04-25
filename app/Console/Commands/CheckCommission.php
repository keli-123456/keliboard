<?php

namespace App\Console\Commands;

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
            Order::where('commission_status', 0)
                ->whereNotNull('invite_user_id')
                ->where('status', 3)
                ->where('updated_at', '<=', strtotime('-3 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
        }
    }

    public function autoPayCommission()
    {
        Order::where('commission_status', 1)
            ->whereNotNull('invite_user_id')
            ->select(['id', 'trade_no', 'invite_user_id', 'commission_status', 'commission_balance'])
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    try {
                        DB::transaction(function () use ($order) {
                            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->first();
                            if (!$lockedOrder) return;
                            if ((int) $lockedOrder->commission_status !== 1) return;
                            if (empty($lockedOrder->invite_user_id)) return;

                            if (!$this->payHandle($lockedOrder->invite_user_id, $lockedOrder)) {
                                throw new \RuntimeException('payHandle returned false');
                            }

                            $lockedOrder->commission_status = 2;
                            $lockedOrder->saveOrFail();
                        }, 3);
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
        $level = 3;
        if ((int)admin_setting('commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)admin_setting('commission_distribution_l1'),
                1 => (int)admin_setting('commission_distribution_l2'),
                2 => (int)admin_setting('commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::find($inviteUserId);
            if (!$inviter) continue;
            if (!isset($commissionShareLevels[$l])) continue;
            $commissionBalance = $order->commission_balance * ($commissionShareLevels[$l] / 100);
            if (!$commissionBalance) continue;
            if ((int)admin_setting('withdraw_close_enable', 0)) {
                $inviter->balance = $inviter->balance + $commissionBalance;
            } else {
                $inviter->commission_balance = $inviter->commission_balance + $commissionBalance;
            }
            if (!$inviter->save()) {
                return false;
            }
            CommissionLog::create([
                'invite_user_id' => $inviteUserId,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance
            ]);
            $inviteUserId = $inviter->invite_user_id;
            // update order actual commission balance
            $order->actual_commission_balance = $order->actual_commission_balance + $commissionBalance;
        }
        return true;
    }

}

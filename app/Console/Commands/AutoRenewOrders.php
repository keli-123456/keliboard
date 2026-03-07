<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoRenewOrders extends Command
{
    private const LOOKAHEAD_SECONDS = 86400;
    private const CALLBACK_NO = 'auto_renew_balance';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'renew:auto';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '使用账户余额自动续费即将到期的套餐';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = time();

        User::query()
            ->where('auto_renew_enable', 1)
            ->where('banned', 0)
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<=', $now + self::LOOKAHEAD_SECONDS)
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $this->processUser((int) $user->id);
                }
            });

        return self::SUCCESS;
    }

    private function processUser(int $userId): void
    {
        $order = null;

        try {
            DB::transaction(function () use ($userId, &$order): void {
                $user = User::query()
                    ->lockForUpdate()
                    ->find($userId);

                if (!$user || !$this->shouldAttemptAutoRenew($user)) {
                    return;
                }

                if (app(UserService::class)->isNotCompleteOrderByUserId($user->id)) {
                    return;
                }

                $plan = Plan::find($user->plan_id);
                if (!$plan || !$this->supportsAutoRenew($plan, $user->auto_renew_period)) {
                    return;
                }

                $amount = $this->getAutoRenewAmount($user, $plan, $user->auto_renew_period);
                if ($amount > 0 && (int) $user->balance < $amount) {
                    return;
                }

                $order = OrderService::createFromRequest($user, $plan, $user->auto_renew_period);
                if ((int) $order->total_amount > 0) {
                    throw new \RuntimeException("Auto renew order requires external payment: {$order->trade_no}");
                }
            });

            if (!$order) {
                return;
            }

            $freshOrder = $order->fresh();
            if (!$freshOrder) {
                return;
            }

            $orderService = new OrderService($freshOrder);
            if (!$orderService->paid(self::CALLBACK_NO)) {
                $freshOrder->refresh();
                if ((int) $freshOrder->status === Order::STATUS_PENDING) {
                    (new OrderService($freshOrder))->cancel();
                }
                throw new \RuntimeException("Failed to complete auto renew order: {$freshOrder->trade_no}");
            }
        } catch (\Throwable $e) {
            Log::warning('Auto renew failed', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shouldAttemptAutoRenew(User $user): bool
    {
        $now = time();

        return (bool) $user->auto_renew_enable
            && !empty($user->plan_id)
            && !empty($user->expired_at)
            && $user->expired_at > $now
            && $user->expired_at <= ($now + self::LOOKAHEAD_SECONDS);
    }

    private function supportsAutoRenew(Plan $plan, ?string $period): bool
    {
        if (!$plan->renew || !User::isAutoRenewPeriod($period)) {
            return false;
        }

        $periodKey = PlanService::getPeriodKey($period);
        if (in_array($periodKey, [Plan::PERIOD_ONETIME, Plan::PERIOD_RESET_TRAFFIC], true)) {
            return false;
        }

        $price = $plan->prices[$periodKey] ?? null;
        return $price !== null && (float) $price > 0;
    }

    private function getAutoRenewAmount(User $user, Plan $plan, string $period): int
    {
        $periodKey = PlanService::getPeriodKey($period);
        $baseAmount = (int) round(((float) ($plan->prices[$periodKey] ?? 0)) * 100);
        if ($baseAmount <= 0) {
            return 0;
        }

        $discountAmount = $user->discount
            ? (int) round($baseAmount * ($user->discount / 100))
            : 0;

        return max(0, $baseAmount - $discountAmount);
    }
}

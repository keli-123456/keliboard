<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCommerceContextResolver;
use App\Services\AgentCommerceService;
use App\Services\AgentStorefrontService;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\SiteCommerceService;
use App\Services\SiteStorefrontService;
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

                $order = $this->createAutoRenewOrder($user, $plan, $user->auto_renew_period);
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

        $agentContext = app(AgentCommerceContextResolver::class)->resolveUser($user);
        if ($agentContext) {
            $sale = app(AgentStorefrontService::class)->resolveSalePrice(
                (int) $agentContext['agent_user_id'],
                (int) $plan->id,
                $periodKey
            );

            return max(0, (int) ($sale['sale_amount'] ?? 0));
        }

        $siteContext = app(SiteCommerceService::class)->contextForUser($user);
        if ($siteContext) {
            $pricing = app(SiteStorefrontService::class)->resolveSalePrice(
                (int) $siteContext['site_id'],
                (int) $plan->id,
                $periodKey
            );

            return $this->applyUserDiscount($user, max(0, (int) ($pricing['sale_amount'] ?? 0)));
        }

        return $this->applyUserDiscount(
            $user,
            OrderService::amountToCents($plan->prices[$periodKey] ?? 0)
        );
    }

    private function createAutoRenewOrder(User $user, Plan $plan, string $period): Order
    {
        $agentOrder = app(AgentCommerceService::class)->createAutoRenewOrder($user, $plan, $period);
        if ($agentOrder) {
            return $agentOrder;
        }

        $siteOrder = app(SiteCommerceService::class)->createAutoRenewOrder($user, $plan, $period);
        if ($siteOrder) {
            return $siteOrder;
        }

        return OrderService::createFromRequest($user, $plan, $period);
    }

    private function applyUserDiscount(User $user, int $baseAmount): int
    {
        if ($baseAmount <= 0) {
            return 0;
        }

        $discountAmount = $user->discount
            ? OrderService::percentageOfAmount($baseAmount, $user->discount)
            : 0;

        return max(0, $baseAmount - $discountAmount);
    }
}

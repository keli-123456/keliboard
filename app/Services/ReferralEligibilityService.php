<?php

namespace App\Services;

use App\Models\AgentOrderContext;
use App\Models\AgentUser;
use App\Models\Order;
use Illuminate\Support\Facades\Schema;

class ReferralEligibilityService
{
    public function isAgentUser(?int $userId, bool $lock = false): bool
    {
        if (!$userId || !Schema::hasTable('v2_agent_user')) return false;
        return AgentUser::where('sub_user_id', $userId)->when($lock, fn ($q) => $q->lockForUpdate())
            ->first(['id']) !== null;
    }

    public function excludesOrder(Order $order, bool $lock = false): bool
    {
        if ($order->id && Schema::hasTable('v2_agent_order_context')
            && AgentOrderContext::where('order_id', $order->id)->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])) {
            return true;
        }
        return $this->isAgentUser((int) $order->user_id, $lock)
            || $this->isAgentUser((int) $order->invite_user_id, $lock);
    }

    public function excludeAgentOrders($query)
    {
        if (Schema::hasTable('v2_agent_order_context')) {
            $query->whereNotIn('id', AgentOrderContext::query()->select('order_id'));
        }
        if (Schema::hasTable('v2_agent_user')) {
            $query->whereNotIn('user_id', AgentUser::query()->select('sub_user_id'))
                ->whereNotIn('invite_user_id', AgentUser::query()->select('sub_user_id'));
        }
        return $query;
    }
}

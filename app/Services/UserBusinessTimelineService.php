<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserBusinessTimelineService
{
    public function inspect(User $user, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $sources = [];
        $parts = [];
        $add = static function (string $table, string $time, string $kind, string $userColumn = 'user_id') use ($user, &$parts): void {
            $parts[] = DB::table($table)->where($userColumn, $user->id)->where($time, '>', 0)
                ->select(['id as entity_id', $time . ' as occurred_at'])->selectRaw("'{$kind}' as kind");
        };
        $add('v2_user', 'created_at', 'registered', 'id');
        if (Schema::hasTable('v2_order')) {
            $sources[] = 'orders';
            $add('v2_order', 'created_at', 'order_created');
            $add('v2_order', 'paid_at', 'payment_recorded');
            if (Schema::hasColumn('v2_order', 'refund_disposed_at')) $add('v2_order', 'refund_disposed_at', 'refund_processed');
        }
        if (Schema::hasTable('v2_gift_card_usage')) {
            $sources[] = 'gift_cards';
            $add('v2_gift_card_usage', 'created_at', 'gift_redeemed');
        }
        if (Schema::hasTable('v2_traffic_reset_logs')) {
            $sources[] = 'traffic_resets';
        }
        if (Schema::hasTable('v2_admin_audit_log')) {
            $sources[] = 'admin_requests';
            $recent = DB::table('v2_admin_audit_log')->select(['id', 'created_at', 'action', 'request_data'])
                ->orderByDesc('id')->limit(1000);
            // Legacy audits may contain invalid JSON and record requests, not successful changes.
            $document = "CASE WHEN JSON_VALID(request_data) THEN request_data ELSE '{}' END";
            $target = "JSON_EXTRACT({$document}, '$.id')";
            if (DB::connection()->getDriverName() !== 'sqlite') $target = "JSON_UNQUOTE({$target})";
            $parts[] = DB::query()->fromSub($recent, 'recent_audit')
                ->whereIn('action', ['user.update', 'user.ban', 'user.resetSecret'])
                ->whereRaw("CAST({$target} AS CHAR) = ?", [(string) $user->id])
                ->where('created_at', '>', 0)->select(['id as entity_id', 'created_at as occurred_at'])
                ->selectRaw("'admin_request' as kind");
        }
        $union = array_shift($parts);
        foreach ($parts as $part) $union->unionAll($part);
        $events = DB::query()->fromSub($union, 'activity');
        $total = (clone $events)->count();
        $rows = $events->orderByDesc('occurred_at')->orderBy('kind')->orderByDesc('entity_id')
            ->forPage($page, $perPage)->get();
        $orderIds = $rows->filter(fn ($row) => in_array($row->kind, ['order_created', 'payment_recorded', 'refund_processed'], true))->pluck('entity_id');
        $orderFields = ['id', 'plan_id', 'period', 'type', 'status', 'total_amount', 'paid_at', 'created_at', 'refund_amount'];
        $orders = $orderIds->isEmpty() ? collect() : Order::where('user_id', $user->id)->whereIn('id', $orderIds)->get($orderFields)->keyBy('id');
        $giftIds = $rows->where('kind', 'gift_redeemed')->pluck('entity_id');
        $gifts = $giftIds->isEmpty() ? collect() : DB::table('v2_gift_card_usage')->where('user_id', $user->id)
            ->whereIn('id', $giftIds)->get(['id', 'rewards_given'])->keyBy('id');
        $resets = !in_array('traffic_resets', $sources, true) ? collect() : DB::table('v2_traffic_reset_logs')
            ->where('user_id', $user->id)->orderByDesc('id')->limit(10)->get(['id', 'reset_time', 'trigger_source', 'reset_type']);
        $candidates = in_array('orders', $sources, true) && $user->plan_id
            ? Order::where('user_id', $user->id)->where('plan_id', $user->plan_id)->where('status', Order::STATUS_COMPLETED)
                ->where(fn ($query) => $query->whereNull('refund_amount')->orWhere('refund_amount', '<=', 0))
                ->orderByDesc('id')->limit(5)->get($orderFields)
            : collect();
        $planIds = $orders->pluck('plan_id')->merge($candidates->pluck('plan_id'))->push($user->plan_id)->filter()->unique();
        $plans = $planIds->isEmpty() ? collect() : Plan::whereIn('id', $planIds)->get(['id', 'name'])->keyBy('id');
        $items = [];
        foreach ($rows as $row) {
            $at = is_numeric($row->occurred_at) ? (int) $row->occurred_at : strtotime($row->occurred_at);
            $item = ['key' => $row->kind . ':' . $row->entity_id, 'kind' => $row->kind,
                'entity_id' => (int) $row->entity_id, 'at' => $at ?: null,
                'evidence' => $row->kind === 'admin_request' ? 'request_only' : 'record'];
            if ($order = $orders->get($row->entity_id)) {
                if (in_array($row->kind, ['order_created', 'payment_recorded', 'refund_processed'], true)) {
                    $item += $this->orderPayload($order, $plans);
                }
            }
            if ($row->kind === 'gift_redeemed' && $gift = $gifts->get($row->entity_id)) {
                $rewards = json_decode((string) $gift->rewards_given, true) ?: [];
                $item['reward_types'] = array_values(array_intersect(array_keys($rewards),
                    ['plan_id', 'plan_validity_days', 'transfer_enable', 'balance', 'commission_balance', 'reset_traffic']));
                $item['reward_plan_id'] = isset($rewards['plan_id']) && is_numeric($rewards['plan_id']) ? (int) $rewards['plan_id'] : null;
            }
            $items[] = $item;
        }
        return ['user_id' => (int) $user->id, 'observed_at' => time(), 'read_only' => true,
            'currency_symbol' => (string) admin_setting('currency_symbol', '¥'),
            'entitlement' => ['plan_id' => $user->plan_id, 'plan_name' => $plans->get($user->plan_id)?->name,
                'expired_at' => $user->expired_at, 'transfer_enable' => (int) $user->transfer_enable,
                'banned' => (bool) $user->banned, 'source_status' => 'unconfirmed',
                'related_completed_orders' => $candidates->map(fn ($order) => $this->orderPayload($order, $plans))->values()->all()],
            'events' => $items,
            'recent_traffic_resets' => $resets->map(fn ($reset) => ['id' => (int) $reset->id,
                'at' => strtotime($reset->reset_time) ?: null, 'trigger_source' => $reset->trigger_source,
                'reset_type' => $reset->reset_type])->all(),
            'pagination' => ['total' => $total, 'current_page' => $page, 'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage))],
            'available_sources' => $sources, 'gateway_verified' => false, 'complete_history' => false,
            'admin_adjustments_reconstructed' => false, 'audit_window_limit' => 1000];
    }

    private function orderPayload(Order $order, $plans): array
    {
        return ['order_id' => (int) $order->id, 'plan_id' => $order->plan_id,
            'plan_name' => $plans->get($order->plan_id)?->name, 'status' => (int) $order->status,
            'order_type' => (int) $order->type, 'period' => $order->period,
            'amount' => (int) $order->total_amount, 'refund_amount' => (int) $order->refund_amount,
            'paid_at' => $order->paid_at ? (int) $order->paid_at : null];
    }
}

<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Site;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RetentionAnalyticsController extends Controller
{
    public function overview(Request $request)
    {
        $filters = $request->validate([
            'days' => 'nullable|integer|in:30,90,180,365',
            'site_id' => 'nullable|integer|min:1',
            'ownership' => 'nullable|in:platform,agent,all',
        ]);
        if (!Schema::hasTable('v2_order') || !Schema::hasTable('v2_user')) {
            return $this->success($this->emptyPayload());
        }

        $days = (int) ($filters['days'] ?? 90);
        $start = now()->startOfDay()->subDays($days - 1)->timestamp;
        $orders = $this->paidOrders($start, $filters);
        $summary = $this->orderSummary(clone $orders);
        $summary = array_merge($summary, $this->subscriberSummary($filters));

        return $this->success([
            'range_days' => $days,
            'summary' => $summary,
            'trend' => $this->trend(clone $orders, $days),
            'site_performance' => $this->sitePerformance($start, $filters),
            'at_risk_users' => $this->atRiskUsers($filters),
            'sites' => $this->siteOptions(),
        ]);
    }

    private function paidOrders(int $start, array $filters): Builder
    {
        $query = DB::table('v2_order as o')
            ->join('v2_user as u', 'u.id', '=', 'o.user_id')
            ->where('o.status', Order::STATUS_COMPLETED)
            ->whereIn('o.type', [Order::TYPE_NEW_PURCHASE, Order::TYPE_RENEWAL])
            ->whereRaw('COALESCE(NULLIF(o.paid_at, 0), o.updated_at) >= ?', [$start]);
        $this->applyOwnership($query, 'o.user_id', $filters);
        if (!empty($filters['site_id'])) {
            $query->whereRaw('COALESCE(o.site_id, u.site_id) = ?', [(int) $filters['site_id']]);
        }
        return $query;
    }

    private function orderSummary(Builder $query): array
    {
        $row = $query->selectRaw('SUM(CASE WHEN o.type = ? THEN 1 ELSE 0 END) new_orders', [Order::TYPE_NEW_PURCHASE])
            ->selectRaw('SUM(CASE WHEN o.type = ? THEN 1 ELSE 0 END) renewal_orders', [Order::TYPE_RENEWAL])
            ->selectRaw('COUNT(DISTINCT CASE WHEN o.type = ? THEN o.user_id END) new_customers', [Order::TYPE_NEW_PURCHASE])
            ->selectRaw('COUNT(DISTINCT CASE WHEN o.type = ? THEN o.user_id END) renewal_customers', [Order::TYPE_RENEWAL])
            ->selectRaw('SUM(CASE WHEN o.type = ? THEN o.total_amount + COALESCE(o.handling_amount, 0) ELSE 0 END) new_revenue', [Order::TYPE_NEW_PURCHASE])
            ->selectRaw('SUM(CASE WHEN o.type = ? THEN o.total_amount + COALESCE(o.handling_amount, 0) ELSE 0 END) renewal_revenue', [Order::TYPE_RENEWAL])
            ->first();
        $newOrders = (int) ($row->new_orders ?? 0);
        $renewalOrders = (int) ($row->renewal_orders ?? 0);
        $paidOrders = $newOrders + $renewalOrders;
        return [
            'new_orders' => $newOrders,
            'renewal_orders' => $renewalOrders,
            'new_customers' => (int) ($row->new_customers ?? 0),
            'renewal_customers' => (int) ($row->renewal_customers ?? 0),
            'new_revenue' => (int) ($row->new_revenue ?? 0),
            'renewal_revenue' => (int) ($row->renewal_revenue ?? 0),
            'renewal_order_share' => $paidOrders > 0 ? round($renewalOrders * 100 / $paidOrders, 2) : 0.0,
        ];
    }

    private function subscriberSummary(array $filters): array
    {
        $now = time();
        $base = DB::table('v2_user as u')->whereRaw('COALESCE(u.is_admin, 0) = 0');
        $this->applyOwnership($base, 'u.id', $filters);
        if (!empty($filters['site_id'])) $base->where('u.site_id', (int) $filters['site_id']);
        $active = (clone $base)->whereNotNull('u.plan_id')->where('u.banned', 0)
            ->where(fn (Builder $q) => $q->whereNull('u.expired_at')->orWhere('u.expired_at', 0)->orWhere('u.expired_at', '>', $now));
        return [
            'active_subscribers' => (clone $active)->count(),
            'auto_renew_enabled' => (clone $active)->where('u.auto_renew_enable', 1)->count(),
            'expiring_7d' => (clone $base)->whereNotNull('u.plan_id')->where('u.banned', 0)->whereBetween('u.expired_at', [$now + 1, $now + 7 * 86400])->count(),
            'expiring_30d' => (clone $base)->whereNotNull('u.plan_id')->where('u.banned', 0)->whereBetween('u.expired_at', [$now + 1, $now + 30 * 86400])->count(),
            'expired_30d' => (clone $base)->whereNotNull('u.plan_id')->whereBetween('u.expired_at', [$now - 30 * 86400, $now])->count(),
        ];
    }

    private function trend(Builder $orders, int $days): array
    {
        $dateSql = "DATE(FROM_UNIXTIME(COALESCE(NULLIF(o.paid_at, 0), o.updated_at)))";
        if (DB::connection()->getDriverName() === 'sqlite') {
            $dateSql = "DATE(COALESCE(NULLIF(o.paid_at, 0), o.updated_at), 'unixepoch')";
        }
        $rows = $orders->groupByRaw($dateSql)->selectRaw("{$dateSql} day")
            ->selectRaw('SUM(CASE WHEN o.type = ? THEN 1 ELSE 0 END) new_orders', [Order::TYPE_NEW_PURCHASE])
            ->selectRaw('SUM(CASE WHEN o.type = ? THEN 1 ELSE 0 END) renewal_orders', [Order::TYPE_RENEWAL])
            ->selectRaw('SUM(o.total_amount + COALESCE(o.handling_amount, 0)) revenue')
            ->get()->keyBy('day');
        $result = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = now()->startOfDay()->subDays($offset)->format('Y-m-d');
            $row = $rows->get($day);
            $result[] = ['date' => $day, 'new_orders' => (int) ($row->new_orders ?? 0), 'renewal_orders' => (int) ($row->renewal_orders ?? 0), 'revenue' => (int) ($row->revenue ?? 0)];
        }
        return $result;
    }

    private function sitePerformance(int $start, array $filters): array
    {
        $orders = $this->paidOrders($start, $filters)
            ->groupByRaw('COALESCE(o.site_id, u.site_id)')
            ->selectRaw('COALESCE(o.site_id, u.site_id) site_id')
            ->selectRaw('SUM(CASE WHEN o.type = ? THEN 1 ELSE 0 END) new_orders', [Order::TYPE_NEW_PURCHASE])
            ->selectRaw('SUM(CASE WHEN o.type = ? THEN 1 ELSE 0 END) renewal_orders', [Order::TYPE_RENEWAL])
            ->selectRaw('COUNT(DISTINCT CASE WHEN o.type = ? THEN o.user_id END) renewal_customers', [Order::TYPE_RENEWAL])
            ->selectRaw('SUM(o.total_amount + COALESCE(o.handling_amount, 0)) revenue')->get()->keyBy(fn ($row) => (int) ($row->site_id ?? 0));
        $users = DB::table('v2_user as u')->whereRaw('COALESCE(u.is_admin, 0) = 0');
        $this->applyOwnership($users, 'u.id', $filters);
        if (!empty($filters['site_id'])) $users->where('u.site_id', (int) $filters['site_id']);
        $now = time();
        $users = $users->groupBy('u.site_id')->selectRaw('u.site_id')
            ->selectRaw('SUM(CASE WHEN u.plan_id IS NOT NULL AND u.banned = 0 AND (u.expired_at IS NULL OR u.expired_at = 0 OR u.expired_at > ?) THEN 1 ELSE 0 END) active_subscribers', [$now])
            ->selectRaw('SUM(CASE WHEN u.plan_id IS NOT NULL AND u.expired_at BETWEEN ? AND ? THEN 1 ELSE 0 END) expiring_30d', [$now + 1, $now + 30 * 86400])
            ->get()->keyBy(fn ($row) => (int) ($row->site_id ?? 0));
        $siteNames = $this->siteOptions();
        $names = collect($siteNames)->mapWithKeys(fn ($site) => [(int) $site['id'] => (string) $site['name']]);
        $keys = $orders->keys()->merge($users->keys())->unique()->sort();
        return $keys->map(function ($key) use ($orders, $users, $names): array {
            $order = $orders->get($key); $user = $users->get($key);
            $new = (int) ($order->new_orders ?? 0); $renewal = (int) ($order->renewal_orders ?? 0);
            return ['site_id' => $key > 0 ? (int) $key : null, 'site_name' => $key > 0 ? (string) ($names->get($key) ?? '') : '',
                'new_orders' => $new, 'renewal_orders' => $renewal, 'renewal_customers' => (int) ($order->renewal_customers ?? 0),
                'renewal_order_share' => ($new + $renewal) > 0 ? round($renewal * 100 / ($new + $renewal), 2) : 0.0,
                'revenue' => (int) ($order->revenue ?? 0), 'active_subscribers' => (int) ($user->active_subscribers ?? 0), 'expiring_30d' => (int) ($user->expiring_30d ?? 0)];
        })->values()->all();
    }

    private function atRiskUsers(array $filters): array
    {
        $now = time();
        $stats = DB::table('v2_order')->where('status', Order::STATUS_COMPLETED)
            ->groupBy('user_id')->select('user_id')->selectRaw('MAX(COALESCE(NULLIF(paid_at, 0), updated_at)) last_paid_at')
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) renewal_orders', [Order::TYPE_RENEWAL]);
        $query = DB::table('v2_user as u')->leftJoin('v2_plan as p', 'p.id', '=', 'u.plan_id')->leftJoin('v2_site as s', 's.id', '=', 'u.site_id')
            ->leftJoinSub($stats, 'os', 'os.user_id', '=', 'u.id')->whereNotNull('u.plan_id')->where('u.banned', 0)
            ->whereBetween('u.expired_at', [$now - 30 * 86400, $now + 30 * 86400]);
        $this->applyOwnership($query, 'u.id', $filters);
        if (!empty($filters['site_id'])) $query->where('u.site_id', (int) $filters['site_id']);
        return $query->orderBy('u.expired_at')->limit(100)->get(['u.id', 'u.email', 'u.site_id', 's.name as site_name', 'u.plan_id', 'p.name as plan_name', 'u.expired_at', 'u.balance', 'u.auto_renew_enable', 'os.last_paid_at', 'os.renewal_orders'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'email' => (string) $row->email, 'site_id' => $row->site_id ? (int) $row->site_id : null, 'site_name' => (string) ($row->site_name ?? ''),
                'plan_id' => (int) $row->plan_id, 'plan_name' => (string) ($row->plan_name ?? ''), 'expired_at' => (int) $row->expired_at, 'balance' => (int) ($row->balance ?? 0),
                'auto_renew_enable' => (bool) $row->auto_renew_enable, 'last_paid_at' => $row->last_paid_at ? (int) $row->last_paid_at : null,
                'renewal_orders' => (int) ($row->renewal_orders ?? 0), 'risk' => (int) $row->expired_at <= $now ? 'expired' : ((int) $row->expired_at <= $now + 7 * 86400 ? 'expiring_7d' : 'expiring_30d')])->all();
    }

    private function applyOwnership(Builder $query, string $userColumn, array $filters): void
    {
        if (!Schema::hasTable('v2_agent_user')) return;
        $ownership = (string) ($filters['ownership'] ?? 'platform');
        if ($ownership === 'all') return;
        $method = $ownership === 'agent' ? 'whereExists' : 'whereNotExists';
        $query->{$method}(fn (Builder $q) => $q->selectRaw('1')->from('v2_agent_user as au')->whereColumn('au.sub_user_id', $userColumn));
    }

    private function siteOptions(): array
    {
        if (!Schema::hasTable('v2_site')) return [];
        return Site::query()->where('status', Site::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name'])->map(fn ($site) => ['id' => (int) $site->id, 'name' => (string) $site->name])->all();
    }

    private function emptyPayload(): array
    {
        return ['range_days' => 90, 'summary' => [], 'trend' => [], 'site_performance' => [], 'at_risk_users' => [], 'sites' => []];
    }
}

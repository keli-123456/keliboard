<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\DomainMetricDaily;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DomainAnalyticsController extends Controller
{
    public function overview(Request $request)
    {
        $data = $request->validate([
            'days' => 'nullable|integer|in:7,30,90',
            'site_id' => 'nullable|integer|min:1',
            'kind' => 'nullable|in:all,platform,site,agent',
            'search' => 'nullable|string|max:100',
        ]);
        $days = (int) ($data['days'] ?? 30);
        $start = now()->startOfDay()->subDays($days - 1)->format('Y-m-d');
        if (!Schema::hasTable('v2_domain_metric_daily')) {
            return $this->success($this->emptyPayload($days));
        }

        $query = $this->filtered($start, $data);
        $summary = $this->summary(clone $query);
        $ranking = $this->ranking(clone $query);
        $invite = $this->inviteByHost($days);
        foreach ($ranking as &$row) {
            $stats = $invite[$row['host']] ?? ['clicks' => 0, 'registrations' => 0];
            $row['invite_clicks'] = $stats['clicks'];
            $row['invite_registrations'] = $stats['registrations'];
        }

        return $this->success([
            'range_days' => $days,
            'summary' => $summary,
            'trend' => $this->trend(clone $query, $days),
            'ranking' => $ranking,
            'funnel' => $this->funnel($summary),
            'site_performance' => $this->sitePerformance(clone $query),
            'sites' => $this->siteOptions(),
        ]);
    }

    private function filtered(string $start, array $filters): Builder
    {
        $kind = (string) ($filters['kind'] ?? 'all');
        return DomainMetricDaily::query()
            ->where('record_date', '>=', $start)
            ->when(!empty($filters['site_id']), fn (Builder $q) => $q->where('site_id', (int) $filters['site_id']))
            ->when(!empty($filters['search']), fn (Builder $q) => $q->where('host', 'like', '%' . trim((string) $filters['search']) . '%'))
            ->when($kind === 'platform', fn (Builder $q) => $q->whereNull('site_id')->whereNull('agent_user_id'))
            ->when($kind === 'site', fn (Builder $q) => $q->whereNotNull('site_id'))
            ->when($kind === 'agent', fn (Builder $q) => $q->whereNotNull('agent_user_id'));
    }

    private function summary(Builder $query): array
    {
        $row = $query->selectRaw('SUM(page_views) page_views, SUM(unique_visitors) unique_visitors, SUM(registrations) registrations, SUM(orders_created) orders_created, SUM(orders_paid) orders_paid, SUM(revenue_amount) revenue_amount, SUM(subscription_pulls) subscription_pulls')->first();
        return $this->metricArray($row);
    }

    private function ranking(Builder $query): array
    {
        return $query->leftJoin('v2_site as site', 'site.id', '=', 'v2_domain_metric_daily.site_id')
            ->leftJoin('v2_user as agent', 'agent.id', '=', 'v2_domain_metric_daily.agent_user_id')
            ->groupBy('host', 'v2_domain_metric_daily.site_id', 'site.name', 'v2_domain_metric_daily.agent_user_id', 'agent.email')
            ->selectRaw('host, v2_domain_metric_daily.site_id, site.name site_name, v2_domain_metric_daily.agent_user_id, agent.email agent_email')
            ->selectRaw('SUM(page_views) page_views, SUM(unique_visitors) unique_visitors, SUM(registrations) registrations, SUM(orders_created) orders_created, SUM(orders_paid) orders_paid, SUM(revenue_amount) revenue_amount, SUM(subscription_pulls) subscription_pulls')
            ->orderByDesc('page_views')->limit(100)->get()->map(function ($row): array {
                $metrics = $this->metricArray($row);
                return array_merge([
                    'host' => (string) $row->host,
                    'site_id' => $row->site_id ? (int) $row->site_id : null,
                    'site_name' => (string) ($row->site_name ?? ''),
                    'agent_user_id' => $row->agent_user_id ? (int) $row->agent_user_id : null,
                    'agent_email' => (string) ($row->agent_email ?? ''),
                    'conversion_rate' => $metrics['unique_visitors'] > 0 ? round($metrics['registrations'] * 100 / $metrics['unique_visitors'], 2) : 0.0,
                ], $metrics);
            })->all();
    }

    private function trend(Builder $query, int $days): array
    {
        $rows = $query->groupBy('record_date')->select('record_date')
            ->selectRaw('SUM(page_views) page_views, SUM(unique_visitors) unique_visitors, SUM(registrations) registrations, SUM(orders_paid) orders_paid, SUM(revenue_amount) revenue_amount, SUM(subscription_pulls) subscription_pulls')
            ->orderBy('record_date')->get()->keyBy('record_date');
        $result = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = now()->startOfDay()->subDays($offset)->format('Y-m-d');
            $row = $rows->get($date);
            $result[] = array_merge(['date' => $date], $this->metricArray($row));
        }
        return $result;
    }

    private function funnel(array $metrics): array
    {
        $visitors = max(0, (int) $metrics['unique_visitors']);
        $registrations = max(0, (int) $metrics['registrations']);
        $created = max(0, (int) $metrics['orders_created']);
        $paid = max(0, (int) $metrics['orders_paid']);
        return [
            $this->funnelStage('visitors', $visitors, null),
            $this->funnelStage('registrations', $registrations, $visitors),
            $this->funnelStage('orders_created', $created, $registrations),
            $this->funnelStage('orders_paid', $paid, $created),
        ];
    }

    private function funnelStage(string $key, int $value, ?int $previous): array
    {
        return [
            'key' => $key,
            'value' => $value,
            'rate' => $previous === null ? 100.0 : ($previous > 0 ? round($value * 100 / $previous, 2) : 0.0),
        ];
    }

    private function sitePerformance(Builder $query): array
    {
        if (!Schema::hasTable('v2_site')) return [];
        return $query->whereNull('v2_domain_metric_daily.agent_user_id')
            ->leftJoin('v2_site as performance_site', 'performance_site.id', '=', 'v2_domain_metric_daily.site_id')
            ->groupBy('v2_domain_metric_daily.site_id', 'performance_site.name')
            ->selectRaw('v2_domain_metric_daily.site_id, performance_site.name site_name')
            ->selectRaw('SUM(page_views) page_views, SUM(unique_visitors) unique_visitors, SUM(registrations) registrations, SUM(orders_created) orders_created, SUM(orders_paid) orders_paid, SUM(revenue_amount) revenue_amount, SUM(subscription_pulls) subscription_pulls')
            ->orderByDesc('revenue_amount')
            ->get()
            ->map(function ($row): array {
                $metrics = $this->metricArray($row);
                return array_merge([
                    'site_id' => $row->site_id ? (int) $row->site_id : null,
                    'site_name' => (string) ($row->site_name ?? ''),
                    'visitor_registration_rate' => $metrics['unique_visitors'] > 0
                        ? round($metrics['registrations'] * 100 / $metrics['unique_visitors'], 2)
                        : 0.0,
                    'order_payment_rate' => $metrics['orders_created'] > 0
                        ? round($metrics['orders_paid'] * 100 / $metrics['orders_created'], 2)
                        : 0.0,
                    'average_order_amount' => $metrics['orders_paid'] > 0
                        ? (int) round($metrics['revenue_amount'] / $metrics['orders_paid'])
                        : 0,
                ], $metrics);
            })
            ->all();
    }

    private function inviteByHost(int $days): array
    {
        if (!Schema::hasTable('v2_invite_click')) return [];
        return DB::table('v2_invite_click')->whereNotNull('landing_host')
            ->where('clicked_at', '>=', now()->startOfDay()->subDays($days - 1)->timestamp)
            ->groupBy('landing_host')->select('landing_host')
            ->selectRaw('SUM(hit_count) clicks, SUM(CASE WHEN registered_user_id IS NOT NULL THEN 1 ELSE 0 END) registrations')
            ->get()->mapWithKeys(fn ($row) => [(string) $row->landing_host => ['clicks' => (int) $row->clicks, 'registrations' => (int) $row->registrations]])->all();
    }

    private function metricArray($row): array
    {
        return [
            'page_views' => (int) ($row->page_views ?? 0), 'unique_visitors' => (int) ($row->unique_visitors ?? 0),
            'registrations' => (int) ($row->registrations ?? 0), 'orders_created' => (int) ($row->orders_created ?? 0),
            'orders_paid' => (int) ($row->orders_paid ?? 0), 'revenue_amount' => (int) ($row->revenue_amount ?? 0),
            'subscription_pulls' => (int) ($row->subscription_pulls ?? 0),
        ];
    }

    private function siteOptions(): array
    {
        if (!Schema::hasTable('v2_site')) return [];
        return Site::query()->where('status', Site::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name'])->map(fn ($site) => ['id' => (int) $site->id, 'name' => (string) $site->name])->all();
    }

    private function emptyPayload(int $days): array
    {
        $summary = $this->metricArray(null);
        return ['range_days' => $days, 'summary' => $summary, 'trend' => [], 'ranking' => [], 'funnel' => $this->funnel($summary), 'site_performance' => [], 'sites' => $this->siteOptions()];
    }
}

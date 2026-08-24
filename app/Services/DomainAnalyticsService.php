<?php

namespace App\Services;

use App\Models\DomainMetricDaily;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class DomainAnalyticsService
{
    private const METRIC_COLUMNS = [
        'page_views', 'unique_visitors', 'registrations', 'orders_created',
        'orders_paid', 'revenue_amount', 'subscription_pulls',
    ];

    public function recordPageView(Request $request): void
    {
        $host = $this->host($request);
        if ($host === '' || !$this->available()) return;
        $this->purgeExpiredVisitorHashes();

        $date = date('Y-m-d');
        $inserted = DB::table('v2_domain_visitor_daily')->insertOrIgnore([
            'record_date' => $date,
            'host' => $host,
            'visitor_hash' => $this->visitorHash($request, $date),
            'created_at' => time(),
        ]);
        $this->increment($request, $host, [
            'page_views' => 1,
            'unique_visitors' => $inserted > 0 ? 1 : 0,
        ]);
    }

    public function recordRegistration(Request $request): void
    {
        $this->incrementRequest($request, ['registrations' => 1]);
    }

    public function recordOrderCreated(Request $request, Order $order): void
    {
        $host = $this->host($request);
        if ($host === '' || !$this->available()) return;
        if (Schema::hasColumn('v2_order', 'analytics_host')) {
            $order->analytics_host = $host;
            $order->save();
        }
        $this->increment($request, $host, ['orders_created' => 1]);
    }

    public function recordOrderPaid(Order $order): void
    {
        if (!$this->available()) return;
        $host = strtolower(trim((string) ($order->analytics_host ?? '')));
        if ($host === '') return;
        $this->incrementHost($host, [
            'orders_paid' => 1,
            'revenue_amount' => max(0, (int) $order->total_amount + (int) $order->handling_amount),
        ], (int) ($order->site_id ?: 0) ?: null);
    }

    public function recordSubscriptionPull(Request $request): void
    {
        $this->incrementRequest($request, ['subscription_pulls' => 1]);
    }

    private function incrementRequest(Request $request, array $values): void
    {
        $host = $this->host($request);
        if ($host !== '' && $this->available()) $this->increment($request, $host, $values);
    }

    private function increment(Request $request, string $host, array $values): void
    {
        $site = app(SiteResolver::class)->resolveRequest($request);
        $agent = app(AgentDomainResolver::class)->resolveRequest($request);
        $this->incrementHost($host, $values, $site['site_id'] ?? null, $site['site_domain_id'] ?? null, $agent);
    }

    private function incrementHost(string $host, array $values, ?int $siteId = null, ?int $siteDomainId = null, ?array $agent = null): void
    {
        $now = time();
        DomainMetricDaily::query()->upsert([[
            'record_date' => date('Y-m-d'),
            'host' => $host,
            'site_id' => $siteId,
            'site_domain_id' => $siteDomainId,
            'agent_user_id' => $agent['agent_user_id'] ?? null,
            'agent_domain_id' => $agent['agent_domain_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['record_date', 'host'], ['site_id', 'site_domain_id', 'agent_user_id', 'agent_domain_id', 'updated_at']);

        $increments = array_intersect_key($values, array_flip(self::METRIC_COLUMNS));
        if (!$increments) return;
        $query = DomainMetricDaily::query()->where('record_date', date('Y-m-d'))->where('host', $host);
        foreach ($increments as $column => $value) {
            if ((int) $value > 0) $query->increment($column, (int) $value, ['updated_at' => $now]);
        }
    }

    private function host(Request $request): string
    {
        $raw = (string) ($request->headers->get('x-forwarded-host') ?: $request->headers->get('host', ''));
        return app(SiteResolver::class)->normalizeHost($raw);
    }

    private function visitorHash(Request $request, string $date): string
    {
        $fingerprint = implode('|', [$date, strtolower((string) $request->ip()), strtolower((string) $request->userAgent()), strtolower((string) $request->header('Accept-Language', ''))]);
        return hash_hmac('sha256', $fingerprint, (string) config('app.key', 'keliboard'));
    }

    private function purgeExpiredVisitorHashes(): void
    {
        try {
            $key = 'domain_analytics_visitor_cleanup_' . date('Y-m-d');
            if (!Cache::add($key, 1, now()->addDay())) return;
            DB::table('v2_domain_visitor_daily')
                ->where('record_date', '<', date('Y-m-d', strtotime('-35 days')))
                ->delete();
        } catch (\Throwable) {
            // Analytics maintenance must never interrupt a user request.
        }
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('v2_domain_metric_daily') && Schema::hasTable('v2_domain_visitor_daily');
        } catch (\Throwable) {
            return false;
        }
    }
}

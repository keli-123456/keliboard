<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\InviteClick;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InviteMonitorController extends Controller
{
    public function overview(Request $request)
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|in:7,30,90',
            'site_id' => 'nullable|integer|min:1',
            'source' => 'nullable|string|max:50',
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $startAt = now()->startOfDay()->subDays($days - 1)->timestamp;
        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        $source = trim((string) ($validated['source'] ?? ''));

        if (!Schema::hasTable('v2_invite_click')) {
            return $this->success($this->emptyPayload($days));
        }

        $query = $this->filteredQuery($startAt, $siteId, $source);
        $summary = [
            'clicks' => (int) (clone $query)->sum('hit_count'),
            'visitors' => (int) (clone $query)->distinct()->count('visitor_hash'),
            'registrations' => (int) (clone $query)->whereNotNull('registered_user_id')->count(),
        ];
        $summary['conversion_rate'] = $summary['visitors'] > 0
            ? round($summary['registrations'] * 100 / $summary['visitors'], 2)
            : 0.0;

        return $this->success([
            'range_days' => $days,
            'summary' => $summary,
            'trend' => $this->trend($startAt, $siteId, $source, $days),
            'sources' => $this->sourceRows($startAt, $siteId, $source),
            'ranking' => $this->rankingRows($startAt, $siteId, $source),
            'recent' => $this->recentRows($startAt, $siteId, $source),
            'sites' => $this->siteOptions(),
        ]);
    }

    private function filteredQuery(int $startAt, ?int $siteId, string $source): Builder
    {
        return InviteClick::query()
            ->where('clicked_at', '>=', $startAt)
            ->when($siteId !== null, fn (Builder $query) => $query->where('site_id', $siteId))
            ->when($source !== '', fn (Builder $query) => $query->where('source', $source));
    }

    private function trend(int $startAt, ?int $siteId, string $source, int $days): array
    {
        $driver = DB::connection()->getDriverName();
        $dayExpression = match ($driver) {
            'sqlite' => "date(clicked_at, 'unixepoch')",
            'pgsql' => "to_char(to_timestamp(clicked_at), 'YYYY-MM-DD')",
            default => 'DATE(FROM_UNIXTIME(clicked_at))',
        };

        $rows = $this->filteredQuery($startAt, $siteId, $source)
            ->selectRaw("{$dayExpression} as day")
            ->selectRaw('SUM(hit_count) as clicks')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->selectRaw('SUM(CASE WHEN registered_user_id IS NOT NULL THEN 1 ELSE 0 END) as registrations')
            ->groupBy(DB::raw($dayExpression))
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $result = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = now()->startOfDay()->subDays($offset)->format('Y-m-d');
            $row = $rows->get($day);
            $result[] = [
                'date' => $day,
                'clicks' => (int) ($row->clicks ?? 0),
                'visitors' => (int) ($row->visitors ?? 0),
                'registrations' => (int) ($row->registrations ?? 0),
            ];
        }

        return $result;
    }

    private function sourceRows(int $startAt, ?int $siteId, string $source): array
    {
        return $this->filteredQuery($startAt, $siteId, $source)
            ->select('source')
            ->selectRaw('SUM(hit_count) as clicks')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as visitors')
            ->selectRaw('SUM(CASE WHEN registered_user_id IS NOT NULL THEN 1 ELSE 0 END) as registrations')
            ->groupBy('source')
            ->orderByDesc('clicks')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'source' => (string) $row->source,
                'clicks' => (int) $row->clicks,
                'visitors' => (int) $row->visitors,
                'registrations' => (int) $row->registrations,
                'conversion_rate' => (int) $row->visitors > 0
                    ? round((int) $row->registrations * 100 / (int) $row->visitors, 2)
                    : 0.0,
            ])
            ->all();
    }

    private function rankingRows(int $startAt, ?int $siteId, string $source): array
    {
        $query = DB::table('v2_invite_click as click')
            ->join('v2_user as inviter', 'inviter.id', '=', 'click.inviter_user_id')
            ->where('click.clicked_at', '>=', $startAt)
            ->when($siteId !== null, fn ($builder) => $builder->where('click.site_id', $siteId))
            ->when($source !== '', fn ($builder) => $builder->where('click.source', $source))
            ->groupBy('click.inviter_user_id', 'inviter.email')
            ->selectRaw('click.inviter_user_id as user_id, inviter.email')
            ->selectRaw('SUM(click.hit_count) as clicks')
            ->selectRaw('COUNT(DISTINCT click.visitor_hash) as visitors')
            ->selectRaw('SUM(CASE WHEN click.registered_user_id IS NOT NULL THEN 1 ELSE 0 END) as registrations')
            ->orderByDesc('registrations')
            ->orderByDesc('visitors')
            ->limit(50)
            ->get();

        return $query->map(fn ($row) => [
            'user_id' => (int) $row->user_id,
            'email' => (string) $row->email,
            'clicks' => (int) $row->clicks,
            'visitors' => (int) $row->visitors,
            'registrations' => (int) $row->registrations,
            'conversion_rate' => (int) $row->visitors > 0
                ? round((int) $row->registrations * 100 / (int) $row->visitors, 2)
                : 0.0,
        ])->all();
    }

    private function recentRows(int $startAt, ?int $siteId, string $source): array
    {
        return $this->filteredQuery($startAt, $siteId, $source)
            ->with(['inviter:id,email', 'site:id,code,name'])
            ->latest('last_clicked_at')
            ->limit(30)
            ->get()
            ->map(fn (InviteClick $click) => [
                'id' => (int) $click->id,
                'visitor' => substr((string) $click->visitor_hash, 0, 8),
                'invite_code' => (string) $click->invite_code,
                'inviter_user_id' => (int) $click->inviter_user_id,
                'inviter_email' => (string) ($click->inviter?->email ?? ''),
                'site_id' => $click->site_id ? (int) $click->site_id : null,
                'site_name' => (string) ($click->site?->name ?? '主站'),
                'source' => (string) $click->source,
                'referrer_host' => (string) ($click->referrer_host ?? ''),
                'landing_host' => (string) ($click->landing_host ?? ''),
                'hit_count' => (int) $click->hit_count,
                'clicked_at' => (int) $click->last_clicked_at,
                'converted' => $click->registered_user_id !== null,
                'registered_user_id' => $click->registered_user_id ? (int) $click->registered_user_id : null,
            ])
            ->all();
    }

    private function siteOptions(): array
    {
        if (!Schema::hasTable('v2_site')) {
            return [];
        }

        return Site::query()
            ->where('status', Site::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Site $site) => [
                'id' => (int) $site->id,
                'code' => (string) $site->code,
                'name' => (string) $site->name,
            ])
            ->all();
    }

    private function emptyPayload(int $days): array
    {
        return [
            'range_days' => $days,
            'summary' => ['clicks' => 0, 'visitors' => 0, 'registrations' => 0, 'conversion_rate' => 0.0],
            'trend' => [],
            'sources' => [],
            'ranking' => [],
            'recent' => [],
            'sites' => $this->siteOptions(),
        ];
    }
}

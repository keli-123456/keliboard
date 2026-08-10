<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ScanDomainHealthJob;
use App\Models\DomainHealth;
use App\Services\DomainHealthMonitorService;
use Illuminate\Http\Request;
use Throwable;

class DomainHealthController extends Controller
{
    public function overview(Request $request, DomainHealthMonitorService $monitor)
    {
        $monitor->synchronizeTargets();

        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, min(100, (int) $request->input('pageSize', $request->input('page_size', 30))));
        $sourceType = trim((string) $request->input('source_type', ''));
        $status = trim((string) $request->input('status', ''));
        $keyword = trim((string) $request->input('keyword', ''));

        $query = DomainHealth::query()
            ->where('monitored', true)
            ->orderByRaw("CASE status WHEN 'down' THEN 0 WHEN 'warning' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END")
            ->orderByDesc('alert_active')
            ->orderBy('domain');

        if (in_array($sourceType, [
            DomainHealth::SOURCE_SITE,
            DomainHealth::SOURCE_AGENT,
            DomainHealth::SOURCE_NAVIGATION,
            DomainHealth::SOURCE_SYSTEM,
        ], true)) {
            $query->where('source_type', $sourceType);
        }
        if (in_array($status, [
            DomainHealth::STATUS_UNKNOWN,
            DomainHealth::STATUS_HEALTHY,
            DomainHealth::STATUS_WARNING,
            DomainHealth::STATUS_DOWN,
        ], true)) {
            $query->where('status', $status);
        }
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('domain', 'like', '%' . $keyword . '%')
                    ->orWhere('source_name', 'like', '%' . $keyword . '%');
            });
        }

        $page = $query->paginate($pageSize, ['*'], 'page', $current);

        return $this->success([
            'items' => array_map(
                fn (DomainHealth $domain): array => $monitor->payload($domain),
                $page->items(),
            ),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
            ],
            'summary' => $monitor->summary(),
            'settings' => $monitor->settings(),
        ]);
    }

    public function saveSettings(Request $request, DomainHealthMonitorService $monitor)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'failure_threshold' => 'required|integer|min:1|max:10',
            'timeout_seconds' => 'required|integer|min:2|max:20',
            'certificate_warning_days' => 'required|integer|min:1|max:60',
            'telegram_notify' => 'required|boolean',
        ]);

        return $this->success($monitor->saveSettings($data));
    }

    public function check(Request $request, DomainHealthMonitorService $monitor)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_domain_health,id',
        ]);

        try {
            $domain = DomainHealth::query()->findOrFail((int) $data['id']);

            return $this->success($monitor->payload($monitor->scanOne($domain, true)));
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }

    public function checkAll()
    {
        try {
            ScanDomainHealthJob::dispatch();

            return $this->success(['queued' => true]);
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }
}

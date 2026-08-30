<?php

namespace App\Services;

final class SystemHealthEvaluator
{
    private const STATUS_WEIGHT = [
        'healthy' => 0,
        'warning' => 1,
        'critical' => 2,
    ];

    public function evaluate(array $metrics): array
    {
        $checks = [
            $this->evaluateTraffic((array) ($metrics['traffic'] ?? [])),
            $this->evaluateScheduler((array) ($metrics['scheduler'] ?? [])),
            $this->evaluateQueue((array) ($metrics['queue'] ?? [])),
            $this->evaluateLogStorage((array) ($metrics['log_storage'] ?? [])),
            $this->evaluateDatabaseCapacity((array) ($metrics['database_capacity'] ?? [])),
            $this->evaluateMigrations((array) ($metrics['migrations'] ?? [])),
            $this->evaluateOperationTasks((array) ($metrics['operation_tasks'] ?? [])),
            $this->evaluateBackup((array) ($metrics['backup'] ?? [])),
            $this->evaluateExternalServices((array) ($metrics['external_services'] ?? [])),
        ];

        usort($checks, fn(array $left, array $right) =>
            self::STATUS_WEIGHT[$right['status']] <=> self::STATUS_WEIGHT[$left['status']]
        );

        $summary = ['healthy' => 0, 'warning' => 0, 'critical' => 0];
        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        $status = $summary['critical'] > 0
            ? 'critical'
            : ($summary['warning'] > 0 ? 'warning' : 'healthy');

        return [
            'status' => $status,
            'checked_at' => (int) ($metrics['checked_at'] ?? time()),
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    public function evaluateTraffic(array $metrics): array
    {
        if (($metrics['available'] ?? true) === false) {
            return $this->check('traffic', 'critical', 'unavailable');
        }

        $latestUpdatedAt = (int) ($metrics['latest_updated_at'] ?? 0);
        $now = (int) ($metrics['now'] ?? time());
        $todayStart = (int) ($metrics['today_start'] ?? strtotime('today', $now));
        $todayRows = (int) ($metrics['today_rows'] ?? 0);
        $monthTotal = (int) ($metrics['month_total'] ?? 0);
        $ageSeconds = $latestUpdatedAt > 0 ? max(0, $now - $latestUpdatedAt) : null;

        $status = 'healthy';
        $reason = 'fresh';

        if (($now - $todayStart) >= 1200 && $monthTotal > 0 && $todayRows === 0) {
            $status = 'critical';
            $reason = 'today_missing';
        } elseif ($latestUpdatedAt <= 0) {
            $status = 'warning';
            $reason = 'missing';
        } elseif ($ageSeconds !== null && $ageSeconds >= 3600) {
            $status = 'critical';
            $reason = 'stale';
        } elseif ($ageSeconds !== null && $ageSeconds >= 900) {
            $status = 'warning';
            $reason = 'delayed';
        }

        return $this->check('traffic', $status, $reason, [
            'latest_updated_at' => $latestUpdatedAt ?: null,
            'age_seconds' => $ageSeconds,
            'today_rows' => $todayRows,
            'today_total' => (int) ($metrics['today_total'] ?? 0),
            'month_total' => $monthTotal,
        ]);
    }

    public function evaluateScheduler(array $metrics): array
    {
        $running = (bool) ($metrics['running'] ?? false);

        return $this->check('scheduler', $running ? 'healthy' : 'critical', $running ? 'running' : 'stopped', [
            'last_runtime' => isset($metrics['last_runtime']) ? (int) $metrics['last_runtime'] : null,
        ]);
    }

    public function evaluateQueue(array $metrics): array
    {
        if (($metrics['available'] ?? true) === false) {
            return $this->check('queue', 'critical', 'unavailable');
        }

        $running = (bool) ($metrics['running'] ?? false);
        $failedJobs = (int) ($metrics['failed_jobs'] ?? 0);
        $waitSeconds = (int) ($metrics['wait_seconds'] ?? 0);
        $pausedMasters = (int) ($metrics['paused_masters'] ?? 0);

        if (!$running || $pausedMasters > 0) {
            $status = 'critical';
            $reason = 'stopped';
        } elseif ($waitSeconds >= 60) {
            $status = 'warning';
            $reason = 'delayed';
        } elseif ($failedJobs > 0) {
            $status = 'warning';
            $reason = 'failed_jobs';
        } else {
            $status = 'healthy';
            $reason = 'running';
        }

        return $this->check('queue', $status, $reason, [
            'failed_jobs' => $failedJobs,
            'wait_seconds' => $waitSeconds,
            'paused_masters' => $pausedMasters,
            'processes' => (int) ($metrics['processes'] ?? 0),
        ]);
    }

    public function evaluateLogStorage(array $metrics): array
    {
        $bytes = max(0, (int) ($metrics['bytes'] ?? 0));

        if ($bytes >= 1073741824) {
            $status = 'critical';
            $reason = 'oversized';
        } elseif ($bytes >= 268435456) {
            $status = 'warning';
            $reason = 'growing';
        } else {
            $status = 'healthy';
            $reason = 'normal';
        }

        return $this->check('log_storage', $status, $reason, [
            'bytes' => $bytes,
            'files' => (int) ($metrics['files'] ?? 0),
            'latest_modified_at' => isset($metrics['latest_modified_at'])
                ? (int) $metrics['latest_modified_at']
                : null,
        ]);
    }

    public function evaluateDatabaseCapacity(array $metrics): array
    {
        if (($metrics['available'] ?? true) === false) {
            return $this->check('database_capacity', 'critical', 'unavailable');
        }

        if (($metrics['supported'] ?? true) === false) {
            return $this->check('database_capacity', 'healthy', 'not_applicable', $metrics);
        }

        $percent = max(0.0, (float) ($metrics['utilization_percent'] ?? 0));
        if ($percent >= 90) {
            $status = 'critical';
            $reason = 'nearly_exhausted';
        } elseif ($percent >= 75) {
            $status = 'warning';
            $reason = 'capacity_warning';
        } else {
            $status = 'healthy';
            $reason = 'normal';
        }

        return $this->check('database_capacity', $status, $reason, [
            'column_type' => (string) ($metrics['column_type'] ?? ''),
            'auto_increment' => isset($metrics['auto_increment']) ? (string) $metrics['auto_increment'] : null,
            'utilization_percent' => round($percent, 4),
            'table_bytes' => (int) ($metrics['table_bytes'] ?? 0),
        ]);
    }

    public function evaluateMigrations(array $metrics): array
    {
        if (($metrics['available'] ?? true) === false) {
            return $this->check('migrations', 'critical', 'unavailable');
        }

        $pending = max(0, (int) ($metrics['pending'] ?? 0));
        $status = $pending >= 5 ? 'critical' : ($pending > 0 ? 'warning' : 'healthy');
        $reason = $pending > 0 ? 'pending' : 'current';

        return $this->check('migrations', $status, $reason, [
            'pending' => $pending,
            'applied' => max(0, (int) ($metrics['applied'] ?? 0)),
            'total' => max(0, (int) ($metrics['total'] ?? 0)),
        ]);
    }

    public function evaluateOperationTasks(array $metrics): array
    {
        if (($metrics['available'] ?? false) === false) {
            return $this->check('operation_tasks', 'warning', 'unavailable');
        }

        $stale = max(0, (int) ($metrics['stale'] ?? 0));
        $failedRecent = max(0, (int) ($metrics['failed_recent'] ?? 0));
        $reportedStatus = (string) ($metrics['status'] ?? '');

        if ($stale > 0 || $reportedStatus === 'critical') {
            $status = 'critical';
            $reason = 'stale';
        } elseif ($failedRecent > 0 || $reportedStatus === 'warning') {
            $status = 'warning';
            $reason = 'failed_recent';
        } else {
            $status = 'healthy';
            $reason = 'healthy';
        }

        return $this->check('operation_tasks', $status, $reason, [
            'queued' => max(0, (int) ($metrics['queued'] ?? 0)),
            'running' => max(0, (int) ($metrics['running'] ?? 0)),
            'stale' => $stale,
            'failed_recent' => $failedRecent,
            'completed_recent' => max(0, (int) ($metrics['completed_recent'] ?? 0)),
            'oldest_active_seconds' => max(0, (int) ($metrics['oldest_active_seconds'] ?? 0)),
        ]);
    }

    public function evaluateBackup(array $metrics): array
    {
        if (($metrics['available'] ?? false) === false) {
            return $this->check('backup', 'warning', 'unavailable');
        }

        $enabled = (bool) ($metrics['enabled'] ?? false);
        $running = max(0, (int) ($metrics['running'] ?? 0));
        $latestStatus = trim((string) ($metrics['latest_status'] ?? ''));
        $latestFinishedAt = (int) ($metrics['latest_finished_at'] ?? 0);
        $now = (int) ($metrics['now'] ?? time());
        $ageSeconds = $latestFinishedAt > 0 ? max(0, $now - $latestFinishedAt) : null;
        $ready = (bool) ($metrics['metadata_ready'] ?? false)
            && (bool) ($metrics['backup_path_writable'] ?? false)
            && (bool) ($metrics['gzip_ready'] ?? false);

        if (!$enabled) {
            $status = 'warning';
            $reason = 'disabled';
        } elseif (!$ready) {
            $status = 'critical';
            $reason = 'not_ready';
        } elseif ($running > 0 || in_array($latestStatus, ['queued', 'running'], true)) {
            $status = 'healthy';
            $reason = 'running';
        } elseif ($latestStatus === '') {
            $status = 'warning';
            $reason = 'never_run';
        } elseif ($latestStatus === 'failed') {
            $status = 'critical';
            $reason = 'failed';
        } elseif ($ageSeconds === null || $ageSeconds >= 172800) {
            $status = 'critical';
            $reason = 'stale';
        } elseif ($ageSeconds >= 108000) {
            $status = 'warning';
            $reason = 'delayed';
        } else {
            $status = 'healthy';
            $reason = 'current';
        }

        return $this->check('backup', $status, $reason, [
            'enabled' => $enabled,
            'running' => $running,
            'latest_status' => $latestStatus !== '' ? $latestStatus : null,
            'latest_finished_at' => $latestFinishedAt ?: null,
            'age_seconds' => $ageSeconds,
            'metadata_ready' => (bool) ($metrics['metadata_ready'] ?? false),
            'backup_path_writable' => (bool) ($metrics['backup_path_writable'] ?? false),
            'gzip_ready' => (bool) ($metrics['gzip_ready'] ?? false),
        ]);
    }

    public function evaluateExternalServices(array $metrics): array
    {
        if (($metrics['available'] ?? false) === false) {
            return $this->check('external_services', 'warning', 'unavailable');
        }

        $domainsMonitored = max(0, (int) ($metrics['domains_monitored'] ?? 0));
        $domainWarning = max(0, (int) ($metrics['domain_warning'] ?? 0));
        $domainDown = max(0, (int) ($metrics['domain_down'] ?? 0));
        $domainUnknown = max(0, (int) ($metrics['domain_unknown'] ?? 0));
        $domainStale = max(0, (int) ($metrics['domain_stale'] ?? 0));
        $proxyEnabled = (bool) ($metrics['proxy_enabled'] ?? false);
        $proxyConfigured = max(0, (int) ($metrics['proxy_configured'] ?? 0));
        $proxyHealthy = max(0, (int) ($metrics['proxy_healthy'] ?? 0));

        if ($domainDown > 0 || ($proxyEnabled && $proxyConfigured > 0 && $proxyHealthy === 0)) {
            $status = 'critical';
            $reason = 'down';
        } elseif (
            $domainWarning > 0
            || $domainUnknown > 0
            || $domainStale > 0
            || ($proxyEnabled && ($proxyConfigured === 0 || $proxyHealthy < $proxyConfigured))
        ) {
            $status = 'warning';
            $reason = 'degraded';
        } elseif ($domainsMonitored === 0 && !$proxyEnabled) {
            $status = 'healthy';
            $reason = 'not_configured';
        } else {
            $status = 'healthy';
            $reason = 'healthy';
        }

        return $this->check('external_services', $status, $reason, [
            'domains_monitored' => $domainsMonitored,
            'domain_healthy' => max(0, (int) ($metrics['domain_healthy'] ?? 0)),
            'domain_warning' => $domainWarning,
            'domain_down' => $domainDown,
            'domain_unknown' => $domainUnknown,
            'domain_stale' => $domainStale,
            'domain_last_checked_at' => isset($metrics['domain_last_checked_at'])
                ? (int) $metrics['domain_last_checked_at']
                : null,
            'proxy_enabled' => $proxyEnabled,
            'proxy_configured' => $proxyConfigured,
            'proxy_healthy' => $proxyHealthy,
            'proxy_last_seen_at' => isset($metrics['proxy_last_seen_at'])
                ? (int) $metrics['proxy_last_seen_at']
                : null,
        ]);
    }

    private function check(string $key, string $status, string $reason, array $metrics = []): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'reason' => $reason,
            'metrics' => $metrics,
        ];
    }
}

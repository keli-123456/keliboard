<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiDiagnosticReport;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class AiDiagnosticService
{
    public function __construct(
        private AiDiagnosticMetricsService $metricsService,
        private AiDiagnosticAnalyzer $analyzer,
        private AiDiagnosticDispositionService $dispositionService,
        private AiDiagnosticIncidentService $incidentService,
    ) {}

    public function settings(): array
    {
        $minimumSeverity = (string) admin_setting('ai_diagnostics_minimum_alert_severity', 'critical');
        if (!in_array($minimumSeverity, ['warning', 'critical'], true)) {
            $minimumSeverity = 'critical';
        }

        return [
            'enabled' => (bool) admin_setting('ai_diagnostics_enabled', false),
            'schedule_enabled' => (bool) admin_setting('ai_diagnostics_schedule_enabled', false),
            'lookback_days' => max(3, min(30, (int) admin_setting('ai_diagnostics_lookback_days', 7))),
            'notify_telegram' => (bool) admin_setting('ai_diagnostics_notify_telegram', false),
            'notify_email' => (bool) admin_setting('ai_diagnostics_notify_email', false),
            'notification_email' => (string) admin_setting('ai_diagnostics_notification_email', ''),
            'minimum_alert_severity' => $minimumSeverity,
            'alert_cooldown_hours' => max(1, min(168, (int) admin_setting('ai_diagnostics_alert_cooldown_hours', 6))),
            'default_sla_hours' => max(1, min(720, (int) admin_setting('ai_diagnostics_default_sla_hours', 24))),
            'shadow_mode' => true,
            'read_only' => true,
            'external_data_sharing' => false,
            'schedule_interval' => 'hourly',
        ];
    }

    public function saveSettings(array $settings): array
    {
        $current = $this->settings();
        $minimumSeverity = (string) ($settings['minimum_alert_severity'] ?? $current['minimum_alert_severity']);
        if (!in_array($minimumSeverity, ['warning', 'critical'], true)) {
            $minimumSeverity = 'critical';
        }

        admin_setting([
            'ai_diagnostics_enabled' => !empty($settings['enabled']) ? 1 : 0,
            'ai_diagnostics_schedule_enabled' => !empty($settings['schedule_enabled']) ? 1 : 0,
            'ai_diagnostics_lookback_days' => max(3, min(30, (int) ($settings['lookback_days'] ?? 7))),
            'ai_diagnostics_notify_telegram' => array_key_exists('notify_telegram', $settings)
                ? (!empty($settings['notify_telegram']) ? 1 : 0)
                : ($current['notify_telegram'] ? 1 : 0),
            'ai_diagnostics_notify_email' => array_key_exists('notify_email', $settings)
                ? (!empty($settings['notify_email']) ? 1 : 0)
                : ($current['notify_email'] ? 1 : 0),
            'ai_diagnostics_notification_email' => trim((string) ($settings['notification_email'] ?? $current['notification_email'])),
            'ai_diagnostics_minimum_alert_severity' => $minimumSeverity,
            'ai_diagnostics_alert_cooldown_hours' => max(1, min(168, (int) ($settings['alert_cooldown_hours'] ?? $current['alert_cooldown_hours']))),
            'ai_diagnostics_default_sla_hours' => max(1, min(720, (int) ($settings['default_sla_hours'] ?? $current['default_sla_hours']))),
        ]);

        return $this->settings();
    }
    public function overview(string $scopeKey): array
    {
        $scope = $this->resolveScope($scopeKey);
        $report = null;
        if (Schema::hasTable('v2_ai_diagnostic_report')) {
            $report = AiDiagnosticReport::query()
                ->where('scope_key', $scope['key'])
                ->orderByDesc('generated_at')
                ->first();
        }

        return [
            'settings' => $this->settings(),
            'scope' => $scope,
            'scopes' => $this->scopes(),
            'report' => $report ? $this->payload($report) : null,
            'operations' => $this->incidentService->dashboard((string) $scope['key']),
        ];
    }

    public function run(string $scopeKey, string $generatedBy = 'manual', ?int $adminId = null, bool $force = false): AiDiagnosticReport
    {
        if (!Schema::hasTable('v2_ai_diagnostic_report')) {
            throw new RuntimeException('AI diagnostics migration is not installed');
        }
        if (!$force && !$this->settings()['enabled']) {
            throw new RuntimeException('AI diagnostics is disabled');
        }

        $scope = $this->resolveScope($scopeKey);
        $settings = $this->settings();
        $metrics = $this->metricsService->collect(
            $scope['site_id'],
            (int) $settings['lookback_days'],
            $scope['type'] === 'platform'
        );
        $analysis = $this->analyzer->analyze($metrics);

        $report = AiDiagnosticReport::query()->create([
            'scope_key' => $scope['key'],
            'scope_type' => $scope['type'],
            'site_id' => $scope['site_id'],
            'status' => $analysis['status'],
            'score' => $analysis['score'],
            'summary' => $analysis['summary'],
            'metrics' => $metrics,
            'findings' => $analysis['findings'],
            'ai_summary' => null,
            'ai_status' => 'local_rules',
            'generated_by' => $generatedBy,
            'admin_id' => $adminId,
            'generated_at' => time(),
        ]);
        $this->incidentService->syncReport($report);

        return $report;
    }

    public function runScheduled(): array
    {
        $settings = $this->settings();
        if (!$settings['enabled'] || !$settings['schedule_enabled']) {
            return ['enabled' => false, 'generated' => 0, 'failed' => 0];
        }

        $generated = 0;
        $failed = 0;
        foreach ($this->scopes() as $scope) {
            try {
                $this->run((string) $scope['key'], 'schedule');
                $generated++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        return ['enabled' => true, 'generated' => $generated, 'failed' => $failed];
    }

    public function payload(AiDiagnosticReport $report): array
    {
        $rawFindings = array_values((array) ($report->findings ?? []));
        $dispositions = $this->dispositionService->forReport($report);
        $incidents = $this->incidentService->forReport($report);
        $findings = [];
        foreach ($rawFindings as $index => $finding) {
            $finding['disposition'] = $dispositions[$index];
            $finding['incident'] = $incidents[$index] ?? null;
            $findings[] = $finding;
        }

        return [
            'id' => (int) $report->id,
            'scope_key' => (string) $report->scope_key,
            'scope_type' => (string) $report->scope_type,
            'site_id' => $report->site_id !== null ? (int) $report->site_id : null,
            'status' => (string) $report->status,
            'score' => (int) $report->score,
            'summary' => (array) ($report->summary ?? []),
            'metrics' => (array) ($report->metrics ?? []),
            'findings' => $findings,
            'generated_by' => (string) $report->generated_by,
            'generated_at' => (int) $report->generated_at,
        ];
    }

    public function scopes(): array
    {
        $scopes = [
            ['key' => 'platform', 'type' => 'platform', 'site_id' => null, 'label' => '全部非代理业务'],
            ['key' => 'site:0', 'type' => 'site', 'site_id' => null, 'label' => '主站'],
        ];
        if (!Schema::hasTable('v2_site')) {
            return $scopes;
        }

        foreach (Site::query()->orderBy('id')->get(['id', 'name', 'code']) as $site) {
            $scopes[] = [
                'key' => 'site:' . (int) $site->id,
                'type' => 'site',
                'site_id' => (int) $site->id,
                'label' => (string) ($site->name ?: $site->code),
            ];
        }

        return $scopes;
    }

    private function resolveScope(string $scopeKey): array
    {
        foreach ($this->scopes() as $scope) {
            if ($scope['key'] === $scopeKey) {
                return $scope;
            }
        }

        throw new RuntimeException('Invalid diagnostic scope');
    }
}





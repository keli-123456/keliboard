<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiDiagnosticIncident;
use App\Models\AiDiagnosticIncidentLog;
use App\Models\AiDiagnosticReport;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class AiDiagnosticIncidentService
{
    public function __construct(private ?AiDiagnosticNotificationService $notificationService = null) {}

    public function syncReport(AiDiagnosticReport $report): array
    {
        if (!$this->available()) {
            return ['created' => 0, 'updated' => 0, 'recovered' => 0, 'events' => []];
        }

        $result = DB::transaction(function () use ($report): array {
            $created = 0;
            $updated = 0;
            $events = [];
            $fingerprints = [];

            foreach (array_values((array) $report->findings) as $finding) {
                $fingerprint = $this->fingerprint($report, $finding);
                $fingerprints[] = $fingerprint;
                $incident = AiDiagnosticIncident::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
                $event = null;
                if (!$incident) {
                    $incident = AiDiagnosticIncident::query()->create($this->newIncidentData($report, $finding, $fingerprint));
                    $created++;
                    $event = 'created';
                    $this->log($incident, 'created', null, AiDiagnosticIncident::STATUS_OPEN, null, [
                        'report_id' => (int) $report->id,
                    ]);
                } else {
                    $previousSeverity = (string) $incident->severity;
                    $previousStatus = (string) $incident->status;
                    $updates = [
                        'last_report_id' => (int) $report->id,
                        'last_seen_at' => (int) $report->generated_at,
                        'occurrence_count' => (int) $incident->occurrence_count + 1,
                        'latest_evidence' => (array) ($finding['evidence'] ?? []),
                    ];
                    if ($this->severityWeight((string) ($finding['severity'] ?? 'warning')) > $this->severityWeight($previousSeverity)) {
                        $updates['severity'] = (string) $finding['severity'];
                        $event = 'escalated';
                    }
                    if (in_array($previousStatus, [AiDiagnosticIncident::STATUS_RESOLVED, AiDiagnosticIncident::STATUS_RECOVERED], true)) {
                        $updates['status'] = $incident->assignee_id ? AiDiagnosticIncident::STATUS_ASSIGNED : AiDiagnosticIncident::STATUS_OPEN;
                        $updates['resolved_at'] = null;
                        $updates['recurrence_count'] = (int) $incident->recurrence_count + 1;
                        $event = 'recurrence';
                    }
                    $incident->forceFill($updates)->save();
                    $updated++;
                    if ($event) {
                        $this->log($incident, $event, $previousStatus, (string) $incident->status, null, [
                            'report_id' => (int) $report->id,
                            'previous_severity' => $previousSeverity,
                            'severity' => (string) $incident->severity,
                        ]);
                    }
                }
                if ($event) {
                    $events[] = ['incident' => $incident, 'event' => $event];
                }
            }

            $recoverable = AiDiagnosticIncident::query()
                ->where('scope_key', $report->scope_key)
                ->whereIn('status', AiDiagnosticIncident::ACTIVE_STATUSES)
                ->when($fingerprints !== [], fn ($query) => $query->whereNotIn('fingerprint', $fingerprints))
                ->lockForUpdate()
                ->get();
            foreach ($recoverable as $incident) {
                $from = (string) $incident->status;
                $incident->forceFill([
                    'status' => AiDiagnosticIncident::STATUS_RECOVERED,
                    'resolved_at' => (int) $report->generated_at,
                ])->save();
                $this->log($incident, 'recovered', $from, AiDiagnosticIncident::STATUS_RECOVERED, null, [
                    'report_id' => (int) $report->id,
                ]);
                $events[] = ['incident' => $incident, 'event' => 'recovered'];
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'recovered' => $recoverable->count(),
                'events' => $events,
            ];
        });

        if ($this->notificationService) {
            foreach ($result['events'] as $item) {
                $this->notificationService->dispatch($item['incident'], $item['event']);
            }
        }
        $result['events'] = array_map(static fn (array $item): array => [
            'incident_id' => (int) $item['incident']->id,
            'event' => $item['event'],
        ], $result['events']);

        return $result;
    }

    public function dashboard(string $scopeKey): array
    {
        if (!$this->available()) {
            return $this->emptyDashboard();
        }

        $query = AiDiagnosticIncident::query()->where('scope_key', $scopeKey);
        $active = (clone $query)->whereIn('status', AiDiagnosticIncident::ACTIVE_STATUSES);
        $incidents = (clone $query)
            ->with('assignee:id,email')
            ->orderByRaw("CASE WHEN status IN ('open','assigned') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get()
            ->map(fn (AiDiagnosticIncident $incident): array => $this->payload($incident))
            ->all();

        return [
            'summary' => [
                'open' => (clone $active)->count(),
                'assigned' => (clone $active)->whereNotNull('assignee_id')->count(),
                'overdue' => (clone $active)->whereNotNull('due_at')->where('due_at', '<', time())->count(),
                'recurrent' => (clone $query)->where('recurrence_count', '>', 0)->count(),
                'resolved' => (clone $query)->whereIn('status', [AiDiagnosticIncident::STATUS_RESOLVED, AiDiagnosticIncident::STATUS_RECOVERED])->count(),
                'false_positive' => (clone $query)->where('status', AiDiagnosticIncident::STATUS_FALSE_POSITIVE)->count(),
            ],
            'incidents' => $incidents,
            'operators' => $this->operators(),
        ];
    }

    public function forReport(AiDiagnosticReport $report): array
    {
        $findings = array_values((array) $report->findings);
        if (!$this->available() || $findings === []) {
            return array_fill(0, count($findings), null);
        }
        $fingerprints = array_map(fn (array $finding): string => $this->fingerprint($report, $finding), $findings);
        $rows = AiDiagnosticIncident::query()->whereIn('fingerprint', $fingerprints)->with('assignee:id,email')->get()->keyBy('fingerprint');

        return array_map(fn (string $fingerprint): ?array => isset($rows[$fingerprint]) ? $this->payload($rows[$fingerprint]) : null, $fingerprints);
    }

    public function forFinding(AiDiagnosticReport $report, array $finding, bool $withLogs = false): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $incident = AiDiagnosticIncident::query()
            ->where('fingerprint', $this->fingerprint($report, $finding))
            ->with('assignee:id,email')
            ->first();
        if (!$incident) {
            return null;
        }
        $payload = $this->payload($incident);
        if ($withLogs) {
            $payload['logs'] = $incident->logs()->with('admin:id,email')->orderByDesc('created_at')->limit(50)->get()->map(static fn (AiDiagnosticIncidentLog $log): array => [
                'id' => (int) $log->id,
                'action' => (string) $log->action,
                'from_status' => $log->from_status !== null ? (string) $log->from_status : null,
                'to_status' => $log->to_status !== null ? (string) $log->to_status : null,
                'admin_id' => $log->admin_id !== null ? (int) $log->admin_id : null,
                'admin_email' => $log->admin?->email,
                'note' => $log->note,
                'metadata' => (array) ($log->metadata ?? []),
                'created_at' => (int) $log->created_at,
            ])->all();
        }

        return $payload;
    }

    public function update(int $incidentId, array $data, ?int $adminId): array
    {
        if (!$this->available()) {
            throw new RuntimeException('AI diagnostic incident migration is not installed');
        }
        $incident = AiDiagnosticIncident::query()->findOrFail($incidentId);
        $from = (string) $incident->status;
        $status = (string) ($data['status'] ?? $from);
        if (!in_array($status, [AiDiagnosticIncident::STATUS_OPEN, AiDiagnosticIncident::STATUS_RESOLVED, AiDiagnosticIncident::STATUS_FALSE_POSITIVE, AiDiagnosticIncident::STATUS_IGNORED], true)) {
            throw new RuntimeException('Invalid incident status');
        }
        $assigneeId = isset($data['assignee_id']) && (int) $data['assignee_id'] > 0 ? (int) $data['assignee_id'] : null;
        if ($assigneeId !== null && !$this->operatorExists($assigneeId)) {
            throw new RuntimeException('Invalid incident assignee');
        }
        if ($status === AiDiagnosticIncident::STATUS_OPEN && $assigneeId !== null) {
            $status = AiDiagnosticIncident::STATUS_ASSIGNED;
        }
        $dueAt = isset($data['due_at']) && (int) $data['due_at'] > 0 ? (int) $data['due_at'] : null;
        $note = isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null;

        $incident->forceFill([
            'status' => $status,
            'assignee_id' => $assigneeId,
            'due_at' => $dueAt,
            'resolved_at' => in_array($status, [AiDiagnosticIncident::STATUS_RESOLVED, AiDiagnosticIncident::STATUS_FALSE_POSITIVE, AiDiagnosticIncident::STATUS_IGNORED], true) ? time() : null,
            'last_note' => $note,
        ])->save();
        $this->log($incident, 'manual_update', $from, $status, $adminId, [
            'assignee_id' => $assigneeId,
            'due_at' => $dueAt,
        ], $note);

        return $this->payload($incident->fresh(['assignee:id,email']));
    }

    public function assignedTo(int $operatorId): array
    {
        if (!$this->available()) {
            return ['summary' => ['open' => 0, 'overdue' => 0], 'incidents' => []];
        }
        $query = AiDiagnosticIncident::query()
            ->where('assignee_id', $operatorId)
            ->whereIn('status', AiDiagnosticIncident::ACTIVE_STATUSES);

        return [
            'summary' => [
                'open' => (clone $query)->count(),
                'overdue' => (clone $query)->whereNotNull('due_at')->where('due_at', '<', time())->count(),
            ],
            'incidents' => $query->with('assignee:id,email')
                ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")
                ->orderBy('due_at')
                ->orderByDesc('last_seen_at')
                ->limit(100)
                ->get()
                ->map(fn (AiDiagnosticIncident $incident): array => $this->payload($incident))
                ->all(),
        ];
    }

    public function updateAssigned(int $incidentId, int $operatorId, string $status, ?string $note): array
    {
        if (!$this->available()) {
            throw new RuntimeException('AI diagnostic incident migration is not installed');
        }
        if (!in_array($status, [AiDiagnosticIncident::STATUS_ASSIGNED, AiDiagnosticIncident::STATUS_RESOLVED], true)) {
            throw new RuntimeException('Invalid assigned incident status');
        }
        $incident = AiDiagnosticIncident::query()
            ->whereKey($incidentId)
            ->where('assignee_id', $operatorId)
            ->firstOrFail();
        $from = (string) $incident->status;
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $incident->forceFill([
            'status' => $status,
            'resolved_at' => $status === AiDiagnosticIncident::STATUS_RESOLVED ? time() : null,
            'last_note' => $note,
        ])->save();
        $this->log($incident, 'staff_update', $from, $status, $operatorId, [], $note);

        return $this->payload($incident->fresh(['assignee:id,email']));
    }
    public function applyDisposition(AiDiagnosticReport $report, string $findingKey, string $status, ?string $note, ?int $adminId): void
    {
        if (!$this->available()) {
            return;
        }
        foreach ((array) $report->findings as $finding) {
            if ((string) ($finding['key'] ?? '') !== $findingKey) {
                continue;
            }
            $incident = AiDiagnosticIncident::query()->where('fingerprint', $this->fingerprint($report, (array) $finding))->first();
            if (!$incident) {
                return;
            }
            $this->update((int) $incident->id, [
                'status' => $status,
                'assignee_id' => $incident->assignee_id,
                'due_at' => $incident->due_at,
                'note' => $note,
            ], $adminId);
            return;
        }
    }

    private function newIncidentData(AiDiagnosticReport $report, array $finding, string $fingerprint): array
    {
        $slaHours = max(1, min(720, (int) admin_setting('ai_diagnostics_default_sla_hours', 24)));
        return [
            'fingerprint' => $fingerprint,
            'scope_key' => (string) $report->scope_key,
            'scope_type' => (string) $report->scope_type,
            'site_id' => $report->site_id,
            'finding_key' => (string) ($finding['key'] ?? ''),
            'module' => (string) ($finding['module'] ?? 'business'),
            'severity' => (string) ($finding['severity'] ?? 'warning'),
            'subject_id' => (int) data_get($finding, 'evidence.subject_id', 0),
            'status' => AiDiagnosticIncident::STATUS_OPEN,
            'first_report_id' => (int) $report->id,
            'last_report_id' => (int) $report->id,
            'occurrence_count' => 1,
            'recurrence_count' => 0,
            'due_at' => (int) $report->generated_at + ($slaHours * 3600),
            'first_seen_at' => (int) $report->generated_at,
            'last_seen_at' => (int) $report->generated_at,
            'latest_evidence' => (array) ($finding['evidence'] ?? []),
        ];
    }

    private function fingerprint(AiDiagnosticReport $report, array $finding): string
    {
        return hash('sha256', implode('|', [
            (string) $report->scope_key,
            (string) ($finding['key'] ?? ''),
            (int) data_get($finding, 'evidence.subject_id', 0),
        ]));
    }

    private function payload(AiDiagnosticIncident $incident): array
    {
        return [
            'id' => (int) $incident->id,
            'scope_key' => (string) $incident->scope_key,
            'scope_label' => $this->scopeLabel($incident),
            'site_id' => $incident->site_id !== null ? (int) $incident->site_id : null,
            'finding_key' => (string) $incident->finding_key,
            'module' => (string) $incident->module,
            'severity' => (string) $incident->severity,
            'subject_id' => (int) $incident->subject_id,
            'status' => (string) $incident->status,
            'first_report_id' => (int) $incident->first_report_id,
            'last_report_id' => (int) $incident->last_report_id,
            'occurrence_count' => (int) $incident->occurrence_count,
            'recurrence_count' => (int) $incident->recurrence_count,
            'assignee_id' => $incident->assignee_id !== null ? (int) $incident->assignee_id : null,
            'assignee_email' => $incident->assignee?->email,
            'due_at' => $incident->due_at !== null ? (int) $incident->due_at : null,
            'overdue' => in_array((string) $incident->status, AiDiagnosticIncident::ACTIVE_STATUSES, true) && $incident->due_at !== null && (int) $incident->due_at < time(),
            'first_seen_at' => (int) $incident->first_seen_at,
            'last_seen_at' => (int) $incident->last_seen_at,
            'resolved_at' => $incident->resolved_at !== null ? (int) $incident->resolved_at : null,
            'last_notified_at' => $incident->last_notified_at !== null ? (int) $incident->last_notified_at : null,
            'last_notification_channels' => (array) ($incident->last_notification_channels ?? []),
            'last_notification_error' => $incident->last_notification_error,
            'last_note' => $incident->last_note,
            'latest_evidence' => (array) ($incident->latest_evidence ?? []),
        ];
    }

    private function operators(): array
    {
        return User::query()
            ->where('banned', false)
            ->where(fn ($query) => $query->where('is_admin', true)->orWhere('is_staff', true))
            ->orderByDesc('is_admin')
            ->orderBy('email')
            ->get(['id', 'email', 'is_admin', 'is_staff'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'email' => (string) $user->email,
                'role' => $user->is_admin ? 'admin' : 'staff',
            ])->all();
    }

    private function operatorExists(int $id): bool
    {
        return User::query()->whereKey($id)->where('banned', false)->where(fn ($query) => $query->where('is_admin', true)->orWhere('is_staff', true))->exists();
    }

    private function scopeLabel(AiDiagnosticIncident $incident): string
    {
        if ($incident->scope_key === 'platform') {
            return '全部非代理业务';
        }
        if ($incident->site_id === null) {
            return '主站';
        }
        $site = Schema::hasTable('v2_site') ? Site::query()->find((int) $incident->site_id) : null;
        return (string) ($site?->name ?: $site?->code ?: ('分站 #' . (int) $incident->site_id));
    }

    private function log(AiDiagnosticIncident $incident, string $action, ?string $from, ?string $to, ?int $adminId, array $metadata = [], ?string $note = null): void
    {
        AiDiagnosticIncidentLog::query()->create([
            'incident_id' => (int) $incident->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'admin_id' => $adminId,
            'note' => $note,
            'metadata' => $metadata,
        ]);
    }

    private function severityWeight(string $severity): int
    {
        return $severity === 'critical' ? 2 : ($severity === 'warning' ? 1 : 0);
    }

    private function available(): bool
    {
        return Schema::hasTable('v2_ai_diagnostic_incident') && Schema::hasTable('v2_ai_diagnostic_incident_log');
    }

    private function emptyDashboard(): array
    {
        return [
            'summary' => ['open' => 0, 'assigned' => 0, 'overdue' => 0, 'recurrent' => 0, 'resolved' => 0, 'false_positive' => 0],
            'incidents' => [],
            'operators' => [],
        ];
    }
}


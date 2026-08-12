<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiDiagnosticReport;
use App\Services\AiDiagnosticDispositionService;
use App\Services\AiDiagnosticEvidenceService;
use App\Services\AiDiagnosticIncidentService;
use App\Services\AiDiagnosticService;
use Illuminate\Http\Request;
use Throwable;

class AiDiagnosticController extends Controller
{
    public function overview(Request $request, AiDiagnosticService $service)
    {
        $data = $request->validate(['scope_key' => 'nullable|string|max:64']);

        try {
            return $this->success($service->overview((string) ($data['scope_key'] ?? 'platform')));
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }

    public function saveSettings(Request $request, AiDiagnosticService $service)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'schedule_enabled' => 'required|boolean',
            'lookback_days' => 'required|integer|min:3|max:30',
            'notify_telegram' => 'nullable|boolean',
            'notify_email' => 'nullable|boolean',
            'notification_email' => 'nullable|email|max:191',
            'minimum_alert_severity' => 'nullable|in:warning,critical',
            'alert_cooldown_hours' => 'nullable|integer|min:1|max:168',
            'default_sla_hours' => 'nullable|integer|min:1|max:720',
        ]);

        return $this->success($service->saveSettings($data));
    }

    public function run(Request $request, AiDiagnosticService $service)
    {
        $data = $request->validate(['scope_key' => 'required|string|max:64']);

        try {
            $report = $service->run(
                (string) $data['scope_key'],
                'manual',
                (int) ($request->user()?->id ?? 0) ?: null,
            );

            return $this->success($service->payload($report));
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }

    public function history(Request $request, AiDiagnosticService $service, AiDiagnosticEvidenceService $evidenceService)
    {
        $data = $request->validate([
            'scope_key' => 'required|string|max:64',
            'days' => 'nullable|integer|min:1|max:90',
        ]);

        try {
            $service->overview((string) $data['scope_key']);

            return $this->success($evidenceService->history(
                (string) $data['scope_key'],
                (int) ($data['days'] ?? 30)
            ));
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }

    public function detail(
        Request $request,
        AiDiagnosticService $service,
        AiDiagnosticEvidenceService $evidenceService,
        AiDiagnosticDispositionService $dispositionService,
        AiDiagnosticIncidentService $incidentService
    ) {
        $data = $request->validate([
            'report_id' => 'required|integer|min:1',
            'finding_key' => 'required|string|max:96',
        ]);

        try {
            $report = AiDiagnosticReport::query()->findOrFail((int) $data['report_id']);
            $detail = $evidenceService->detail($report, (string) $data['finding_key']);
            $detail['disposition'] = $dispositionService->forFinding($report, (array) $detail['finding']);
            $detail['incident'] = $incidentService->forFinding($report, (array) $detail['finding'], true);
            $detail['report'] = $service->payload($report);

            return $this->success($detail);
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }

    public function saveDisposition(Request $request, AiDiagnosticDispositionService $dispositionService, AiDiagnosticIncidentService $incidentService)
    {
        $data = $request->validate([
            'report_id' => 'required|integer|min:1',
            'finding_key' => 'required|string|max:96',
            'status' => 'required|in:open,resolved,false_positive,ignored',
            'note' => 'nullable|string|max:2000',
            'cooldown_hours' => 'nullable|integer|min:1|max:720',
        ]);

        try {
            $report = AiDiagnosticReport::query()->findOrFail((int) $data['report_id']);

            $adminId = (int) ($request->user()?->id ?? 0) ?: null;
            $disposition = $dispositionService->update(
                $report,
                (string) $data['finding_key'],
                (string) $data['status'],
                $data['note'] ?? null,
                isset($data['cooldown_hours']) ? (int) $data['cooldown_hours'] : null,
                $adminId,
            );
            $incidentService->applyDisposition(
                $report,
                (string) $data['finding_key'],
                (string) $data['status'],
                $data['note'] ?? null,
                $adminId,
            );

            return $this->success($disposition);
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }

    public function updateIncident(
        Request $request,
        AiDiagnosticIncidentService $incidentService,
        AiDiagnosticDispositionService $dispositionService
    )
    {
        $data = $request->validate([
            'incident_id' => 'required|integer|min:1',
            'status' => 'required|in:open,resolved,false_positive,ignored',
            'assignee_id' => 'nullable|integer|min:1',
            'due_at' => 'nullable|integer|min:1',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $adminId = (int) ($request->user()?->id ?? 0) ?: null;
            $incident = $incidentService->update(
                (int) $data['incident_id'],
                $data,
                $adminId,
            );
            $report = AiDiagnosticReport::query()->find((int) $incident['last_report_id']);
            if ($report) {
                $dispositionService->update(
                    $report,
                    (string) $incident['finding_key'],
                    (string) $data['status'],
                    $data['note'] ?? null,
                    null,
                    $adminId,
                );
            }

            return $this->success($incident);
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }
}


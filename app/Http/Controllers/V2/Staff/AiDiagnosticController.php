<?php

namespace App\Http\Controllers\V2\Staff;

use App\Http\Controllers\Controller;
use App\Models\AiDiagnosticReport;
use App\Services\AiDiagnosticDispositionService;
use App\Services\AiDiagnosticIncidentService;
use Illuminate\Http\Request;
use Throwable;

class AiDiagnosticController extends Controller
{
    public function assigned(Request $request, AiDiagnosticIncidentService $service)
    {
        try {
            return $this->success($service->assignedTo((int) $request->user()->id));
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }

    public function update(
        Request $request,
        AiDiagnosticIncidentService $service,
        AiDiagnosticDispositionService $dispositionService
    )
    {
        $data = $request->validate([
            'incident_id' => 'required|integer|min:1',
            'status' => 'required|in:assigned,resolved',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $operatorId = (int) $request->user()->id;
            $incident = $service->updateAssigned(
                (int) $data['incident_id'],
                $operatorId,
                (string) $data['status'],
                $data['note'] ?? null,
            );
            if ((string) $data['status'] === 'resolved') {
                $report = AiDiagnosticReport::query()->find((int) $incident['last_report_id']);
                if ($report) {
                    $dispositionService->update(
                        $report,
                        (string) $incident['finding_key'],
                        'resolved',
                        $data['note'] ?? null,
                        null,
                        $operatorId,
                    );
                }
            }

            return $this->success($incident);
        } catch (Throwable $exception) {
            return $this->fail([500001, $exception->getMessage()]);
        }
    }
}


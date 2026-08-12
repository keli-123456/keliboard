<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiDiagnosticDisposition;
use App\Models\AiDiagnosticReport;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class AiDiagnosticDispositionService
{
    public function forReport(AiDiagnosticReport $report): array
    {
        $findings = array_values((array) $report->findings);
        if (!Schema::hasTable('v2_ai_diagnostic_disposition') || $findings === []) {
            return array_map(
                fn (array $finding): array => $this->openPayload((int) data_get($finding, 'evidence.subject_id', 0)),
                $findings
            );
        }

        $keys = array_values(array_unique(array_map(
            static fn (array $finding): string => (string) ($finding['key'] ?? ''),
            $findings
        )));
        $rows = AiDiagnosticDisposition::query()
            ->whereIn('finding_key', $keys)
            ->where(function ($query) use ($report): void {
                $query->where('report_id', $report->id)
                    ->orWhere(function ($cooling) use ($report): void {
                        $cooling->where('scope_key', $report->scope_key)
                            ->where('status', AiDiagnosticDisposition::STATUS_IGNORED)
                            ->where('cooling_until', '>', time());
                    });
            })
            ->orderByDesc('updated_at')
            ->get();

        return array_map(function (array $finding) use ($report, $rows): array {
            $key = (string) ($finding['key'] ?? '');
            $subjectId = (int) data_get($finding, 'evidence.subject_id', 0);
            $exact = $rows->first(fn (AiDiagnosticDisposition $row): bool =>
                (int) $row->report_id === (int) $report->id
                && $row->finding_key === $key
                && (int) $row->subject_id === $subjectId
            );
            if ($exact) {
                return $this->payload($exact, false);
            }

            $cooling = $rows->first(fn (AiDiagnosticDisposition $row): bool =>
                $row->scope_key === $report->scope_key
                && $row->finding_key === $key
                && (int) $row->subject_id === $subjectId
                && $row->status === AiDiagnosticDisposition::STATUS_IGNORED
                && (int) $row->cooling_until > time()
            );

            return $cooling ? $this->payload($cooling, true) : $this->openPayload($subjectId);
        }, $findings);
    }

    public function forFinding(AiDiagnosticReport $report, array $finding): array
    {
        $subjectId = (int) data_get($finding, 'evidence.subject_id', 0);
        if (!Schema::hasTable('v2_ai_diagnostic_disposition')) {
            return $this->openPayload($subjectId);
        }

        $exact = AiDiagnosticDisposition::query()
            ->where('report_id', $report->id)
            ->where('finding_key', (string) ($finding['key'] ?? ''))
            ->where('subject_id', $subjectId)
            ->first();
        if ($exact) {
            return $this->payload($exact, false);
        }

        $cooling = AiDiagnosticDisposition::query()
            ->where('scope_key', $report->scope_key)
            ->where('finding_key', (string) ($finding['key'] ?? ''))
            ->where('subject_id', $subjectId)
            ->where('status', AiDiagnosticDisposition::STATUS_IGNORED)
            ->where('cooling_until', '>', time())
            ->orderByDesc('updated_at')
            ->first();

        return $cooling ? $this->payload($cooling, true) : $this->openPayload($subjectId);
    }

    public function update(
        AiDiagnosticReport $report,
        string $findingKey,
        string $status,
        ?string $note,
        ?int $cooldownHours,
        ?int $adminId
    ): array {
        if (!Schema::hasTable('v2_ai_diagnostic_disposition')) {
            throw new RuntimeException('AI diagnostic disposition migration is not installed');
        }

        $finding = $this->finding($report, $findingKey);
        if (!in_array($status, AiDiagnosticDisposition::STATUSES, true)) {
            throw new RuntimeException('Invalid diagnostic disposition status');
        }

        $subjectId = (int) data_get($finding, 'evidence.subject_id', 0);
        $coolingUntil = null;
        if ($status === AiDiagnosticDisposition::STATUS_IGNORED) {
            $hours = max(1, min(720, (int) ($cooldownHours ?: 24)));
            $coolingUntil = time() + ($hours * 3600);
        }

        $disposition = AiDiagnosticDisposition::query()->updateOrCreate(
            [
                'report_id' => (int) $report->id,
                'finding_key' => $findingKey,
                'subject_id' => $subjectId,
            ],
            [
                'scope_key' => (string) $report->scope_key,
                'status' => $status,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'cooling_until' => $coolingUntil,
                'admin_id' => $adminId,
            ]
        );

        return $this->payload($disposition, false);
    }

    private function finding(AiDiagnosticReport $report, string $findingKey): array
    {
        foreach ((array) $report->findings as $finding) {
            if ((string) ($finding['key'] ?? '') === $findingKey) {
                return (array) $finding;
            }
        }

        throw new RuntimeException('Diagnostic finding was not found in this report');
    }

    private function openPayload(int $subjectId): array
    {
        return [
            'id' => null,
            'status' => AiDiagnosticDisposition::STATUS_OPEN,
            'note' => null,
            'subject_id' => $subjectId,
            'cooling_until' => null,
            'admin_id' => null,
            'updated_at' => null,
            'inherited' => false,
        ];
    }

    private function payload(AiDiagnosticDisposition $disposition, bool $inherited): array
    {
        return [
            'id' => (int) $disposition->id,
            'status' => (string) $disposition->status,
            'note' => $disposition->note !== null ? (string) $disposition->note : null,
            'subject_id' => (int) $disposition->subject_id,
            'cooling_until' => $disposition->cooling_until !== null ? (int) $disposition->cooling_until : null,
            'admin_id' => $disposition->admin_id !== null ? (int) $disposition->admin_id : null,
            'updated_at' => $disposition->updated_at !== null ? (int) $disposition->updated_at : null,
            'inherited' => $inherited,
        ];
    }
}



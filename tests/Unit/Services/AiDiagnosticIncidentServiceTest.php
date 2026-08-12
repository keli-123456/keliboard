<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AiDiagnosticIncident;
use App\Models\AiDiagnosticIncidentLog;
use App\Models\AiDiagnosticReport;
use App\Models\User;
use App\Services\AiDiagnosticIncidentService;
use App\Services\AiDiagnosticNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AiDiagnosticIncidentServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private AiDiagnosticIncidentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createTables();
        $this->service = new AiDiagnosticIncidentService(null);
    }

    public function test_same_finding_is_merged_then_recovers_and_reopens_as_recurrence(): void
    {
        $first = $this->report(1000, [$this->finding()]);
        $created = $this->service->syncReport($first);

        $this->assertSame(1, $created['created']);
        $this->assertSame(1, AiDiagnosticIncident::query()->count());

        $second = $this->report(2000, [$this->finding()]);
        $updated = $this->service->syncReport($second);
        $incident = AiDiagnosticIncident::query()->firstOrFail();

        $this->assertSame(1, $updated['updated']);
        $this->assertSame(2, $incident->occurrence_count);
        $this->assertSame(AiDiagnosticIncident::STATUS_OPEN, $incident->status);

        $healthy = $this->report(3000, []);
        $recovered = $this->service->syncReport($healthy);
        $incident->refresh();

        $this->assertSame(1, $recovered['recovered']);
        $this->assertSame(AiDiagnosticIncident::STATUS_RECOVERED, $incident->status);

        $recurrence = $this->report(4000, [$this->finding()]);
        $result = $this->service->syncReport($recurrence);
        $incident->refresh();

        $this->assertSame(1, $incident->recurrence_count);
        $this->assertSame(3, $incident->occurrence_count);
        $this->assertSame(AiDiagnosticIncident::STATUS_OPEN, $incident->status);
        $this->assertContains('recurrence', array_column($result['events'], 'event'));
        $this->assertSame(1, AiDiagnosticIncident::query()->count());
    }

    public function test_assignment_is_visible_only_to_the_assigned_operator(): void
    {
        $first = $this->report(1000, [$this->finding()]);
        $this->service->syncReport($first);
        $incident = AiDiagnosticIncident::query()->firstOrFail();
        $staff = $this->user('staff@example.test', true);
        $other = $this->user('other@example.test', true);

        $updated = $this->service->update((int) $incident->id, [
            'status' => 'open',
            'assignee_id' => $staff->id,
            'due_at' => time() + 3600,
            'note' => 'Please review',
        ], 99);

        $this->assertSame(AiDiagnosticIncident::STATUS_ASSIGNED, $updated['status']);
        $this->assertSame($staff->id, $updated['assignee_id']);
        $this->assertCount(1, $this->service->assignedTo((int) $staff->id)['incidents']);
        $this->assertCount(0, $this->service->assignedTo((int) $other->id)['incidents']);

        $this->expectException(ModelNotFoundException::class);
        $this->service->updateAssigned((int) $incident->id, (int) $other->id, 'resolved', 'Should be denied');
    }

    public function test_staff_progress_and_resolution_are_audited(): void
    {
        $this->service->syncReport($this->report(1000, [$this->finding()]));
        $incident = AiDiagnosticIncident::query()->firstOrFail();
        $staff = $this->user('staff@example.test', true);
        $this->service->update((int) $incident->id, [
            'status' => 'open',
            'assignee_id' => $staff->id,
            'due_at' => time() + 3600,
        ], 1);

        $this->service->updateAssigned((int) $incident->id, (int) $staff->id, 'assigned', 'Investigating');
        $resolved = $this->service->updateAssigned((int) $incident->id, (int) $staff->id, 'resolved', 'Recovered');

        $this->assertSame(AiDiagnosticIncident::STATUS_RESOLVED, $resolved['status']);
        $this->assertSame('Recovered', $resolved['last_note']);
        $this->assertGreaterThanOrEqual(3, AiDiagnosticIncidentLog::query()->count());
        $this->assertTrue(AiDiagnosticIncidentLog::query()->where('action', 'staff_update')->where('note', 'Recovered')->exists());
    }

    public function test_notifications_do_not_duplicate_platform_rollups_or_recurrences_in_cooldown(): void
    {
        $notification = new AiDiagnosticNotificationService();
        $this->service->syncReport($this->report(1000, [$this->finding()]));
        $platformIncident = AiDiagnosticIncident::query()->firstOrFail();

        $platform = $notification->dispatch($platformIncident, 'created');

        $this->assertFalse($platform['notified']);
        $this->assertNull($platformIncident->fresh()->last_notified_at);

        $siteIncident = AiDiagnosticIncident::query()->create([
            'fingerprint' => hash('sha256', 'site:0|payment_success_low|0'),
            'scope_key' => 'site:0',
            'scope_type' => 'site',
            'site_id' => null,
            'finding_key' => 'payment_success_low',
            'module' => 'payment',
            'severity' => 'critical',
            'subject_id' => 0,
            'status' => AiDiagnosticIncident::STATUS_OPEN,
            'first_report_id' => $platformIncident->first_report_id,
            'last_report_id' => $platformIncident->last_report_id,
            'occurrence_count' => 1,
            'recurrence_count' => 0,
            'first_seen_at' => 1000,
            'last_seen_at' => 1000,
            'latest_evidence' => [],
        ]);

        $created = $notification->dispatch($siteIncident, 'created');
        $recurrence = $notification->dispatch($siteIncident->fresh(), 'recurrence');

        $this->assertTrue($created['notified']);
        $this->assertSame(['panel'], $created['channels']);
        $this->assertNotNull($siteIncident->fresh()->last_notified_at);
        $this->assertFalse($recurrence['notified']);
        $this->assertSame(2, AiDiagnosticIncidentLog::query()
            ->where('action', 'notification_suppressed')
            ->count());
    }
    private function finding(): array
    {
        return [
            'key' => 'payment_success_low',
            'module' => 'payment',
            'severity' => 'critical',
            'confidence' => 'high',
            'evidence' => [
                'current' => 0.25,
                'baseline' => 0.90,
                'unit' => 'ratio',
                'change_percent' => -72.2,
                'subject_id' => 0,
            ],
        ];
    }

    private function report(int $generatedAt, array $findings): AiDiagnosticReport
    {
        return AiDiagnosticReport::query()->create([
            'scope_key' => 'platform',
            'scope_type' => 'platform',
            'site_id' => null,
            'status' => $findings === [] ? 'healthy' : 'critical',
            'score' => $findings === [] ? 100 : 75,
            'summary' => ['critical' => count($findings), 'warning' => 0, 'finding_count' => count($findings)],
            'metrics' => [],
            'findings' => $findings,
            'generated_by' => 'manual',
            'generated_at' => $generatedAt,
        ]);
    }

    private function user(string $email, bool $staff): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => 'secret',
            'token' => sha1($email),
            'uuid' => sha1('uuid-' . $email),
            'is_staff' => $staff,
            'banned' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createTables(): void
    {
        $this->database->schema()->create('v2_ai_diagnostic_report', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('scope_key');
            $table->string('scope_type');
            $table->integer('site_id')->nullable();
            $table->string('status');
            $table->integer('score');
            $table->json('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->json('findings')->nullable();
            $table->text('ai_summary')->nullable();
            $table->string('ai_status')->default('disabled');
            $table->string('generated_by');
            $table->integer('admin_id')->nullable();
            $table->integer('generated_at');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->database->schema()->create('v2_ai_diagnostic_incident', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('fingerprint', 64)->unique();
            $table->string('scope_key');
            $table->string('scope_type');
            $table->integer('site_id')->nullable();
            $table->string('finding_key');
            $table->string('module');
            $table->string('severity');
            $table->bigInteger('subject_id')->default(0);
            $table->string('status')->default('open');
            $table->bigInteger('first_report_id');
            $table->bigInteger('last_report_id');
            $table->integer('occurrence_count')->default(1);
            $table->integer('recurrence_count')->default(0);
            $table->integer('assignee_id')->nullable();
            $table->integer('due_at')->nullable();
            $table->integer('first_seen_at');
            $table->integer('last_seen_at');
            $table->integer('resolved_at')->nullable();
            $table->integer('last_notified_at')->nullable();
            $table->json('last_notification_channels')->nullable();
            $table->text('last_notification_error')->nullable();
            $table->text('last_note')->nullable();
            $table->json('latest_evidence')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->database->schema()->create('v2_ai_diagnostic_incident_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('incident_id');
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->integer('admin_id')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }
}


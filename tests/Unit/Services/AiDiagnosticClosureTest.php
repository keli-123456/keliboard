<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentOrderContext;
use App\Models\AgentUser;
use App\Models\AiDiagnosticDisposition;
use App\Models\AiDiagnosticReport;
use App\Models\Order;
use App\Models\User;
use App\Services\AiDiagnosticDispositionService;
use App\Services\AiDiagnosticEvidenceService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AiDiagnosticClosureTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createDiagnosticTables();
    }

    public function test_referral_detail_excludes_agent_downlines_and_exposes_reliable_evidence(): void
    {
        $now = time();
        $regularInviter = $this->createUser('inviter@example.test', $now - 100000);
        $agent = $this->createUser('agent@example.test', $now - 100000);
        $first = $this->createUser('first@example.test', $now - 3600, $regularInviter->id, ip2long('1.2.3.4'));
        $second = $this->createUser('second@example.test', $now - 3500, $regularInviter->id, ip2long('1.2.3.4'));
        $agentDownline = $this->createUser('agent-user@example.test', $now - 3400, $agent->id, ip2long('1.2.3.4'));
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $agentDownline->id,
            'created_at' => $now - 3400,
            'updated_at' => $now - 3400,
        ]);

        $this->createOrder('regular-order', $first->id, $regularInviter->id, 1200, 120, $now - 3000);
        $agentOrder = $this->createOrder('agent-order', $agentDownline->id, $agent->id, 9900, 990, $now - 2900);
        AgentOrderContext::query()->create([
            'order_id' => $agentOrder->id,
            'trade_no' => $agentOrder->trade_no,
            'agent_user_id' => $agent->id,
            'sale_amount' => 9900,
            'cost_amount' => 500,
            'status' => AgentOrderContext::STATUS_PAID,
            'created_at' => $now - 2900,
            'updated_at' => $now - 2900,
        ]);

        $report = $this->createReport($now, $regularInviter->id);
        $detail = app(AiDiagnosticEvidenceService::class)->detail($report, 'referral_concentration');

        $this->assertSame('referral', $detail['evidence']['kind']);
        $this->assertCount(1, $detail['evidence']['records']);
        $this->assertSame($regularInviter->id, $detail['evidence']['records'][0]['id']);
        $this->assertSame(2, $detail['evidence']['records'][0]['invite_count']);
        $this->assertSame(1, $detail['evidence']['records'][0]['paid_count']);
        $this->assertSame(0.5, $detail['evidence']['records'][0]['conversion']);
        $this->assertCount(2, $detail['evidence']['invitees']);
        $this->assertSame('1.2.3.4', $detail['evidence']['ip_concentration'][0]['ip']);
        $this->assertSame(2, $detail['evidence']['ip_concentration'][0]['user_count']);
        $this->assertCount(1, $detail['evidence']['commission_orders']);
        $this->assertSame('regular-order', $detail['evidence']['commission_orders'][0]['trade_no']);
        $this->assertFalse($detail['evidence']['device_evidence']['available']);
        $this->assertTrue($detail['evidence']['agent_downlines_excluded']);
        $this->assertSame(3, User::query()->whereNotNull('invite_user_id')->count());
        $this->assertSame(2, Order::query()->count());
    }

    public function test_disposition_is_separate_from_report_and_cooling_inherits_to_next_report(): void
    {
        $now = time();
        $firstReport = $this->createReport($now - 60, 88);
        $service = app(AiDiagnosticDispositionService::class);

        $saved = $service->update(
            $firstReport,
            'referral_concentration',
            AiDiagnosticDisposition::STATUS_IGNORED,
            'Observe for one day',
            24,
            7
        );

        $this->assertSame(AiDiagnosticDisposition::STATUS_IGNORED, $saved['status']);
        $this->assertGreaterThan($now, $saved['cooling_until']);
        $this->assertSame('critical', data_get($firstReport->fresh()->findings, '0.severity'));

        $nextReport = $this->createReport($now, 88);
        $inherited = $service->forFinding($nextReport, $nextReport->findings[0]);

        $this->assertSame(AiDiagnosticDisposition::STATUS_IGNORED, $inherited['status']);
        $this->assertTrue($inherited['inherited']);

        $resolved = $service->update(
            $nextReport,
            'referral_concentration',
            AiDiagnosticDisposition::STATUS_RESOLVED,
            'Checked manually',
            null,
            7
        );
        $this->assertSame(AiDiagnosticDisposition::STATUS_RESOLVED, $resolved['status']);
        $this->assertFalse($resolved['inherited']);
        $this->assertNull($resolved['cooling_until']);
        $this->assertSame(2, AiDiagnosticReport::query()->count());
        $this->assertSame(2, AiDiagnosticDisposition::query()->count());
    }

    private function createReport(int $generatedAt, int $subjectId): AiDiagnosticReport
    {
        return AiDiagnosticReport::query()->create([
            'scope_key' => 'platform',
            'scope_type' => 'platform',
            'site_id' => null,
            'status' => 'critical',
            'score' => 75,
            'summary' => ['critical' => 1, 'warning' => 0, 'finding_count' => 1],
            'metrics' => [
                'window' => [
                    'current_start' => $generatedAt - 86400,
                    'current_end' => $generatedAt,
                ],
            ],
            'findings' => [[
                'key' => 'referral_concentration',
                'module' => 'referral',
                'severity' => 'critical',
                'confidence' => 'high',
                'evidence' => [
                    'current' => 0.9,
                    'baseline' => 0.6,
                    'unit' => 'ratio',
                    'change_percent' => 50.0,
                    'subject_id' => $subjectId,
                ],
            ]],
            'generated_by' => 'manual',
            'generated_at' => $generatedAt,
        ]);
    }

    private function createUser(
        string $email,
        int $createdAt,
        ?int $inviteUserId = null,
        ?int $lastLoginIp = null
    ): User {
        return User::query()->create([
            'email' => $email,
            'password' => 'secret',
            'token' => sha1($email),
            'uuid' => sha1('uuid-' . $email),
            'invite_user_id' => $inviteUserId,
            'last_login_ip' => $lastLoginIp,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function createOrder(
        string $tradeNo,
        int $userId,
        int $inviteUserId,
        int $amount,
        int $commission,
        int $createdAt
    ): Order {
        return Order::query()->create([
            'invite_user_id' => $inviteUserId,
            'user_id' => $userId,
            'plan_id' => 1,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => 'month_price',
            'trade_no' => $tradeNo,
            'total_amount' => $amount,
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => Order::COMMISSION_STATUS_PENDING,
            'commission_balance' => $commission,
            'actual_commission_balance' => $commission,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function createDiagnosticTables(): void
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
        $this->database->schema()->create('v2_ai_diagnostic_disposition', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('report_id');
            $table->string('scope_key');
            $table->string('finding_key');
            $table->integer('subject_id')->default(0);
            $table->string('status');
            $table->text('note')->nullable();
            $table->integer('cooling_until')->nullable();
            $table->integer('admin_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['report_id', 'finding_key', 'subject_id']);
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentOrderContext;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\Site;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AiDiagnosticMetricsService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AiDiagnosticMetricsServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createSiteTenantTables();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createTicketTables();
        $this->createStatUserTable();
    }

    public function test_site_diagnostics_exclude_agent_business_and_keep_regular_referrals(): void
    {
        $now = time();
        $site = Site::query()->create([
            'code' => 'miaosu',
            'name' => 'Miaosu',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $now - 172800,
            'updated_at' => $now - 172800,
        ]);
        $regularInviter = $this->createUser('regular-inviter@example.test', $site->id, $now - 172800);
        $agent = $this->createUser('agent@example.test', $site->id, $now - 172800);
        $regularInvitee = $this->createUser('regular-invitee@example.test', $site->id, $now - 3600, $regularInviter->id);
        $agentInvitee = $this->createUser('agent-invitee@example.test', $site->id, $now - 3500, $agent->id);

        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $agentInvitee->id,
            'created_at' => $now - 3500,
            'updated_at' => $now - 3500,
        ]);

        $regularOrder = $this->createOrder('regular-paid', $site->id, $regularInvitee->id, $regularInviter->id, 1200, 120, $now - 3000);
        $agentOrder = $this->createOrder('agent-paid', $site->id, $agentInvitee->id, $agent->id, 9900, 990, $now - 2900);
        AgentOrderContext::query()->create([
            'order_id' => $agentOrder->id,
            'trade_no' => $agentOrder->trade_no,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => null,
            'payment_id' => null,
            'sale_amount' => $agentOrder->total_amount,
            'cost_amount' => 500,
            'hold_id' => null,
            'status' => AgentOrderContext::STATUS_PAID,
            'created_at' => $now - 2900,
            'updated_at' => $now - 2900,
        ]);

        $this->createTraffic($regularInvitee->id, $now - 1800, 100, 200);
        $this->createTraffic($agentInvitee->id, $now - 1700, 7000, 8000);
        Ticket::query()->create([
            'site_id' => $site->id,
            'user_id' => $regularInvitee->id,
            'agent_user_id' => null,
            'subject' => 'regular ticket',
            'status' => Ticket::STATUS_OPENING,
            'created_at' => $now - 1600,
            'updated_at' => $now - 1600,
        ]);
        Ticket::query()->create([
            'site_id' => $site->id,
            'user_id' => $agentInvitee->id,
            'agent_user_id' => $agent->id,
            'subject' => 'agent ticket',
            'status' => Ticket::STATUS_OPENING,
            'created_at' => $now - 1500,
            'updated_at' => $now - 1500,
        ]);

        $metrics = app(AiDiagnosticMetricsService::class)->collect($site->id, 7);

        $this->assertSame(1200, $metrics['business']['income_current']);
        $this->assertSame(1, $metrics['business']['new_users_current']);
        $this->assertSame(300, $metrics['business']['traffic_bytes_current']);
        $this->assertSame(1, $metrics['business']['tickets_current']);
        $this->assertSame(1, $metrics['payment']['orders_current']);
        $this->assertSame(1, $metrics['payment']['paid_current']);
        $this->assertSame(1, $metrics['referral']['invites_current']);
        $this->assertSame(1.0, $metrics['referral']['conversion_current']);
        $this->assertSame($regularInviter->id, $metrics['referral']['top_inviter_id']);
        $this->assertSame(120, $metrics['referral']['pending_commission_amount']);
        $this->assertTrue($metrics['referral']['agent_downlines_excluded']);
        $this->assertSame($regularOrder->id, Order::query()->where('trade_no', 'regular-paid')->value('id'));
    }

    private function createUser(string $email, int $siteId, int $createdAt, ?int $inviteUserId = null): User
    {
        return User::query()->create([
            'site_id' => $siteId,
            'email' => $email,
            'password' => 'secret',
            'token' => sha1($email),
            'uuid' => sha1('uuid-' . $email),
            'invite_user_id' => $inviteUserId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function createOrder(
        string $tradeNo,
        int $siteId,
        int $userId,
        int $inviteUserId,
        int $amount,
        int $commission,
        int $createdAt
    ): Order {
        return Order::query()->create([
            'site_id' => $siteId,
            'invite_user_id' => $inviteUserId,
            'user_id' => $userId,
            'plan_id' => 1,
            'payment_id' => null,
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

    private function createStatUserTable(): void
    {
        $this->database->schema()->create('v2_stat_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->decimal('server_rate', 10, 2)->default(1);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->char('record_type', 2)->default('d');
            $table->integer('record_at')->index();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createTraffic(int $userId, int $recordAt, int $upload, int $download): void
    {
        StatUser::query()->create([
            'user_id' => $userId,
            'server_rate' => 1,
            'u' => $upload,
            'd' => $download,
            'record_type' => 'd',
            'record_at' => $recordAt,
            'created_at' => $recordAt,
            'updated_at' => $recordAt,
        ]);
    }
}

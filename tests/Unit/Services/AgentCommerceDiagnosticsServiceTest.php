<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceDiagnosticsService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCommerceDiagnosticsServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createPaymentTable();
        $this->createAgentCommerceTables();
        $this->createPlanTable();
        $this->bindTestSettings([
            'agent_center_discount_percent' => 50,
            'agent_center_allowed_plan_ids' => '',
        ]);
    }

    public function test_inactive_agent_is_rejected(): void
    {
        $agent = $this->createUser('agent@example.test', 10000);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent permission is not active');

        app(AgentCommerceDiagnosticsService::class)->diagnose($agent);
    }

    public function test_no_enabled_payments_blocks_payment_check(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('blocked', $diagnostics['checks']['payments']['status']);
        $this->assertSame('blocked', $diagnostics['overall_status']);
    }

    public function test_payment_bound_to_inactive_domain_warns(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $domain = $this->createDomain($agent, 'pending.example.test', AgentDomain::STATUS_PENDING);
        $this->createPayment($agent, $domain->id, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('warning', $diagnostics['checks']['payments']['status']);
        $this->assertSame('blocked', $diagnostics['overall_status']);
        $this->assertSame(0, $diagnostics['domains'][0]['available_payment_count']);
    }

    public function test_payment_bound_to_one_active_domain_warns_for_uncovered_contexts(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $domainA = $this->createDomain($agent, 'a.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createDomain($agent, 'b.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, $domainA->id, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('warning', $diagnostics['checks']['payments']['status']);
        $this->assertSame(3, $diagnostics['summary']['payment_contexts_total']);
        $this->assertSame(1, $diagnostics['summary']['payment_contexts_available']);

        $paymentContexts = collect($diagnostics['payment_contexts'])->keyBy(
            fn (array $context): string => $context['type'] . ':' . ($context['domain'] ?? 'primary')
        );
        $this->assertCount(3, $paymentContexts);
        $this->assertSame([
            'type' => 'primary',
            'domain_id' => null,
            'domain' => null,
            'available_payment_count' => 0,
            'issues' => ['payment_unavailable'],
        ], $paymentContexts['primary:primary']);
        $this->assertSame(1, $paymentContexts['agent_domain:a.example.test']['available_payment_count']);
        $this->assertSame([], $paymentContexts['agent_domain:a.example.test']['issues']);
        $this->assertSame(0, $paymentContexts['agent_domain:b.example.test']['available_payment_count']);
        $this->assertSame(['payment_unavailable'], $paymentContexts['agent_domain:b.example.test']['issues']);

        $domains = collect($diagnostics['domains'])->keyBy('domain');
        $this->assertSame(1, $domains['a.example.test']['available_payment_count']);
        $this->assertSame([], $domains['a.example.test']['issues']);
        $this->assertSame(0, $domains['b.example.test']['available_payment_count']);
        $this->assertContains('payment_unavailable', $domains['b.example.test']['issues']);
    }

    public function test_unbound_payment_covers_primary_and_all_active_domain_contexts(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'a.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createDomain($agent, 'b.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('ok', $diagnostics['checks']['payments']['status']);
        $this->assertSame(3, $diagnostics['summary']['payment_contexts_total']);
        $this->assertSame(3, $diagnostics['summary']['payment_contexts_available']);
        $this->assertSame(1, $diagnostics['summary']['available_payments']);

        $paymentContexts = $diagnostics['payment_contexts'];
        $this->assertCount(3, $paymentContexts);
        foreach ($paymentContexts as $context) {
            $this->assertSame(1, $context['available_payment_count']);
            $this->assertSame([], $context['issues']);
        }

        foreach ($diagnostics['domains'] as $domain) {
            $this->assertSame(1, $domain['available_payment_count']);
            $this->assertNotContains('payment_unavailable', $domain['issues']);
        }
    }

    public function test_no_enabled_prices_blocks_price_check(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('blocked', $diagnostics['checks']['prices']['status']);
        $this->assertSame('blocked', $diagnostics['overall_status']);
    }

    public function test_partially_configured_prices_warn(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 10.00,
            Plan::PERIOD_YEARLY => 100.00,
        ]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('warning', $diagnostics['checks']['prices']['status']);
        $this->assertSame([Plan::PERIOD_YEARLY], $diagnostics['plans'][0]['missing_periods']);
    }

    public function test_hidden_sellable_plan_without_agent_price_is_excluded_from_diagnostics(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $visiblePlan = $this->createPlan('Visible', [Plan::PERIOD_MONTHLY => 10.00]);
        $hiddenPlan = $this->createPlan('Hidden', [Plan::PERIOD_MONTHLY => 20.00], ['show' => false]);
        $this->createAgentPrice($agent, $visiblePlan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);
        $diagnosticPlanIds = collect($diagnostics['plans'])->pluck('plan_id')->all();

        $this->assertSame('ok', $diagnostics['checks']['prices']['status']);
        $this->assertSame(0, $diagnostics['summary']['missing_price_periods']);
        $this->assertSame([$visiblePlan->id], $diagnosticPlanIds);
        $this->assertNotContains($hiddenPlan->id, $diagnosticPlanIds);
    }

    public function test_sold_out_plan_is_excluded_from_diagnostics(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $availablePlan = $this->createPlan('Available', [Plan::PERIOD_MONTHLY => 10.00]);
        $soldOutPlan = $this->createPlan('Sold Out', [Plan::PERIOD_MONTHLY => 20.00], ['capacity_limit' => 0]);
        $this->createAgentPrice($agent, $availablePlan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);
        $diagnosticPlanIds = collect($diagnostics['plans'])->pluck('plan_id')->all();

        $this->assertSame([$availablePlan->id], $diagnosticPlanIds);
        $this->assertNotContains($soldOutPlan->id, $diagnosticPlanIds);
    }

    public function test_available_balance_lower_than_minimum_cost_blocks_balance_check(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 499);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('blocked', $diagnostics['checks']['balance']['status']);
        $this->assertSame(500, $diagnostics['summary']['minimum_cost']);
        $this->assertSame(499, $diagnostics['summary']['available_balance']);
    }

    public function test_balance_that_only_covers_some_configured_prices_warns(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 600);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 10.00,
            Plan::PERIOD_YEARLY => 100.00,
        ]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_YEARLY, 13000);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('warning', $diagnostics['checks']['balance']['status']);
        $this->assertSame(500, $diagnostics['summary']['minimum_cost']);
        $this->assertSame(5000, $diagnostics['summary']['maximum_cost']);
    }

    public function test_diagnostics_summary_reports_pending_holds_and_available_balance(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 2000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => 123,
            'trade_no' => 'pending-hold',
            'amount' => 700,
            'status' => AgentBalanceHold::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame(2000, $diagnostics['summary']['balance']);
        $this->assertSame(700, $diagnostics['summary']['pending_hold_total']);
        $this->assertSame(1300, $diagnostics['summary']['available_balance']);
        $this->assertSame(500, $diagnostics['summary']['minimum_cost']);
        $this->assertSame(500, $diagnostics['summary']['maximum_cost']);
    }

    public function test_healthy_configuration_is_ok(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);
        $this->createDefaultSiteSetting($agent, 'Agent Shop');

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('ok', $diagnostics['overall_status']);
        $this->assertSame('ok', $diagnostics['checks']['domains']['status']);
        $this->assertSame('ok', $diagnostics['checks']['payments']['status']);
        $this->assertSame('ok', $diagnostics['checks']['prices']['status']);
        $this->assertSame('ok', $diagnostics['checks']['balance']['status']);
        $this->assertSame('ok', $diagnostics['checks']['site_settings']['status']);
        $this->assertSame(1, $diagnostics['summary']['enabled_site_settings']);
        $this->assertTrue($diagnostics['summary']['default_site_setting_enabled']);
    }

    public function test_missing_default_site_setting_warns_without_blocking_orders(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('warning', $diagnostics['overall_status']);
        $this->assertSame('warning', $diagnostics['checks']['site_settings']['status']);
        $this->assertSame('site_settings', $diagnostics['checks']['site_settings']['action']);
        $this->assertSame(0, $diagnostics['summary']['enabled_site_settings']);
        $this->assertFalse($diagnostics['summary']['default_site_setting_enabled']);
    }

    public function test_zero_discount_cost_is_treated_as_estimated_cost(): void
    {
        $this->bindTestSettings([
            'agent_center_discount_percent' => 0,
            'agent_center_allowed_plan_ids' => '',
        ]);

        $agent = $this->createActiveAgent('agent@example.test', 0);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);
        $this->createDefaultSiteSetting($agent, 'Agent Shop');

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('ok', $diagnostics['overall_status']);
        $this->assertSame('ok', $diagnostics['checks']['balance']['status']);
        $this->assertSame(0, $diagnostics['summary']['minimum_cost']);
        $this->assertSame(0, $diagnostics['summary']['maximum_cost']);
    }

    private function createActiveAgent(string $email, int $balance): User
    {
        $agent = $this->createUser($email, $balance);

        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createUser(string $email, int $balance): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => $balance,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createDomain(User $agent, string $domain, string $status): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => $status,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPayment(User $agent, ?int $ownerDomainId, bool $enable): Payment
    {
        return Payment::query()->create([
            'uuid' => substr(md5($agent->email . ':' . (string) $ownerDomainId), 0, 8),
            'name' => 'Agent Payment',
            'payment' => 'fake',
            'icon' => '',
            'config' => [],
            'notify_domain' => '',
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => $enable,
            'sort' => 0,
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'owner_domain_id' => $ownerDomainId,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPlan(string $name, array $prices, array $overrides = []): Plan
    {
        return Plan::query()->create(array_merge([
            'name' => $name,
            'prices' => $prices,
            'transfer_enable' => 100,
            'group_id' => 1,
            'speed_limit' => 100,
            'device_limit' => 3,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function createAgentPrice(User $agent, int $planId, string $period, int $salePrice): AgentPlanPrice
    {
        return AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $planId,
            'period' => $period,
            'sale_price' => $salePrice,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createDefaultSiteSetting(User $agent, string $siteName): AgentSiteSetting
    {
        return AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => null,
            'site_name' => $siteName,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

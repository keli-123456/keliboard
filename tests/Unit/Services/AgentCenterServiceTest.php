<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePlanPrice;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\SubscriptionProxy\SubscriptionProxyProbeService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCenterServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createInviteCodeTable();
        $this->createStatUserTable();
        $this->createTicketTables();
        $this->addTicketRuntimeColumns();
        $this->createPlanTable();
        $this->createAgentTables();
        $this->bindTestUrlGenerator();
        $this->bindAgentSettings();
    }

    public function test_unlock_creates_active_profile_when_balance_meets_threshold(): void
    {
        $agent = $this->createUser('agent@example.test', 10000);

        $result = app(AgentCenterService::class)->unlock($agent);

        $this->assertSame('active', $result['profile']['status']);
        $this->assertSame(1, $this->tableCount('v2_agent_profile'));
        $this->assertSame(0, $this->ledgerCount('unlock'));
    }

    public function test_unlock_rejects_user_below_balance_threshold(): void
    {
        $agent = $this->createUser('agent@example.test', 100);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent unlock threshold has not been reached');

        app(AgentCenterService::class)->unlock($agent);
    }

    public function test_agent_subordinate_apply_creates_pending_profile_and_platform_ticket(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');

        $result = app(AgentCenterService::class)->apply($subordinate, '我想申请代理');

        $profile = AgentProfile::query()->where('user_id', $subordinate->id)->first();
        $ticket = Ticket::query()->where('user_id', $subordinate->id)->first();
        $message = TicketMessage::query()->where('ticket_id', $ticket?->id)->first();

        $this->assertNotNull($profile);
        $this->assertSame(AgentCenterService::STATUS_PENDING, $profile->status);
        $this->assertSame(AgentCenterService::STATUS_PENDING, $result['profile']['status']);
        $this->assertTrue($result['application']['requires_platform_review']);
        $this->assertSame($agent->id, $result['ownership']['agent_user_id']);
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->agent_user_id);
        $this->assertNull($ticket->agent_domain_id);
        $this->assertSame($ticket->id, $result['application']['ticket_id']);
        $this->assertStringContainsString('代理开通申请', (string) $ticket->subject);
        $this->assertStringContainsString($agent->email, (string) $message?->message);
        $this->assertStringContainsString('我想申请代理', (string) $message?->message);
    }

    public function test_apply_from_site_initializes_agent_cost_site(): void
    {
        $this->createSiteTenantTables();
        $site = $this->siteWithDomain('sub-site', 'sub.example.test', false);
        $user = $this->createUser('site-user@example.test', 0, ['site_id' => $site->id]);

        app(AgentCenterService::class)->apply($user, '申请代理');

        $profile = AgentProfile::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame($site->id, (int) $profile->cost_site_id);
    }

    public function test_apply_with_request_uses_request_site_over_user_site(): void
    {
        $this->createSiteTenantTables();
        $userSite = $this->siteWithDomain('user-site', 'user.example.test', false);
        $requestSite = $this->siteWithDomain('request-site', 'request.example.test', false);
        $user = $this->createUser('request-site-user@example.test', 0, ['site_id' => $userSite->id]);
        $request = Request::create('https://request.example.test/api/v1/user/agent/apply', 'POST');

        app(AgentCenterService::class)->apply($user, '申请代理', $request);

        $profile = AgentProfile::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame($requestSite->id, (int) $profile->cost_site_id);
    }

    public function test_apply_with_platform_request_does_not_fallback_to_user_site(): void
    {
        $this->createSiteTenantTables();
        $this->siteWithDomain('default', 'main.example.test', true);
        $userSite = $this->siteWithDomain('user-site', 'user.example.test', false);
        $user = $this->createUser('platform-request-user@example.test', 0, ['site_id' => $userSite->id]);
        $request = Request::create('https://main.example.test/api/v1/user/agent/apply', 'POST');

        app(AgentCenterService::class)->apply($user, '申请代理', $request);

        $profile = AgentProfile::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertNull($profile->cost_site_id);
    }

    public function test_pending_application_cannot_change_existing_cost_site(): void
    {
        $this->createSiteTenantTables();
        $this->siteWithDomain('default', 'main.example.test', true);
        $oldSite = $this->siteWithDomain('old-site', 'old.example.test', false);
        $user = $this->createUser('existing-cost-site-user@example.test', 0, ['site_id' => $oldSite->id]);
        AgentProfile::query()->create([
            'user_id' => $user->id,
            'cost_site_id' => $oldSite->id,
            'status' => AgentCenterService::STATUS_PENDING,
            'level' => 'default',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = Request::create('https://main.example.test/api/v1/user/agent/apply', 'POST');

        app(AgentCenterService::class)->apply($user, '重新申请代理', $request);

        $profile = AgentProfile::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame($oldSite->id, (int) $profile->cost_site_id);
    }

    public function test_agent_subordinate_cannot_unlock_without_platform_review(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'balance' => 10000,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent application requires platform review');

        app(AgentCenterService::class)->unlock($subordinate);
    }

    public function test_agent_application_does_not_create_profile_when_ticket_creation_fails(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        Ticket::query()->create([
            'user_id' => $subordinate->id,
            'subject' => 'existing',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('存在未关闭的工单');

        try {
            app(AgentCenterService::class)->apply($subordinate);
        } finally {
            $this->assertSame(0, AgentProfile::query()->where('user_id', $subordinate->id)->count());
        }
    }

    public function test_create_subordinate_assigns_unique_agent_ownership(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);

        $created = app(AgentCenterService::class)->createSubordinate($agent, [
            'email' => 'buyer@example.test',
            'password' => 'secret123',
            'remark' => 'first customer',
        ]);

        $this->assertSame('buyer@example.test', $created['user']['email']);
        $this->assertSame('first customer', $created['user']['remark']);
        $this->assertSame(1, $this->tableCount('v2_agent_user'));
    }

    public function test_create_subordinate_uses_platform_scope_even_when_agent_has_site(): void
    {
        $this->createSiteTenantTables();
        $defaultSite = $this->siteWithDomain('default', 'main.example.test', true);
        $secondSite = $this->siteWithDomain('second', 'second.example.test', false);
        $this->createUser('buyer@example.test', 0, ['site_id' => $defaultSite->id]);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $agent->site_id = $secondSite->id;
        $agent->save();

        $created = app(AgentCenterService::class)->createSubordinate($agent, [
            'email' => 'buyer@example.test',
            'password' => 'secret123',
        ]);

        $subordinate = User::query()->findOrFail($created['user']['id']);
        $this->assertNull($subordinate->site_id);
        $this->assertSame(2, User::query()->where('email', 'buyer@example.test')->count());
    }

    public function test_create_subordinate_can_assign_plan_and_charge_agent_balance(): void
    {
        $this->bindAgentSettings([
            'agent_center_discount_percent' => 50,
            'agent_center_bonus_day_price' => 200,
        ]);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);
        $before = time();

        $created = app(AgentCenterService::class)->createSubordinate($agent, [
            'email' => 'buyer@example.test',
            'password' => 'secret123',
            'remark' => 'first customer',
            'plan_id' => $plan->id,
            'period' => 'monthly',
            'bonus_days' => 3,
        ]);

        $agent->refresh();
        $subordinate = User::query()->findOrFail($created['user']['id']);

        $this->assertSame(8400, (int) $agent->balance);
        $this->assertSame($plan->id, (int) $subordinate->plan_id);
        $this->assertSame(2, (int) $subordinate->group_id);
        $this->assertSame(128 * 1073741824, (int) $subordinate->transfer_enable);
        $this->assertGreaterThanOrEqual($before + 33 * 86400 - 2, (int) $subordinate->expired_at);
        $this->assertLessThanOrEqual(time() + 33 * 86400 + 2, (int) $subordinate->expired_at);
        $this->assertSame(1, $this->ledgerCount('assign_plan'));
        $this->assertSame(-1600, (int) $created['ledger']['amount']);
        $this->assertSame([
            'plan_name' => 'Starter',
            'base_amount' => 1000,
            'bonus_days' => 3,
            'bonus_day_price' => 200,
            'bonus_amount' => 600,
        ], $created['ledger']['metadata']);
    }

    public function test_create_subordinate_rejects_total_user_limit(): void
    {
        $this->bindAgentSettings(['agent_center_user_limit' => 1]);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createOwnedSubordinate($agent, 'buyer@example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent user limit exceeded');

        app(AgentCenterService::class)->createSubordinate($agent, [
            'email' => 'buyer2@example.test',
            'password' => 'secret123',
        ]);
    }

    public function test_delete_subordinate_removes_owned_user_and_releases_total_limit(): void
    {
        $this->bindAgentSettings(['agent_center_user_limit' => 1]);
        $agent = $this->createActiveAgent('agent@example.test', 10000);

        $created = app(AgentCenterService::class)->createSubordinate($agent, [
            'email' => 'buyer@example.test',
            'password' => 'secret123',
        ]);

        $deleted = app(AgentCenterService::class)->deleteSubordinate($agent, $created['user']['id']);

        $this->assertSame($created['user']['id'], $deleted['deleted_user_id']);
        $this->assertSame(0, $this->tableCount('v2_agent_user'));
        $this->assertSame(0, User::query()->where('email', 'buyer@example.test')->count());

        $createdAgain = app(AgentCenterService::class)->createSubordinate($agent, [
            'email' => 'buyer2@example.test',
            'password' => 'secret123',
        ]);

        $this->assertSame('buyer2@example.test', $createdAgain['user']['email']);
        $this->assertSame(1, $this->tableCount('v2_agent_user'));
    }

    public function test_delete_subordinate_rejects_unowned_user(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $otherAgent = $this->createActiveAgent('other-agent@example.test', 10000);
        $unownedUser = $this->createOwnedSubordinate($otherAgent, 'buyer@example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Target user is not managed by this agent');

        app(AgentCenterService::class)->deleteSubordinate($agent, $unownedUser->id);
    }

    public function test_subscribe_link_returns_owned_subordinate_subscription_url(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'token' => 'buyer-token-123',
        ]);

        $result = app(AgentCenterService::class)->subscribeLink($agent, $subordinate->id);

        $this->assertArrayHasKey('subscribe_url', $result);
        $this->assertStringContainsString('/s/buyer-token-123', $result['subscribe_url']);
    }

    public function test_subscribe_link_returns_accelerated_subscription_proxy_url_when_available(): void
    {
        app()->instance(SubscriptionProxyProbeService::class, new class extends SubscriptionProxyProbeService {
            public function userPayload(string $token): array
            {
                return [
                    'available' => true,
                    'subscribe_url' => 'https://proxy.example.test/sub/' . $token,
                    'machine_id' => 3,
                ];
            }
        });
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'token' => 'buyer-token-123',
        ]);

        $result = app(AgentCenterService::class)->subscribeLink($agent, $subordinate->id);

        $this->assertStringContainsString('/s/buyer-token-123', $result['subscribe_url']);
        $this->assertSame('https://proxy.example.test/sub/buyer-token-123', $result['accelerated_subscribe_url']);
        $this->assertTrue($result['subscription_proxy']['available']);
        $this->assertSame(3, $result['subscription_proxy']['machine_id']);
    }

    public function test_subscribe_link_rejects_unowned_subordinate(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $otherAgent = $this->createActiveAgent('other-agent@example.test', 10000);
        $unownedUser = $this->createOwnedSubordinate($otherAgent, 'buyer@example.test', [
            'token' => 'buyer-token-123',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Target user is not managed by this agent');

        app(AgentCenterService::class)->subscribeLink($agent, $unownedUser->id);
    }

    public function test_list_users_can_search_owned_subordinate_by_token_and_uuid(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $tokenMatch = $this->createOwnedSubordinate($agent, 'token-buyer@example.test', [
            'token' => 'agent-owned-token-123',
            'uuid' => 'not-the-uuid',
        ]);
        $uuidMatch = $this->createOwnedSubordinate($agent, 'uuid-buyer@example.test', [
            'token' => 'not-the-token',
            'uuid' => 'agent-owned-uuid-456',
        ]);
        $this->createOwnedSubordinate($agent, 'other-buyer@example.test', [
            'token' => 'plain-token',
            'uuid' => 'plain-uuid',
        ]);

        $tokenResult = app(AgentCenterService::class)->listUsers($agent, 'owned-token-123');
        $uuidResult = app(AgentCenterService::class)->listUsers($agent, 'owned-uuid-456');

        $this->assertSame([$tokenMatch->id], array_column($tokenResult, 'id'));
        $this->assertSame([$uuidMatch->id], array_column($uuidResult, 'id'));
    }

    public function test_list_users_search_never_crosses_agent_ownership(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $otherAgent = $this->createActiveAgent('other-agent@example.test', 10000);
        $this->createOwnedSubordinate($otherAgent, 'other-buyer@example.test', [
            'token' => 'shared-lookup-token',
            'uuid' => 'shared-lookup-uuid',
        ]);

        $result = app(AgentCenterService::class)->listUsers($agent, 'shared-lookup');

        $this->assertSame([], $result);
    }

    public function test_reset_subscription_regenerates_owned_subordinate_credentials_and_returns_new_link(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'token' => 'old-token-123',
            'uuid' => 'old-uuid-123',
        ]);

        $result = app(AgentCenterService::class)->resetSubscription($agent, $subordinate->id);

        $subordinate->refresh();

        $this->assertNotSame('old-token-123', $subordinate->token);
        $this->assertNotSame('old-uuid-123', $subordinate->uuid);
        $this->assertStringContainsString('/s/' . $subordinate->token, $result['subscribe_url']);
        $this->assertSame($subordinate->id, $result['user']['id']);
        $this->assertSame(1, $this->ledgerCount('reset_subscription'));
    }

    public function test_reset_subscription_rejects_unowned_subordinate(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $otherAgent = $this->createActiveAgent('other-agent@example.test', 10000);
        $unownedUser = $this->createOwnedSubordinate($otherAgent, 'buyer@example.test');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Target user is not managed by this agent');

        app(AgentCenterService::class)->resetSubscription($agent, $unownedUser->id);
    }

    public function test_assign_plan_deducts_agent_balance_updates_subordinate_and_writes_ledger(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);

        $result = app(AgentCenterService::class)->assignPlan($agent, $subordinate->id, [
            'plan_id' => $plan->id,
            'period' => 'monthly',
        ]);

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(8000, (int) $agent->balance);
        $this->assertSame($plan->id, (int) $subordinate->plan_id);
        $this->assertSame($plan->group_id, (int) $subordinate->group_id);
        $this->assertSame(128 * 1073741824, (int) $subordinate->transfer_enable);
        $this->assertSame(0, (int) $subordinate->u);
        $this->assertSame(0, (int) $subordinate->d);
        $this->assertSame(-2000, (int) $result['ledger']['amount']);
        $this->assertSame(1, $this->ledgerCount('assign_plan'));
    }

    public function test_assign_plan_charges_configured_bonus_day_price_without_agent_discount(): void
    {
        $this->bindAgentSettings([
            'agent_center_discount_percent' => 50,
            'agent_center_bonus_day_price' => 200,
        ]);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);
        $before = time();

        $preview = app(AgentCenterService::class)->previewAssignPlan($agent, $subordinate->id, [
            'plan_id' => $plan->id,
            'period' => 'monthly',
            'bonus_days' => 3,
        ]);
        $result = app(AgentCenterService::class)->assignPlan($agent, $subordinate->id, [
            'plan_id' => $plan->id,
            'period' => 'monthly',
            'bonus_days' => 3,
        ]);

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(1000, $preview['base_amount']);
        $this->assertSame(600, $preview['bonus_amount']);
        $this->assertSame(3, $preview['bonus_days']);
        $this->assertSame(1600, $preview['amount']);
        $this->assertSame(8400, (int) $agent->balance);
        $this->assertGreaterThanOrEqual($before + 33 * 86400 - 2, (int) $subordinate->expired_at);
        $this->assertLessThanOrEqual(time() + 33 * 86400 + 2, (int) $subordinate->expired_at);
        $this->assertSame(-1600, (int) $result['ledger']['amount']);
        $this->assertSame([
            'plan_name' => 'Starter',
            'base_amount' => 1000,
            'bonus_days' => 3,
            'bonus_day_price' => 200,
            'bonus_amount' => 600,
        ], $result['ledger']['metadata']);
    }

    public function test_assign_plan_cost_uses_agent_cost_site_price(): void
    {
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->bindAgentSettings(['agent_center_discount_percent' => 50]);
        $site = $this->siteWithDomain('agent-cost', 'agent-cost.example.test', false);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        AgentProfile::query()
            ->where('user_id', $agent->id)
            ->update(['cost_site_id' => $site->id]);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);
        SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => 'monthly',
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $preview = app(AgentCenterService::class)->previewAssignPlan($agent, $subordinate->id, [
            'plan_id' => $plan->id,
            'period' => 'monthly',
        ]);
        $result = app(AgentCenterService::class)->assignPlan($agent, $subordinate->id, [
            'plan_id' => $plan->id,
            'period' => 'monthly',
        ]);

        $agent->refresh();

        $this->assertSame(650, $preview['base_amount']);
        $this->assertSame(650, $preview['amount']);
        $this->assertSame(9350, (int) $agent->balance);
        $this->assertSame(-650, (int) $result['ledger']['amount']);
        $this->assertSame([
            'plan_name' => 'Starter',
            'base_amount' => 650,
            'bonus_days' => 0,
            'bonus_day_price' => 0,
            'bonus_amount' => 0,
        ], $result['ledger']['metadata']);
    }

    public function test_assign_plan_rejects_bonus_days_when_price_is_not_configured(): void
    {
        $this->bindAgentSettings(['agent_center_bonus_day_price' => 0]);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent bonus day price is not configured');

        app(AgentCenterService::class)->assignPlan($agent, $subordinate->id, [
            'plan_id' => $plan->id,
            'period' => 'monthly',
            'bonus_days' => 1,
        ]);
    }

    public function test_grant_bonus_days_extends_owned_subordinate_and_charges_agent_balance(): void
    {
        $this->bindAgentSettings(['agent_center_bonus_day_price' => 200]);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);
        $currentExpiry = time() + 10 * 86400;
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'plan_id' => $plan->id,
            'expired_at' => $currentExpiry,
            'u' => 1024,
            'd' => 2048,
        ]);

        $preview = app(AgentCenterService::class)->previewBonusDays($agent, $subordinate->id, [
            'bonus_days' => 3,
        ]);
        $result = app(AgentCenterService::class)->grantBonusDays($agent, $subordinate->id, [
            'bonus_days' => 3,
        ]);

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(3, $preview['bonus_days']);
        $this->assertSame(200, $preview['bonus_day_price']);
        $this->assertSame(600, $preview['amount']);
        $this->assertSame($currentExpiry + 3 * 86400, $preview['new_expired_at']);
        $this->assertSame(9400, (int) $agent->balance);
        $this->assertSame($currentExpiry + 3 * 86400, (int) $subordinate->expired_at);
        $this->assertSame(1024, (int) $subordinate->u);
        $this->assertSame(2048, (int) $subordinate->d);
        $this->assertSame(-600, (int) $result['ledger']['amount']);
        $this->assertSame(1, $this->ledgerCount('grant_bonus_days'));
        $this->assertSame([
            'plan_name' => 'Starter',
            'bonus_days' => 3,
            'bonus_day_price' => 200,
            'bonus_amount' => 600,
            'previous_expired_at' => $currentExpiry,
            'new_expired_at' => $currentExpiry + 3 * 86400,
        ], $result['ledger']['metadata']);
    }

    public function test_grant_bonus_days_starts_from_now_when_subordinate_is_expired(): void
    {
        $this->bindAgentSettings(['agent_center_bonus_day_price' => 100]);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'plan_id' => $plan->id,
            'expired_at' => time() - 5 * 86400,
        ]);
        $before = time();

        app(AgentCenterService::class)->grantBonusDays($agent, $subordinate->id, [
            'bonus_days' => 2,
        ]);

        $subordinate->refresh();

        $this->assertGreaterThanOrEqual($before + 2 * 86400 - 2, (int) $subordinate->expired_at);
        $this->assertLessThanOrEqual(time() + 2 * 86400 + 2, (int) $subordinate->expired_at);
    }

    public function test_grant_bonus_days_rejects_permanent_or_planless_subordinate(): void
    {
        $this->bindAgentSettings(['agent_center_bonus_day_price' => 100]);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $planless = $this->createOwnedSubordinate($agent, 'planless@example.test');

        try {
            app(AgentCenterService::class)->grantBonusDays($agent, $planless->id, [
                'bonus_days' => 1,
            ]);
            $this->fail('Expected planless subordinate exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Target user has no active plan', $exception->getMessage());
        }

        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);
        $permanent = $this->createOwnedSubordinate($agent, 'permanent@example.test', [
            'plan_id' => $plan->id,
            'expired_at' => null,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Permanent plans do not need bonus days');

        app(AgentCenterService::class)->grantBonusDays($agent, $permanent->id, [
            'bonus_days' => 1,
        ]);
    }

    public function test_assign_plan_rolls_back_when_balance_is_insufficient(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 100);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);

        try {
            app(AgentCenterService::class)->assignPlan($agent, $subordinate->id, [
                'plan_id' => $plan->id,
                'period' => 'monthly',
            ]);
            $this->fail('Expected insufficient balance exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Insufficient balance', $exception->getMessage());
        }

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(100, (int) $agent->balance);
        $this->assertNull($subordinate->plan_id);
        $this->assertSame(0, $this->ledgerCount('assign_plan'));
    }

    public function test_reset_traffic_deducts_reset_price_and_clears_usage(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $plan = $this->createPlan('Starter', ['monthly' => 20.00, 'reset_traffic' => 3.50], 128, 2);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'plan_id' => $plan->id,
            'u' => 1024,
            'd' => 2048,
        ]);

        $result = app(AgentCenterService::class)->resetTraffic($agent, $subordinate->id);

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(9650, (int) $agent->balance);
        $this->assertSame(0, (int) $subordinate->u);
        $this->assertSame(0, (int) $subordinate->d);
        $this->assertSame(-350, (int) $result['ledger']['amount']);
        $this->assertSame(1, $this->ledgerCount('reset_traffic'));
    }

    public function test_reset_traffic_can_be_free_when_configured(): void
    {
        $this->bindAgentSettings(['agent_center_reset_price_mode' => 'free']);
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $plan = $this->createPlan('Starter', ['monthly' => 20.00, 'reset_traffic' => 3.50], 128, 2);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'plan_id' => $plan->id,
            'u' => 1024,
            'd' => 2048,
        ]);

        $result = app(AgentCenterService::class)->resetTraffic($agent, $subordinate->id);

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(10000, (int) $agent->balance);
        $this->assertSame(0, (int) $subordinate->u);
        $this->assertSame(0, (int) $subordinate->d);
        $this->assertSame(0, (int) $result['ledger']['amount']);
    }


    #[\PHPUnit\Framework\Attributes\DataProvider('blockedActivationStates')]
    public function test_unlock_cannot_override_platform_review_or_suspension(string $status): void
    {
        $agent = $this->createActiveAgent('blocked@example.test', 10000);
        AgentProfile::where('user_id', $agent->id)->update(['status' => $status]);
        try {
            app(AgentCenterService::class)->unlock($agent);
            $this->fail('Platform-controlled status must not be self-activated.');
        } catch (ApiException) {
            $this->assertSame($status, AgentProfile::where('user_id', $agent->id)->value('status'));
        }
    }

    public static function blockedActivationStates(): array
    {
        return [['disabled'], ['pending']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedBalanceActions')]
    public function test_manual_operations_cannot_spend_pending_order_reserves(string $action): void
    {
        $this->createAgentCommerceTables();
        $this->bindAgentSettings(['agent_center_bonus_day_price' => 2000]);
        $agent = $this->createActiveAgent('reserve@example.test', 10000);
        $plan = $this->createPlan('Monthly', ['monthly' => 20.00, 'reset_traffic' => 20.00], 100, 1);
        $buyer = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'plan_id' => $plan->id, 'expired_at' => time() + 86400, 'u' => 123,
        ]);
        $order = \App\Models\Order::create([
            'user_id' => $buyer->id, 'period' => 'monthly', 'trade_no' => 'RESERVED',
            'status' => 0, 'total_amount' => 10000,
        ]);
        \App\Models\AgentBalanceHold::create([
            'agent_user_id' => $agent->id, 'order_id' => $order->id,
            'trade_no' => $order->trade_no, 'amount' => 9000, 'status' => 'pending',
        ]);
        try {
            $service = app(AgentCenterService::class);
            match ($action) {
                'create' => $service->createSubordinate($agent, ['email' => 'new@example.test', 'password' => 'secret123', 'plan_id' => $plan->id, 'period' => 'monthly']),
                'assign' => $service->assignPlan($agent, $buyer->id, ['plan_id' => $plan->id, 'period' => 'monthly']),
                'reset' => $service->resetTraffic($agent, $buyer->id),
                'bonus' => $service->grantBonusDays($agent, $buyer->id, ['bonus_days' => 1]),
            };
            $this->fail('Only 1000 cents are available, not the nominal 10000.');
        } catch (ApiException $error) {
            $this->assertSame('Insufficient balance', $error->getMessage());
            $this->assertSame(10000, (int) $agent->fresh()->balance);
            $this->assertSame(123, (int) $buyer->fresh()->u);
            $this->assertSame(0, $this->tableCount('v2_agent_ledger'));
            $this->assertFalse(User::where('email', 'new@example.test')->exists());
        }
    }

    public static function reservedBalanceActions(): array
    {
        return [['create'], ['assign'], ['reset'], ['bonus']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activeExpirations')]
    public function test_cross_plan_assignment_preserves_unexpired_entitlement(?int $days): void
    {
        $agent = $this->createActiveAgent('upgrade@example.test', 10000);
        $old = $this->createPlan('Old', ['yearly' => 12.00], 10, 1);
        $next = $this->createPlan('New', ['monthly' => 20.00], 1000, 2);
        $expires = $days === null ? null : time() + $days * 86400;
        $buyer = $this->createOwnedSubordinate($agent, 'buyer@example.test', ['plan_id' => $old->id, 'expired_at' => $expires]);
        foreach (['previewAssignPlan', 'assignPlan'] as $method) {
            try {
                app(AgentCenterService::class)->$method($agent, $buyer->id, ['plan_id' => $next->id, 'period' => 'monthly']);
                $this->fail('An active different plan cannot be replaced.');
            } catch (ApiException $error) {
                $this->assertSame('An active plan cannot be replaced with a different plan', $error->getMessage());
            }
        }
        $this->assertSame($old->id, (int) $buyer->fresh()->plan_id);
        $this->assertSame($expires, $buyer->fresh()->expired_at);
        $this->assertSame(10000, (int) $agent->fresh()->balance);
    }

    public static function activeExpirations(): array
    {
        return [[365], [null]];
    }

    public function test_expired_plan_can_be_replaced_without_inheriting_old_time(): void
    {
        $agent = $this->createActiveAgent('expired-agent@example.test', 10000);
        $old = $this->createPlan('Old', ['yearly' => 12.00], 10, 1);
        $next = $this->createPlan('New', ['monthly' => 20.00], 1000, 2);
        $buyer = $this->createOwnedSubordinate($agent, 'expired-buyer@example.test',
            ['plan_id' => $old->id, 'expired_at' => time() - 86400]);
        app(AgentCenterService::class)->assignPlan($agent, $buyer->id, ['plan_id' => $next->id, 'period' => 'monthly']);
        $this->assertSame($next->id, (int) $buyer->fresh()->plan_id);
        $this->assertEqualsWithDelta(time() + 30 * 86400, $buyer->fresh()->expired_at, 2);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedBalanceActions')]
    public function test_paid_operation_retry_only_charges_once(string $action): void
    {
        (require base_path('database/migrations/2026_09_14_180100_create_agent_operations_table.php'))->up();
        $this->bindAgentSettings(['agent_center_bonus_day_price' => 2000]);
        $agent = $this->createActiveAgent('retry-agent@example.test', 10000);
        $plan = $this->createPlan('Monthly', ['monthly' => 20.00, 'reset_traffic' => 20.00], 100, 1);
        $buyer = $this->createOwnedSubordinate($agent, 'retry-buyer@example.test',
            ['plan_id' => $plan->id, 'expired_at' => time() + 86400, 'u' => 123]);
        $service = app(AgentCenterService::class);
        $operation = fn () => match ($action) {
            'create' => $service->createSubordinate($agent, ['email' => 'new@example.test', 'password' => 'secret123', 'plan_id' => $plan->id, 'period' => 'monthly']),
            'assign' => $service->assignPlan($agent, $buyer->id, ['plan_id' => $plan->id, 'period' => 'monthly']),
            'reset' => $service->resetTraffic($agent, $buyer->id),
            'bonus' => $service->grantBonusDays($agent, $buyer->id, ['bonus_days' => 1]),
        };
        $runner = app(\App\Services\AgentOperationService::class);
        $first = $runner->execute($agent, 'repeat-operation-key', $action, [], $operation);
        $again = $runner->execute($agent, 'repeat-operation-key', $action, [], $operation);
        $this->assertEquals($first, $again);
        $this->assertSame(8000, (int) $agent->fresh()->balance);
        $this->assertSame(1, $this->tableCount('v2_agent_ledger'));
    }

    public function test_general_balance_debit_cannot_spend_agent_hold(): void
    {
        $this->createAgentCommerceTables();
        $agent = $this->createActiveAgent('balance-agent@example.test', 10000);
        $order = \App\Models\Order::create(['user_id' => $agent->id, 'period' => 'monthly',
            'trade_no' => 'reserve-balance', 'status' => 0, 'total_amount' => 10000]);
        \App\Models\AgentBalanceHold::create(['agent_user_id' => $agent->id, 'order_id' => $order->id,
            'trade_no' => $order->trade_no, 'amount' => 9000, 'status' => 'pending']);
        $service = app(\App\Services\UserService::class);
        $this->assertFalse($service->addBalance($agent->id, -2000));
        $this->assertSame(10000, (int) $agent->fresh()->balance);
        $this->assertTrue($service->addBalance($agent->id, -1000));
        $this->assertSame(9000, (int) $agent->fresh()->balance);
        $this->assertTrue($service->addBalance($agent->id, 1000));
    }

    private function createPlanTable(): void
    {
        $this->database->schema()->create('v2_plan', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id')->nullable();
            $table->integer('transfer_enable')->default(0);
            $table->string('name');
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('renew')->default(true);
            $table->boolean('sell')->default(true);
            $table->integer('sort')->default(0);
            $table->text('content')->nullable();
            $table->json('prices')->nullable();
            $table->json('tags')->nullable();
            $table->json('upgrade_to_plan_ids')->nullable();
            $table->integer('reset_traffic_method')->nullable();
            $table->integer('capacity_limit')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createInviteCodeTable(): void
    {
        $this->database->schema()->create('v2_invite_code', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->string('code')->nullable();
            $table->boolean('status')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createStatUserTable(): void
    {
        $this->database->schema()->create('v2_stat_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->integer('record_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createAgentTables(): void
    {
        $this->database->schema()->create('v2_agent_profile', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->unique();
            $table->integer('cost_site_id')->nullable()->index();
            $table->string('status', 32)->default('pending');
            $table->string('level', 64)->default('default');
            $table->string('remark')->nullable();
            $table->integer('enabled_at')->nullable();
            $table->integer('disabled_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_agent_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('sub_user_id')->unique();
            $table->string('remark')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        $this->database->schema()->create('v2_agent_ledger', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_user_id')->index();
            $table->integer('target_user_id')->nullable()->index();
            $table->string('type', 64)->index();
            $table->integer('amount')->default(0);
            $table->integer('balance_before')->default(0);
            $table->integer('balance_after')->default(0);
            $table->integer('plan_id')->nullable();
            $table->string('period', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
        });
    }

    private function addTicketRuntimeColumns(): void
    {
        if (!$this->database->schema()->hasColumn('v2_ticket', 'reply_status')) {
            $this->database->schema()->table('v2_ticket', function (Blueprint $table): void {
                $table->integer('reply_status')->default(0);
            });
        }
    }

    private function bindAgentSettings(array $overrides = []): void
    {
        $settings = array_merge([
            'agent_center_enable' => 1,
            'agent_center_unlock_mode' => 'balance_threshold',
            'agent_center_unlock_balance' => 5000,
            'agent_center_auto_activate' => 1,
            'agent_center_allowed_plan_ids' => '',
            'agent_center_discount_percent' => 100,
            'agent_center_user_limit' => 20,
            'agent_center_daily_create_limit' => 20,
            'agent_center_allow_traffic_reset' => 1,
            'agent_center_reset_price_mode' => 'plan_reset_price',
            'agent_center_bonus_day_price' => 0,
        ], $overrides);

        app()->instance(Setting::class, new class($settings) {
            public function __construct(private array $settings) {}

            public function get(string $key): mixed
            {
                return $this->settings[$key] ?? null;
            }

            public function save(array $settings): void
            {
                $this->settings = array_merge($this->settings, $settings);
            }

            public function toArray(): array
            {
                return $this->settings;
            }

            public function getBatch(array $keys): array
            {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->get($key);
                }
                return $result;
            }
        });
    }

    private function createUser(string $email, int $balance = 0, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => 'hashed',
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => $balance,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'expired_at' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }

    private function createActiveAgent(string $email, int $balance): User
    {
        $agent = $this->createUser($email, $balance);
        $this->database->table('v2_agent_profile')->insert([
            'user_id' => $agent->id,
            'status' => 'active',
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $agent;
    }

    private function siteWithDomain(string $code, string $host, bool $default): Site
    {
        $site = Site::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'status' => Site::STATUS_ACTIVE,
            'is_default' => $default,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $site;
    }

    private function createOwnedSubordinate(User $agent, string $email, array $attributes = []): User
    {
        $subordinate = $this->createUser($email, 0, $attributes);
        $this->database->table('v2_agent_user')->insert([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $subordinate->id,
            'remark' => $attributes['remark'] ?? null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $subordinate;
    }

    private function createPlan(string $name, array $prices, int $transferEnable, int $groupId): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'prices' => $prices,
            'transfer_enable' => $transferEnable,
            'group_id' => $groupId,
            'speed_limit' => 100,
            'device_limit' => 3,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function ledgerCount(string $type): int
    {
        return (int) $this->database->table('v2_agent_ledger')->where('type', $type)->count();
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->table($table)->count();
    }
}

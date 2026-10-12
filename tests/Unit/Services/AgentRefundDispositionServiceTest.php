<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserSyncEvent;
use App\Models\UserSyncState;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceService;
use App\Services\AgentProfitService;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\OrderRefundDispositionService;
use App\Services\UserSyncService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentRefundDispositionServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private User $agent;
    private Order $order;
    private OrderRefundDispositionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
        config(['app.key' => 'test-refund-key']);
        $this->bindTestSettings(['agent_center_enable' => 1, 'agent_center_user_limit' => 0]);
        $this->createUserTable();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createAgentCenterTables();
        $this->createPersonalAccessTokenTable();
        (require base_path('database/migrations/2026_10_11_100000_add_agent_refund_disposition_to_orders.php'))->up();
        (require base_path('database/migrations/2025_12_30_000001_create_user_sync_tables.php'))->up();
        $this->database->schema()->create('v2_commission_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('trade_no');
            $table->integer('reversed_at')->nullable();
        });
        app()->instance(NodeRealtimePublisher::class, $this->createMock(NodeRealtimePublisher::class));
        $this->agent = $this->user('agent@example.test');
        $this->activate($this->agent);
        $this->order = Order::create([
            'user_id' => $this->agent->id, 'trade_no' => 'AGENT-REFUND-666',
            'plan_id' => 0, 'type' => Order::TYPE_RECHARGE, 'period' => 'recharge',
            'total_amount' => 66600, 'status' => Order::STATUS_COMPLETED, 'paid_at' => time(),
        ]);
        $this->service = new OrderRefundDispositionService();
    }

    public function test_preview_lists_direct_entitlements_without_modifying_users(): void
    {
        $child = $this->child('child@example.test');
        $other = $this->user('other@example.test');
        $preview = $this->service->preview($this->order);
        $this->assertTrue($preview['is_agent']);
        $this->assertSame('direct', $preview['scope']);
        $this->assertSame(1, $preview['subordinate_count']);
        $this->assertSame(1, $preview['available_count']);
        $this->assertSame($child->id, $preview['users'][0]['id']);
        $this->assertTrue($preview['users'][0]['available']);
        $this->assertSame(64, strlen($preview['preview_token']));
        $this->assertFalse($this->agent->fresh()->banned);
        $this->assertFalse($other->fresh()->banned);
        $this->assertNull($this->order->fresh()->refund_disposed_at);
    }

    public function test_agent_refund_cannot_silently_use_the_legacy_disposition(): void
    {
        $child = $this->child('child@example.test');
        $this->rejects([], '必须预览');
        $this->assertFalse($child->fresh()->banned);
        $this->assertSame('active', AgentProfile::where('user_id', $this->agent->id)->value('status'));
    }

    public function test_preserve_disables_agent_but_keeps_child_entitlements_and_sessions(): void
    {
        $child = $this->child('child@example.test');
        $before = $child->only(['plan_id', 'group_id', 'expired_at', 'transfer_enable', 'uuid', 'token']);
        $this->session($child);
        $this->session($this->agent);
        $result = $this->service->dispose($this->order, 99, $this->options('preserve'));
        $this->assertTrue($this->agent->fresh()->banned);
        $this->assertSame('disabled', AgentProfile::where('user_id', $this->agent->id)->value('status'));
        $this->assertFalse($child->fresh()->banned);
        $this->assertSame($before, $child->fresh()->only(array_keys($before)));
        $this->assertSame(1, $child->tokens()->count());
        $this->assertSame(0, $this->agent->tokens()->count());
        $this->assertTrue(UserSyncState::find($child->id)->available);
        $this->assertSame('preserve', $result['agent_disposition']['action']);
        $this->assertSame(99, $this->order->fresh()->agent_refund_disposition['admin_id']);
    }

    public function test_freeze_revokes_direct_children_and_publishes_node_deletions(): void
    {
        $child = $this->child('child@example.test');
        $other = $this->user('other@example.test');
        $this->session($child);
        $this->session($other);
        $result = $this->service->dispose($this->order, 99, $this->options('freeze'));
        $this->assertTrue($child->fresh()->banned);
        $this->assertStringContainsString($this->order->trade_no, $child->fresh()->banned_reason);
        $this->assertFalse(UserSyncState::find($child->id)->available);
        $event = UserSyncEvent::where('user_id', $child->id)->latest('id')->firstOrFail();
        $this->assertTrue($event->old_available);
        $this->assertFalse($event->available);
        $this->assertSame(0, $child->tokens()->count());
        $this->assertFalse($other->fresh()->banned);
        $this->assertSame(1, $other->tokens()->count());
        $this->assertSame(1, $result['agent_disposition']['frozen_count']);
    }

    public function test_retries_do_not_freeze_new_users_or_reverse_commission_twice(): void
    {
        $this->child('first@example.test');
        $first = $this->service->dispose($this->order, 99, $this->options('freeze'));
        $new = $this->child('later@example.test');
        $retry = $this->service->dispose($this->order->fresh(), 100, ['agent_action' => 'transfer']);
        $this->assertTrue($retry['already_processed']);
        $this->assertSame($first['agent_disposition'], $retry['agent_disposition']);
        $this->assertFalse($new->fresh()->banned);
    }

    public function test_freezing_legacy_child_without_sync_state_still_emits_a_deletion(): void
    {
        $child = $this->child('legacy@example.test');
        UserSyncState::whereKey($child->id)->delete();
        $this->service->dispose($this->order, 99, $this->options('freeze'));
        $event = UserSyncEvent::where('user_id', $child->id)->latest('id')->firstOrFail();
        $this->assertTrue($event->old_available);
        $this->assertFalse($event->available);
        $this->assertFalse(UserSyncState::find($child->id)->available);
    }

    public function test_transfer_to_platform_preserves_service_and_unrelated_inviter(): void
    {
        $child = $this->child('child@example.test');
        $inviter = $this->user('inviter@example.test');
        $otherChild = $this->child('other-child@example.test', ['invite_user_id' => $inviter->id]);
        $this->service->dispose($this->order, 99, $this->options('transfer'));
        $this->assertSame(0, AgentUser::where('agent_user_id', $this->agent->id)->count());
        $this->assertNull($child->fresh()->invite_user_id);
        $this->assertSame($inviter->id, $otherChild->fresh()->invite_user_id);
        $this->assertFalse($child->fresh()->banned);
        $this->assertTrue(UserSyncState::find($child->id)->available);
    }

    public function test_transfer_to_active_agent_changes_ownership_not_paid_entitlements(): void
    {
        $child = $this->child('child@example.test');
        $target = $this->user('target@example.test');
        $this->activate($target);
        $before = $child->only(['plan_id', 'expired_at', 'transfer_enable', 'balance', 'uuid', 'token']);
        $result = $this->service->dispose($this->order, 99, $this->options('transfer', ['target_agent_email' => $target->email]));
        $this->assertSame($target->id, (int) AgentUser::where('sub_user_id', $child->id)->value('agent_user_id'));
        $this->assertSame($target->id, $child->fresh()->invite_user_id);
        $this->assertSame($before, $child->fresh()->only(array_keys($before)));
        $this->assertSame($target->id, $result['agent_disposition']['target_agent_user_id']);
    }

    public function test_changed_ownership_or_entitlements_requires_a_new_preview(): void
    {
        $child = $this->child('child@example.test');
        $options = $this->options('freeze');
        $child->expired_at += 86400;
        $child->save();
        $this->rejects($options, '已变化');
        $this->assertFalse($child->fresh()->banned);
    }

    public function test_new_child_requires_new_confirmation_but_traffic_updates_do_not(): void
    {
        $child = $this->child('child@example.test');
        $options = $this->options('preserve');
        $child->u = 300;
        $child->save();
        $this->assertSame($options['preview_token'], $this->service->preview($this->order)['preview_token']);
        $this->child('new@example.test');
        $this->rejects($options, '已变化');
    }

    public function test_missing_acknowledgement_reason_and_tampered_token_are_rejected(): void
    {
        $this->child('child@example.test');
        $this->rejects($this->options('freeze', ['acknowledged' => false]), '必须预览');
        $this->rejects($this->options('freeze', ['reason' => '  ']), '原因');
        $this->rejects($this->options('freeze', ['preview_token' => str_repeat('0', 64)]), '已变化');
    }

    public function test_banned_inactive_or_descendant_receiving_agents_are_rejected(): void
    {
        $child = $this->child('child@example.test');
        $this->activate($child);
        $this->rejects($this->options('transfer', ['target_agent_email' => $child->email]), '下级');
        $target = $this->user('target@example.test', ['banned' => true]);
        $this->activate($target);
        $this->rejects($this->options('transfer', ['target_agent_email' => $target->email]), '已封禁');
        $target->banned = false;
        $target->save();
        AgentProfile::where('user_id', $target->id)->update(['status' => 'disabled']);
        $this->rejects($this->options('transfer', ['target_agent_email' => $target->email]), '未启用');
    }

    public function test_transfer_respects_the_receiving_agent_user_limit(): void
    {
        $this->bindTestSettings(['agent_center_user_limit' => 1]);
        $this->child('first@example.test');
        $this->child('second@example.test');
        $target = $this->user('target@example.test');
        $this->activate($target);
        $this->rejects($this->options('transfer', ['target_agent_email' => $target->email]), '数量上限');
    }

    public function test_failure_rolls_back_bans_agent_status_sessions_and_node_state(): void
    {
        $child = $this->child('child@example.test');
        $this->session($child);
        $events = UserSyncEvent::count();
        $profit = $this->createMock(AgentProfitService::class);
        $profit->method('reverseAffectedOrders')->willThrowException(new \RuntimeException('ledger unavailable'));
        app()->instance(AgentProfitService::class, $profit);
        try {
            $this->service->dispose($this->order, 99, $this->options('freeze'));
            $this->fail('Expected transaction failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('ledger unavailable', $error->getMessage());
        }
        $this->assertFalse($child->fresh()->banned);
        $this->assertSame(1, $child->tokens()->count());
        $this->assertTrue(UserSyncState::find($child->id)->available);
        $this->assertSame($events, UserSyncEvent::count());
        $this->assertSame('active', AgentProfile::where('user_id', $this->agent->id)->value('status'));
        $this->assertNull($this->order->fresh()->agent_refund_disposition);
        $this->assertNull($this->order->fresh()->refund_disposed_at);
    }

    public function test_banned_agent_cannot_use_active_profile_or_reactivate(): void
    {
        $this->agent->banned = true;
        $this->agent->save();
        foreach (['listUsers', 'unlock', 'apply'] as $method) {
            try {
                app(AgentCenterService::class)->{$method}($this->agent);
                $this->fail('Banned agent should be rejected');
            } catch (ApiException $error) {
                $this->assertStringContainsString('disabled', $error->getMessage());
            }
        }
    }

    public function test_preview_is_paginated_and_migration_is_reversible(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->child("child{$i}@example.test");
        }
        $first = $this->service->preview($this->order);
        $last = $this->service->preview($this->order, 2);
        $this->assertCount(10, $first['users']);
        $this->assertCount(2, $last['users']);
        $this->assertSame(12, $last['subordinate_count']);
        $this->assertSame($first['preview_token'], $last['preview_token']);
        $migration = require base_path('database/migrations/2026_10_11_100000_add_agent_refund_disposition_to_orders.php');
        $migration->down();
        $this->rejects([], '数据库迁移');
        $migration->up();
        $migration->up();
        $this->assertTrue($this->service->preview($this->order)['is_agent']);
    }

    public function test_freeze_leaves_existing_ban_reason_referrals_and_grandchildren_untouched(): void
    {
        $child = $this->child('child@example.test', ['banned' => true, 'banned_reason' => 'Existing abuse restriction']);
        $grandchild = $this->user('grandchild@example.test');
        AgentUser::create(['agent_user_id' => $child->id, 'sub_user_id' => $grandchild->id]);
        $referral = $this->user('referral@example.test', ['invite_user_id' => $this->agent->id]);
        $result = $this->service->dispose($this->order, 99, $this->options('freeze'));
        $this->assertSame('Existing abuse restriction', $child->fresh()->banned_reason);
        $this->assertFalse($grandchild->fresh()->banned);
        $this->assertFalse($referral->fresh()->banned);
        $this->assertSame(1, $result['agent_disposition']['subordinate_count']);
        $this->assertSame(0, $result['agent_disposition']['frozen_count']);
    }

    public function test_abnormal_staff_ownership_cannot_be_frozen_or_transferred(): void
    {
        $staff = $this->child('staff@example.test', ['is_staff' => true]);
        $this->rejects($this->options('freeze'), '管理员');
        $this->rejects($this->options('transfer'), '管理员');
        $this->assertFalse($staff->fresh()->banned);
    }

    public function test_missing_subordinate_account_blocks_unreviewable_disposition(): void
    {
        AgentUser::create(['agent_user_id' => $this->agent->id, 'sub_user_id' => 999999]);
        $this->rejects([], '不存在的用户');
    }

    public function test_stale_agent_instance_cannot_provision_after_ban_and_commerce_rejects_banned_agents(): void
    {
        $stale = clone $this->agent;
        $this->agent->update(['banned' => true]);
        try {
            app(AgentCenterService::class)->createSubordinate($stale, ['email' => 'later@example.test', 'password' => 'test-password']);
            $this->fail('Stale agent instance must not bypass the locked ban check');
        } catch (ApiException $error) {
            $this->assertStringContainsString('disabled', $error->getMessage());
        }
        $this->assertFalse(User::where('email', 'later@example.test')->exists());
        try {
            app(AgentCommerceService::class)->calculatePlatformCost($this->agent->fresh(), new Plan(), 'monthly');
            $this->fail('Banned agent must not use an active commerce profile');
        } catch (ApiException $error) {
            $this->assertStringContainsString('not active', $error->getMessage());
        }
    }

    private function user(string $email, array $values = []): User
    {
        return User::create(array_merge([
            'email' => $email, 'password' => 'fixture-only', 'uuid' => $email, 'token' => $email,
            'plan_id' => 1, 'group_id' => 10, 'expired_at' => time() + 86400, 'transfer_enable' => 1073741824,
        ], $values))->fresh();
    }

    private function child(string $email, array $values = []): User
    {
        $child = $this->user($email, array_merge(['invite_user_id' => $this->agent->id], $values));
        AgentUser::create(['agent_user_id' => $this->agent->id, 'sub_user_id' => $child->id]);
        app(UserSyncService::class)->syncUser($child, 'created');
        return $child;
    }

    private function activate(User $user): void
    {
        AgentProfile::create(['user_id' => $user->id, 'status' => 'active', 'level' => 'default']);
    }

    private function session(User $user): void
    {
        $user->createToken('refund-fixture');
    }

    private function options(string $action, array $overrides = []): array
    {
        return array_merge([
            'agent_action' => $action, 'preview_token' => $this->service->preview($this->order)['preview_token'],
            'acknowledged' => true, 'reason' => '退款核对完成，按已确认的下级处置执行',
        ], $overrides);
    }

    private function rejects(array $options, string $message): void
    {
        try {
            $this->service->dispose($this->order, 99, $options);
            $this->fail('Disposition should have been rejected');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
        $this->assertFalse($this->agent->fresh()->banned);
        $this->assertNull($this->order->fresh()->refund_disposed_at);
    }
}

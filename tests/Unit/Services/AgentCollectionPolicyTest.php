<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\AgentProfitController;
use App\Models\AgentCollection;
use App\Models\AgentCollectionPolicy;
use App\Models\AgentOrderContext;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\User;
use App\Services\AgentCollectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCollectionPolicyTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private AgentCollectionService $service;
    private User $agent;
    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createPaymentTable();
        (require base_path('database/migrations/2026_09_14_190000_create_agent_profit_accounts.php'))->up();
        $this->agent = User::create(['email' => 'policy-agent@test.local', 'password' => 'test', 'uuid' => 'policy-agent', 'token' => 'policy-agent']);
        AgentProfile::create(['user_id' => $this->agent->id, 'status' => 'active', 'level' => 'default']);
        $this->payment = Payment::create(['uuid' => 'policy-official', 'name' => 'Official', 'payment' => 'EPay', 'owner_type' => 'platform', 'enable' => true]);
        AgentCollection::create([
            'agent_user_id' => $this->agent->id, 'mode' => 'platform', 'platform_enabled' => true,
            'payment_ids' => [$this->payment->id], 'fee_bps' => 200, 'fee_fixed' => 10,
            'settlement_days' => 7, 'minimum_withdrawal' => 1000, 'updated_at' => time(),
        ]);
        $this->service = app(AgentCollectionService::class);
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_09_15_100000_create_agent_collection_policy.php'))->up();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true, 'use_global' => true, 'platform_enabled' => true,
            'payment_ids' => [$this->payment->id], 'fee_bps' => 500, 'fee_fixed' => 20,
            'settlement_days' => 10, 'minimum_withdrawal' => 2000,
            'revision' => $this->service->policy()['revision'], 'confirm' => true,
        ], $overrides);
    }

    private function save(array $overrides = []): array
    {
        $request = Request::create('/admin/agent-operations/profit/settings', 'POST', $this->input($overrides));
        $request->setUserResolver(fn () => (new User())->forceFill(['id' => 99, 'is_admin' => true]));
        return (new AgentProfitController())->configurePolicy($request)->getData(true)['data'];
    }

    public function test_migration_preserves_independent_authorization_and_does_not_enroll_new_agents(): void
    {
        $before = AgentCollection::find($this->agent->id)->getAttributes();
        $snapshot = $this->service->snapshot($this->agent->id);
        $this->assertFalse($this->service->policy()['ready']);
        $this->migrate();
        $this->assertTrue($this->service->policy()['ready']);
        $this->assertSame($snapshot, $this->service->snapshot($this->agent->id));
        $this->assertSame($before, AgentCollection::find($this->agent->id)->getAttributes());
        $this->assertFalse($this->service->settings(999)->platform_enabled);
        $this->assertSame('self', $this->service->settings(999)->mode);
    }

    public function test_global_rules_cover_existing_and_new_agents_without_overwriting_local_rules_or_modes(): void
    {
        $this->migrate();
        $before = AgentCollection::find($this->agent->id)->getAttributes();
        $this->save();
        $this->assertSame(500, $this->service->settings($this->agent->id)->fee_bps);
        $this->assertSame(500, $this->service->settings(999)->fee_bps);
        $this->assertTrue($this->service->settings(999)->platform_enabled);
        $this->assertSame('self', $this->service->settings(999)->mode);
        $this->assertSame($before, AgentCollection::find($this->agent->id)->getAttributes());
        $this->assertSame(520, $this->service->fee(10000, $this->service->snapshot($this->agent->id)));
        $this->save(['use_global' => false]);
        $this->assertSame(200, $this->service->settings($this->agent->id)->fee_bps);
        $this->assertFalse($this->service->settings(999)->platform_enabled);
    }

    public function test_mode_change_does_not_persist_effective_rules_into_independent_settings(): void
    {
        $this->migrate();
        AgentCollection::whereKey($this->agent->id)->update(['mode' => 'self', 'platform_enabled' => false]);
        $this->save();
        $view = $this->service->setMode($this->agent->id, 'platform');
        $this->assertTrue($view['use_global']);
        $this->assertSame('platform', $view['mode']);
        $stored = AgentCollection::find($this->agent->id);
        $this->assertFalse($stored->platform_enabled);
        $this->assertSame(200, $stored->fee_bps);
        $this->assertArrayNotHasKey('global_enabled', $stored->getAttributes());
    }

    public static function ruleSources(): array
    {
        return ['independent' => [false], 'global' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ruleSources')]
    public function test_master_gate_blocks_new_external_collection_but_not_prepaid_snapshots(bool $unified): void
    {
        $this->migrate();
        $this->save(['enabled' => false, 'use_global' => $unified]);
        $view = $this->service->view($this->agent->id);
        $this->assertFalse($view['platform_enabled']);
        $this->assertTrue($view['configured_platform_enabled']);
        $this->assertFalse($view['global_enabled']);
        $this->assertSame('platform', $this->service->snapshot($this->agent->id, true)['mode']);
        $this->expectException(ApiException::class);
        $this->service->snapshot($this->agent->id);
    }

    public function test_master_gate_blocks_mode_selection_even_with_independent_authorization(): void
    {
        $this->migrate();
        $this->save(['enabled' => false, 'use_global' => false]);
        $this->expectException(ApiException::class);
        $this->service->setMode($this->agent->id, 'platform');
    }

    public function test_configure_while_master_off_preserves_requested_independent_authorization(): void
    {
        $this->migrate();
        $this->save(['enabled' => false, 'use_global' => false]);
        $view = $this->service->configure($this->agent->id, $this->input(), 99);
        $this->assertFalse($view['platform_enabled']);
        $this->assertTrue($view['configured_platform_enabled']);
        $this->assertTrue(AgentCollection::find($this->agent->id)->platform_enabled);
        $this->assertSame('platform', AgentCollection::find($this->agent->id)->mode);
    }

    public function test_local_edit_is_rejected_when_global_rules_are_active(): void
    {
        $this->migrate();
        $this->save();
        try {
            $this->service->configure($this->agent->id, $this->input(), 99);
            $this->fail('Independent edit must not appear successful while global rules apply');
        } catch (ApiException $e) {
            $this->assertSame(200, AgentCollection::find($this->agent->id)->fee_bps);
        }
    }

    public function test_existing_order_snapshot_is_immutable_after_global_rule_and_gate_changes(): void
    {
        $this->migrate();
        $snapshot = $this->service->snapshot($this->agent->id);
        $context = new AgentOrderContext(['pricing_snapshot' => ['collection' => $snapshot]]);
        $this->save(['enabled' => false, 'fee_bps' => 9000, 'fee_fixed' => 1000]);
        $this->assertSame($snapshot, $this->service->forOrder($context));
        $this->assertSame(210, $this->service->fee(10000, $this->service->forOrder($context)));
    }

    public function test_stale_admin_save_cannot_overwrite_a_newer_gate_change(): void
    {
        $this->migrate();
        $this->save(['enabled' => false]);
        try {
            $this->save(['revision' => 0, 'enabled' => true]);
            $this->fail('Stale settings must not reopen collection');
        } catch (ApiException $e) {
            $this->assertFalse($this->service->policy()['enabled']);
            $this->assertSame(1, $this->service->policy()['revision']);
        }
    }

    public function test_removed_channel_cannot_prevent_emergency_closure_but_blocks_reopening(): void
    {
        $this->migrate();
        $this->save();
        $this->payment->delete();
        $this->save(['enabled' => false]);
        $this->assertFalse($this->service->policy()['enabled']);
        $this->expectException(ApiException::class);
        $this->save(['enabled' => true]);
    }

    public function test_disabled_official_channel_cannot_collect_even_when_authorized(): void
    {
        $this->migrate();
        $this->save();
        $this->payment->enable = false;
        $this->payment->save();
        $this->expectException(ApiException::class);
        $this->service->snapshot($this->agent->id);
    }

    public static function invalidChannels(): array
    {
        return ['agent-owned' => ['agent', 'EPay'], 'internal balance' => ['platform', 'balance']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidChannels')]
    public function test_global_rules_reject_ineligible_channels(string $owner, string $gateway): void
    {
        $this->migrate();
        $this->payment->owner_type = $owner;
        $this->payment->payment = $gateway;
        $this->payment->save();
        $this->expectException(ApiException::class);
        $this->save();
    }

    public static function invalidRules(): array
    {
        return [
            'negative rate' => [['fee_bps' => -1]], 'excessive rate' => [['fee_bps' => 10001]],
            'negative fixed fee' => [['fee_fixed' => -1]], 'fractional cents' => [['fee_fixed' => 1.5]],
            'zero settlement' => [['settlement_days' => 0]], 'zero withdrawal' => [['minimum_withdrawal' => 0]],
            'invalid channel' => [['payment_ids' => [0]]], 'duplicate channel' => [['payment_ids' => [1, 1]]],
            'unconfirmed scope' => [['confirm' => false]], 'invalid boolean' => [['enabled' => 'yes']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRules')]
    public function test_invalid_financial_rules_and_missing_confirmation_cannot_be_saved(array $overrides): void
    {
        $this->migrate();
        try {
            $this->save($overrides);
            $this->fail('Invalid settings must be rejected');
        } catch (ValidationException $e) {
            $this->assertSame(0, $this->service->policy()['revision']);
        }
    }

    public static function policyActions(): array
    {
        return ['read' => ['policy'], 'write' => ['configurePolicy']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('policyActions')]
    public function test_non_admin_cannot_read_or_change_policy(string $action): void
    {
        $request = Request::create('/admin/agent-operations/profit/settings', 'POST');
        $request->setUserResolver(fn () => $this->agent);
        try {
            (new AgentProfitController())->$action($request);
            $this->fail('Policy must be admin-only');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_missing_policy_row_fails_closed_instead_of_falling_back_to_local_authorization(): void
    {
        $this->migrate();
        AgentCollectionPolicy::whereKey(1)->delete();
        $this->expectException(ApiException::class);
        $this->service->snapshot($this->agent->id);
    }

    public function test_policy_save_requires_migration(): void
    {
        $this->expectException(ApiException::class);
        $this->save();
    }

    public function test_admin_overview_exposes_only_public_channel_fields_and_policy_revision(): void
    {
        $this->migrate();
        $this->save();
        $request = Request::create('/admin/agent-operations/profit/settings');
        $request->setUserResolver(fn () => new User(['is_admin' => true]));
        $data = (new AgentProfitController())->policy($request)->getData(true)['data'];
        $this->assertSame(1, $data['policy']['revision']);
        $this->assertSame(['id', 'name', 'enable'], array_keys($data['channels'][0]));
        $this->assertSame(99, (int) DB::table('v2_agent_collection_policy')->value('updated_by'));
    }
}

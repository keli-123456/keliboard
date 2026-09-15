<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\EarningsShowcaseController;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\EarningsShowcaseService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class EarningsShowcaseTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private EarningsShowcaseService $service;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        $this->createUserTable(); $this->createOrderTable(); $this->createAgentCenterTables();
        DB::getSchemaBuilder()->create('v2_agent_domain', function (Blueprint $t) {
            $t->increments('id'); $t->integer('agent_user_id'); $t->string('domain');
            $t->string('status'); $t->boolean('is_primary')->default(false);
        });
        DB::getSchemaBuilder()->create('v2_commission_log', function (Blueprint $t) {
            $t->increments('id'); $t->integer('invite_user_id'); $t->string('trade_no');
            $t->integer('get_amount'); $t->integer('created_at'); $t->integer('reversed_at')->nullable();
        });
        DB::getSchemaBuilder()->create('v2_agent_order_context', function (Blueprint $t) {
            $t->increments('id'); $t->integer('order_id'); $t->integer('agent_user_id');
            $t->string('status'); $t->text('pricing_snapshot');
        });
        DB::getSchemaBuilder()->create('v2_site_order_context', function (Blueprint $t) { $t->increments('id'); $t->integer('order_id'); });
        DB::getSchemaBuilder()->create('v2_agent_profit', function (Blueprint $t) {
            $t->increments('id'); $t->integer('agent_user_id'); $t->integer('order_id'); $t->string('trade_no'); $t->string('status');
            foreach (['amount', 'sale_amount', 'cost_amount', 'fee_amount', 'created_at', 'updated_at', 'available_at'] as $column) $t->bigInteger($column);
        });
        DB::getSchemaBuilder()->create('v2_agent_profit_wallet', function (Blueprint $t) { $t->integer('agent_user_id'); $t->integer('available'); });
        app('config')->set('app.timezone', 'Asia/Shanghai');
        app('config')->set('app.key', 'local-test-key-not-for-production');
        $this->settings();
        $this->service = app(EarningsShowcaseService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $override = []): void
    {
        app()->instance(Setting::class, new class(array_merge(['agent_center_enable' => true, 'currency' => 'CNY'], $override)) {
            public function __construct(private array $values) {}
            public function get($key) { return $this->values[$key] ?? null; }
        });
    }

    private function yesterday(): int { return Carbon::now('Asia/Shanghai')->startOfDay()->subSecond()->timestamp; }

    private function order(array $changes = []): Order
    {
        return Order::create(array_merge(['trade_no' => 'earnings-' . ++$this->sequence, 'user_id' => 100, 'plan_id' => 1,
            'paid_at' => $this->yesterday(), 'period' => 'month_price', 'status' => Order::STATUS_COMPLETED,
            'commission_status' => Order::COMMISSION_STATUS_VALID], $changes));
    }

    private function credit(int $recipient = 1, int $amount = 100, array $order = [], array $log = []): Order
    {
        $row = $this->order($order);
        DB::table('v2_commission_log')->insert(array_merge(['invite_user_id' => $recipient, 'trade_no' => $row->trade_no,
            'get_amount' => $amount, 'created_at' => $this->yesterday()], $log));
        return $row;
    }

    private function profit(int $recipient = 1, int $amount = 1000, array $order = [], array $profit = [], array $context = []): Order
    {
        $row = $this->order($order);
        DB::table('v2_agent_order_context')->insert(array_merge(['order_id' => $row->id, 'agent_user_id' => $recipient,
            'status' => 'paid', 'pricing_snapshot' => json_encode(['collection' => ['mode' => 'platform']])], $context));
        DB::table('v2_agent_profit')->insert(array_merge(['agent_user_id' => $recipient, 'order_id' => $row->id, 'trade_no' => $row->trade_no,
            'amount' => $amount, 'sale_amount' => $amount + 300, 'cost_amount' => 200, 'fee_amount' => 100, 'status' => 'available',
            'available_at' => $this->yesterday(), 'created_at' => $this->yesterday(), 'updated_at' => $this->yesterday()], $profit));
        return $row;
    }

    private function seed(): void
    {
        for ($i = 1; $i <= 5; $i++) { $this->credit($i); $this->profit($i); }
    }

    private function user(?int $site = null, string $host = 'platform.example.test'): array
    {
        $request = Request::create('https://' . $host . '/api/v1/user/earnings-showcase');
        $request->setUserResolver(fn () => (new User())->forceFill(['id' => 10, 'site_id' => $site]));
        return $this->service->forUser($request);
    }

    private function assertAmounts(array $group, int $amount): void
    {
        $this->assertTrue($group['eligible']);
        $this->assertSame([$amount, $amount, $amount], array_column($group['entries'], 'amount'));
    }

    public function test_old_aggregate_setting_never_enables_personal_earnings(): void
    {
        $this->seed();
        $this->settings(['agent_commission_summary_enabled' => true]);
        $this->assertFalse($this->service->adminView()['enabled']);
        $this->assertNull($this->user()['referral_earnings']);
        $this->assertNull($this->user()['agent_earnings']);
    }

    public function test_each_group_sums_each_person_not_the_platform_and_not_each_order(): void
    {
        $this->seed();
        for ($i = 1; $i <= 5; $i++) { $this->credit($i, 250); $this->profit($i, 230); }
        $this->assertAmounts($this->service->referralEarnings(), 350);
        $this->assertAmounts($this->service->agentEarnings(), 1230);
        $this->settings([EarningsShowcaseService::SETTING => true]);
        $result = $this->user();
        $this->assertSame(['entries', 'currency', 'as_of'], array_keys($result['referral_earnings']));
        $this->assertArrayNotHasKey('commission', $result);
        $this->assertArrayNotHasKey('amount', $result['agent_earnings']);
        foreach (['referral_earnings', 'agent_earnings'] as $kind) {
            foreach ($result[$kind]['entries'] as $entry) {
                $this->assertSame(['alias', 'amount'], array_keys($entry));
                $this->assertMatchesRegularExpression('/^[A-F0-9]{8}$/', $entry['alias']);
            }
        }
    }

    public function test_aliases_are_stable_within_a_day_but_separate_across_groups_and_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Shanghai'));
        $this->seed();
        $referral = $this->service->referralEarnings();
        $agent = $this->service->agentEarnings();
        $this->assertSame($referral, $this->service->referralEarnings());
        $this->assertEmpty(array_intersect(array_column($referral['entries'], 'alias'), array_column($agent['entries'], 'alias')));
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00', 'Asia/Shanghai'));
        $this->assertEmpty(array_intersect(array_column($referral['entries'], 'alias'), array_column($this->service->referralEarnings()['entries'], 'alias')));
    }

    public function test_sample_thresholds_are_independent_and_small_groups_reveal_nothing(): void
    {
        for ($i = 1; $i <= 5; $i++) $this->credit($i);
        for ($i = 1; $i <= 4; $i++) $this->profit($i);
        $this->settings([EarningsShowcaseService::SETTING => true]);
        $this->assertNotNull($this->user()['referral_earnings']);
        $this->assertNull($this->user()['agent_earnings']);
        $this->assertSame([], $this->service->adminView()['agent_earnings']['entries']);
    }

    public function test_pending_cancelled_refunded_reversed_future_and_nonpositive_commissions_are_excluded(): void
    {
        $this->seed();
        for ($i = 1; $i <= 5; $i++) {
            foreach ([['commission_status' => 0], ['commission_status' => 1], ['commission_status' => 3], ['status' => 2], ['status' => 0],
                ['refund_amount' => 1], ['refund_disposed_at' => time()], ['site_id' => 2]] as $order) $this->credit($i, 999, $order);
            foreach ([['reversed_at' => time()], ['created_at' => $this->yesterday() + 1], ['trade_no' => 'missing-order'], ['invite_user_id' => 0]] as $log) $this->credit($i, 999, [], $log);
            $this->credit($i, -200); $this->credit($i, 0);
            $row = $this->credit($i, 900);
            DB::table('v2_site_order_context')->insert(['order_id' => $row->id]);
        }
        $this->assertAmounts($this->service->referralEarnings(), 100);
    }

    public function test_agent_profit_excludes_refunds_recharges_pending_unsettled_self_and_invalid_ledger_rows(): void
    {
        $this->seed();
        for ($i = 1; $i <= 5; $i++) {
            foreach ([['refund_amount' => 1], ['refund_disposed_at' => time()], ['status' => 2], ['plan_id' => 0], ['paid_at' => null], ['site_id' => 2]] as $order) $this->profit($i, 999, $order);
            foreach ([['status' => 'pending'], ['status' => 'reversed'], ['updated_at' => $this->yesterday() + 1], ['available_at' => $this->yesterday() + 1],
                ['created_at' => $this->yesterday() + 1], ['sale_amount' => 1], ['trade_no' => 'wrong'], ['order_id' => 999999]] as $profit) $this->profit($i, 999, [], $profit);
            foreach ([['status' => 'cancelled'], ['agent_user_id' => 99], ['pricing_snapshot' => '{"collection":{"mode":"self"}}']] as $context) $this->profit($i, 999, [], [], $context);
            $row = $this->profit($i, 999);
            DB::table('v2_site_order_context')->insert(['order_id' => $row->id]);
        }
        $this->assertAmounts($this->service->agentEarnings(), 1000);
    }

    public function test_legitimate_multilevel_referral_credits_count_once_and_agent_credits_do_not_mix(): void
    {
        $this->seed();
        $source = $this->order(['status' => Order::STATUS_DISCOUNTED, 'commission_balance' => 999999]);
        for ($i = 1; $i <= 5; $i++) {
            DB::table('v2_commission_log')->insert(['invite_user_id' => $i, 'get_amount' => 250, 'trade_no' => $source->trade_no, 'created_at' => $this->yesterday()]);
            $row = $this->profit($i);
            DB::table('v2_commission_log')->insert(['invite_user_id' => $i, 'get_amount' => 999, 'trade_no' => $row->trade_no, 'created_at' => $this->yesterday()]);
        }
        // A duplicate imported trade number must not multiply the valid credits.
        DB::getSchemaBuilder()->table('v2_order', fn (Blueprint $t) => $t->dropUnique(['trade_no']));
        $this->order(['trade_no' => $source->trade_no]);
        $this->assertAmounts($this->service->referralEarnings(), 350);
    }

    public function test_withdrawals_never_reduce_cumulative_commission_or_profit(): void
    {
        $this->seed();
        $user = User::create(['email' => 'private@test.local', 'password' => 'test', 'uuid' => 'test', 'token' => 'test', 'commission_balance' => 1000]);
        DB::table('v2_agent_profit_wallet')->insert(['agent_user_id' => 1, 'available' => 1000]);
        $before = $this->service->adminView();
        $user->update(['commission_balance' => 0]);
        DB::table('v2_agent_profit_wallet')->update(['available' => 0]);
        $this->assertSame($before, $this->service->adminView());
    }

    public function test_master_hide_and_agent_disable_apply_even_when_groups_are_cached(): void
    {
        $this->seed(); $this->settings([EarningsShowcaseService::SETTING => true]);
        $this->assertNotNull($this->user()['agent_earnings']);
        $this->settings([EarningsShowcaseService::SETTING => true, 'agent_center_enable' => false]);
        $this->assertNotNull($this->user()['referral_earnings']); $this->assertNull($this->user()['agent_earnings']);
        $this->settings([EarningsShowcaseService::SETTING => false]);
        $this->assertNull($this->user()['referral_earnings']); $this->assertNull($this->user()['agent_earnings']);
    }

    public function test_unbound_users_keep_entries_and_opt_in_groups_across_site_domains(): void
    {
        $this->seed(); $this->settings([EarningsShowcaseService::SETTING => true]);
        DB::table('v2_agent_domain')->insert(['agent_user_id' => 30, 'domain' => 'agent.example.test', 'status' => 'active']);
        foreach ([null, 2, 33] as $site) {
            foreach (['platform.example.test', 'site-a.example.test', 'site-b.example.test', 'agent.example.test'] as $host) {
                $result = $this->user($site, $host);
                $this->assertTrue($result['referral'], $host);
                $this->assertSame('available', $result['agent'], $host);
                $this->assertNotNull($result['referral_earnings']);
                $this->assertNotNull($result['agent_earnings']);
            }
        }
        $this->settings([EarningsShowcaseService::SETTING => false]);
        $result = $this->user(2, 'site-a.example.test');
        $this->assertTrue($result['referral']); $this->assertSame('available', $result['agent']);
        $this->assertNull($result['referral_earnings']); $this->assertNull($result['agent_earnings']);
    }

    public function test_subordinate_site_users_have_no_entries_or_groups_on_any_domain(): void
    {
        $this->seed(); $this->settings([EarningsShowcaseService::SETTING => true]);
        $this->assertNotNull($this->user()['referral_earnings']);
        DB::table('v2_agent_user')->insert(['agent_user_id' => 30, 'sub_user_id' => 10]);
        AgentProfile::create(['user_id' => 10, 'status' => 'active']);
        foreach ([null, 2, 33] as $site) {
            foreach (['platform.example.test', 'site-a.example.test', 'site-b.example.test'] as $host) {
                $this->assertSame(['referral' => false, 'agent' => 'hidden', 'referral_earnings' => null, 'agent_earnings' => null], $this->user($site, $host));
            }
        }
    }

    public function test_agent_entry_tracks_real_activation(): void
    {
        $this->assertSame('available', $this->user()['agent']);
        AgentProfile::create(['user_id' => 10, 'status' => 'active']);
        $this->assertSame('active', $this->user()['agent']);
        $this->settings(['agent_center_enable' => false]);
        $this->assertSame('hidden', $this->user()['agent']); $this->assertTrue($this->user()['referral']);
    }

    public function test_account_binding_overrides_domains_and_active_profiles_even_with_warm_earnings_cache(): void
    {
        $this->seed(); $this->settings([EarningsShowcaseService::SETTING => true]);
        $requestFor = function (string $host, int $id) {
            $request = Request::create('https://' . $host . '/api/v1/user/earnings-showcase');
            $request->setUserResolver(fn () => (new User())->forceFill(['id' => $id, 'site_id' => null]));
            return $request;
        };
        AgentProfile::create(['user_id' => 10, 'status' => 'active']);
        $main = $this->service->forUser($requestFor('platform.example.test', 10));
        $this->assertSame('active', $main['agent']); $this->assertNotNull($main['agent_earnings']);
        DB::table('v2_agent_user')->insert(['agent_user_id' => 10, 'sub_user_id' => 20]);
        DB::table('v2_agent_domain')->insert([
            ['agent_user_id' => 10, 'domain' => 'agent.example.test', 'status' => 'active'],
            ['agent_user_id' => 30, 'domain' => 'other.example.test', 'status' => 'active'],
        ]);
        $restricted = ['referral' => false, 'agent' => 'hidden', 'referral_earnings' => null, 'agent_earnings' => null];
        foreach ([['platform.example.test', 20], ['agent.example.test', 20], ['other.example.test', 20]] as [$host, $id]) {
            $this->assertSame($restricted, $this->service->forUser($requestFor($host, $id)));
        }
        foreach (['platform.example.test', 'agent.example.test', 'other.example.test'] as $host) {
            $owner = $this->service->forUser($requestFor($host, 10));
            $ordinary = $this->service->forUser($requestFor($host, 99));
            $this->assertTrue($owner['referral']); $this->assertSame('active', $owner['agent']);
            $this->assertTrue($ordinary['referral']); $this->assertSame('available', $ordinary['agent']);
        }
        // Even an active profile does not override a subordinate binding.
        AgentProfile::create(['user_id' => 20, 'status' => 'active']);
        $this->assertSame($restricted, $this->service->forUser($requestFor('platform.example.test', 20)));
        $this->settings([EarningsShowcaseService::SETTING => true, 'agent_center_enable' => false]);
        $this->assertSame($restricted, $this->service->forUser($requestFor('platform.example.test', 20)));
        $this->assertTrue($this->service->forUser($requestFor('platform.example.test', 99))['referral']);
    }

    public function test_missing_ownership_schema_never_grants_public_promotion_access(): void
    {
        $this->seed(); $this->settings([EarningsShowcaseService::SETTING => true]);
        $this->assertNotNull($this->user()['referral_earnings']);
        DB::getSchemaBuilder()->drop('v2_agent_user');
        $this->assertSame(['referral' => false, 'agent' => 'hidden', 'referral_earnings' => null, 'agent_earnings' => null], $this->user());
    }

    public function test_domain_schema_is_not_required_to_determine_account_visibility(): void
    {
        DB::getSchemaBuilder()->drop('v2_agent_domain');
        $result = $this->user(2, 'site-a.example.test');
        $this->assertTrue($result['referral']); $this->assertSame('available', $result['agent']);
        DB::table('v2_agent_user')->insert(['agent_user_id' => 30, 'sub_user_id' => 10]);
        $this->assertSame(['referral' => false, 'agent' => 'hidden', 'referral_earnings' => null, 'agent_earnings' => null], $this->user(2));
    }

    public function test_unauthenticated_requests_never_receive_entries_or_groups(): void
    {
        $request = Request::create('/api/v1/user/earnings-showcase');
        $this->assertSame(['referral' => false, 'agent' => 'hidden', 'referral_earnings' => null, 'agent_earnings' => null], $this->service->forUser($request));
    }

    public function test_unsupported_ledgers_currency_and_missing_secret_fail_closed_independently(): void
    {
        $this->seed();
        DB::getSchemaBuilder()->table('v2_agent_profit', fn (Blueprint $t) => $t->dropColumn('status'));
        $this->assertTrue($this->service->referralEarnings()['ready']); $this->assertFalse($this->service->agentEarnings()['ready']);
        $this->settings(['currency' => 'USD']); $this->assertFalse($this->service->referralEarnings()['ready']);
        $this->settings(); app('config')->set('app.key', ''); $this->assertFalse($this->service->referralEarnings()['ready']);
    }

    public function test_unsafe_individual_amounts_do_not_escape_and_list_length_is_bounded(): void
    {
        for ($i = 1; $i <= 10; $i++) $this->credit($i);
        $this->credit(99, 9007199254740992);
        $this->assertAmounts($this->service->referralEarnings(), 100);
    }

    public function test_preview_is_admin_only_and_has_no_store_headers(): void
    {
        $request = Request::create('/admin/earnings-showcase');
        $request->setUserResolver(fn () => new User(['is_admin' => false]));
        try { (new EarningsShowcaseController())->show($request); $this->fail('Not admin'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $request->setUserResolver(fn () => new User(['is_admin' => true]));
        $this->assertStringContainsString('no-store', (new EarningsShowcaseController())->show($request)->headers->get('Cache-Control'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\AgentOperationsController;
use App\Http\Routes\V2\AdminRoute;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Plan;
use App\Models\User;
use App\Services\AdminAgentUsersService;
use App\Services\AgentOperationsService;
use App\Services\UserSyncService;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminAgentUsersTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private User $agent;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings(['secure_path' => 'admin']);
        config(['app.key' => 'test-only-admin-agent-users']);
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->createPlanTable();
        $this->createSiteTenantTables();
        $this->agent = User::query()->create(['email' => 'agent@test.example', 'created_at' => time()]);
        AgentProfile::query()->create(['user_id' => $this->agent->id, 'status' => 'active']);
        $this->plan = Plan::query()->create(['name' => 'Starter', 'group_id' => 1]);
    }

    public function test_new_and_disabled_agents_are_visible_without_orders(): void
    {
        $disabled = User::query()->create(['email' => 'disabled@test.example']);
        AgentProfile::query()->create(['user_id' => $disabled->id, 'status' => 'disabled']);
        User::query()->create(['email' => 'ordinary@test.example']);
        $this->child();
        $service = app(AgentOperationsService::class);
        $list = $service->adminAgents([]);
        $this->assertSame(2, $list['total']);
        $this->assertSame(1, $list['data'][0]['subordinate_count']);
        $this->assertSame('disabled', $list['data'][1]['status']);
        $this->assertSame(1, $service->adminSummary()['active_agent_count']);
        $this->assertSame($disabled->id, $service->adminAgents(['keyword' => (string) $disabled->id])['data'][0]['agent_user_id']);
        $this->assertSame(0, $service->adminAgents(['abnormal' => true])['total']);
        $this->assertSame(0, app(AdminAgentUsersService::class)->fetch($disabled->id, [])['total']);
    }

    public function test_only_direct_owned_users_are_returned_and_secrets_are_never_serialized(): void
    {
        $child = $this->child(['password' => 'secret', 'uuid' => 'private-uuid', 'token' => 'private-token']);
        $grandchild = $this->child([], $child->id);
        User::query()->create(['email' => 'referral@test.example', 'invite_user_id' => $this->agent->id]);
        $other = User::query()->create(['email' => 'other@test.example']);
        $this->child([], $other->id);
        AgentUser::query()->create(['agent_user_id' => $this->agent->id, 'sub_user_id' => 99999]);
        $result = $this->fetch();
        $this->assertSame('direct', $result['scope']);
        $this->assertSame(1, $result['total']);
        $this->assertSame([$child->id], array_column($result['data'], 'id'));
        $this->assertNotContains($grandchild->id, array_column($result['data'], 'id'));
        $this->assertSame('Starter', $result['data'][0]['plan_name']);
        $this->assertSame(1234, $result['data'][0]['balance']);
        foreach (['password', 'uuid', 'token', 'subscribe_url', 'commission_balance'] as $field) {
            $this->assertArrayNotHasKey($field, $result['data'][0]);
        }
    }

    public function test_status_filters_match_node_availability_and_summary_is_not_filtered(): void
    {
        $this->child();
        $this->child(['banned' => true]);
        $this->child(['expired_at' => time() - 60]);
        $this->child(['plan_id' => null]);
        $this->child(['group_id' => null]);
        $this->child(['d' => 1000]);
        $this->child(['transfer_enable' => null]);
        $this->child(['expired_at' => null]);
        $this->child(['expired_at' => 0]);
        $result = $this->fetch();
        $this->assertSame(['total' => 9, 'active' => 3, 'banned' => 1, 'expired' => 1, 'no_plan' => 2, 'exhausted' => 2], $result['summary']);
        foreach ($result['data'] as $row) {
            $available = app(UserSyncService::class)->computeSnapshot(User::query()->findOrFail($row['id']))['available'];
            $this->assertSame($available, $row['status'] === 'active');
        }
        foreach (AdminAgentUsersService::STATUSES as $status) {
            $filtered = $this->fetch(['status' => $status]);
            $this->assertSame($result['summary'], $filtered['summary']);
            $this->assertSame($result['summary'][$status], $filtered['total']);
            $this->assertSame([$status], array_values(array_unique(array_column($filtered['data'], 'status'))));
        }
    }

    public function test_search_pagination_and_live_transfer_keep_ownership_scoped(): void
    {
        $first = $this->child(['email' => 'first@test.example']);
        $this->child(['email' => 'second@test.example']);
        $last = $this->child(['email' => 'last@test.example']);
        AgentUser::query()->where('sub_user_id', $first->id)->update(['remark' => 'VIP']);
        $this->assertSame([$first->id], array_column($this->fetch(['keyword' => 'VIP'])['data'], 'id'));
        $this->assertSame([$last->id], array_column($this->fetch(['keyword' => 'last@'])['data'], 'id'));
        $this->assertSame([$first->id], array_column($this->fetch(['keyword' => (string) $first->id])['data'], 'id'));
        $page = $this->fetch(['page' => 99, 'page_size' => 2]);
        $this->assertSame(2, $page['page']);
        $this->assertSame([$first->id], array_column($page['data'], 'id'));
        AgentUser::query()->where('sub_user_id', $first->id)->delete();
        $this->assertSame(2, $this->fetch()['total']);
        $this->assertSame([], $this->fetch(['keyword' => 'VIP'])['data']);
    }

    public function test_fetch_is_read_only_and_uses_bounded_queries_for_a_full_page(): void
    {
        for ($i = 0; $i < 25; $i++) $this->child();
        $before = User::query()->orderBy('id')->get()->toArray();
        DB::enableQueryLog();
        $page = $this->fetch();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(20, $page['data']);
        $this->assertLessThanOrEqual(6, count($queries));
        $this->assertSame($before, User::query()->orderBy('id')->get()->toArray());
    }

    public function test_controller_validates_filters_and_route_requires_admin(): void
    {
        foreach ([['page' => 0], ['page_size' => 101], ['status' => 'anything'], ['keyword' => str_repeat('x', 256)]] as $invalid) {
            try {
                app(AgentOperationsController::class)->agentUsers(Request::create('/', 'GET', $invalid), $this->agent->id);
                $this->fail('Invalid filter must be rejected');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey(array_key_first($invalid), $error->errors());
            }
        }
        $controller = app(AgentOperationsController::class);
        $payload = $controller->agentUsers(Request::create('/', 'GET'), $this->agent->id)->getData(true);
        $this->assertSame(0, $payload['data']['total']);
        $router = new Router(new Dispatcher(app()), app());
        (new AdminRoute())->map($router);
        $route = $router->getRoutes()->match(Request::create('/admin/agent-operations/agents/1/users', 'GET'));
        $this->assertContains('admin', $route->gatherMiddleware());
        $this->assertSame('agentUsers', $route->getActionMethod());
    }

    public function test_non_agents_cannot_be_used_as_an_arbitrary_user_scope(): void
    {
        $ordinary = User::query()->create(['email' => 'normal@test.example']);
        $this->expectException(ApiException::class);
        app(AdminAgentUsersService::class)->fetch($ordinary->id, []);
    }

    private function fetch(array $filters = []): array
    {
        return app(AdminAgentUsersService::class)->fetch($this->agent->id, $filters);
    }

    private function child(array $fields = [], ?int $owner = null): User
    {
        $user = User::query()->create($fields + [
            'email' => uniqid('client-', true) . '@test.example', 'plan_id' => $this->plan->id,
            'group_id' => 1, 'expired_at' => time() + 3600, 'transfer_enable' => 1000,
            'u' => 10, 'd' => 20, 'balance' => 1234, 'created_at' => time(),
        ]);
        AgentUser::query()->create(['agent_user_id' => $owner ?? $this->agent->id, 'sub_user_id' => $user->id, 'created_at' => time()]);
        return $user;
    }
}

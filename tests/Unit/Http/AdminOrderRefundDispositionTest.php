<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\OrderController;
use App\Http\Routes\V2\AdminRoute;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderRefundDispositionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminOrderRefundDispositionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private Order $order;
    private User $agent;
    private User $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings(['secure_path' => 'admin', 'agent_center_enable' => 1]);
        config(['app.key' => 'test-only-refund-api']);
        $this->createUserTable();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createAgentCenterTables();
        $this->createPersonalAccessTokenTable();
        (require base_path('database/migrations/2026_10_11_100000_add_agent_refund_disposition_to_orders.php'))->up();
        $this->database->schema()->create('v2_commission_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('trade_no');
            $table->integer('reversed_at')->nullable();
        });
        $this->agent = User::create(['email' => 'agent@example.test', 'balance' => 66600, 'password' => 'test-only']);
        $this->child = User::create(['email' => 'child@example.test', 'password' => 'test-only']);
        AgentProfile::create(['user_id' => $this->agent->id, 'status' => 'active', 'level' => 'default']);
        AgentUser::create(['agent_user_id' => $this->agent->id, 'sub_user_id' => $this->child->id]);
        $this->order = Order::create(['user_id' => $this->agent->id, 'trade_no' => 'API-REFUND-666',
            'total_amount' => 66600, 'status' => Order::STATUS_COMPLETED, 'period' => 'recharge', 'type' => Order::TYPE_RECHARGE]);
    }

    public function test_preview_is_read_only_and_submission_returns_the_persisted_audit(): void
    {
        $controller = new OrderController();
        $service = new OrderRefundDispositionService();
        $previewResponse = $controller->refundDisposePreview($this->request(['trade_no' => $this->order->trade_no]), $service);
        $preview = $previewResponse->getData(true)['data'];
        $this->assertTrue($preview['is_agent']);
        $this->assertSame(1, $preview['subordinate_count']);
        $this->assertSame($this->child->email, $preview['users'][0]['email']);
        $this->assertNull($this->order->fresh()->refund_disposed_at);
        $response = $controller->refundDispose($this->request([
            'trade_no' => $this->order->trade_no, 'preview_token' => $preview['preview_token'],
            'agent_action' => 'transfer', 'acknowledged' => true, 'reason' => 'Platform takeover after verified refund',
        ]), $service)->getData(true);
        $this->assertSame('success', $response['status']);
        $this->assertSame('transfer', $response['data']['agent_disposition']['action']);
        $this->assertSame(99, $response['data']['agent_disposition']['admin_id']);
        $this->assertSame($response['data']['agent_disposition'], $this->order->fresh()->agent_refund_disposition);
        $this->assertSame(0, AgentUser::count());
        $this->assertSame(66600, $this->agent->fresh()->balance);
        $this->assertFalse($this->child->fresh()->banned);
    }

    public function test_legacy_agent_submission_and_missing_identity_fail_without_mutation(): void
    {
        $controller = new OrderController();
        $service = new OrderRefundDispositionService();
        $response = $controller->refundDispose($this->request(['trade_no' => $this->order->trade_no]), $service);
        $this->assertSame('fail', $response->getData(true)['status']);
        $anonymous = Request::create('/', 'POST', ['trade_no' => $this->order->trade_no]);
        $response = $controller->refundDispose($anonymous, $service);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($this->agent->fresh()->banned);
        $this->assertNull($this->order->fresh()->refund_disposed_at);
    }

    public function test_preview_rejects_unpaid_orders_and_missing_trade_numbers(): void
    {
        $controller = new OrderController();
        $service = new OrderRefundDispositionService();
        $this->order->update(['status' => Order::STATUS_PENDING]);
        $response = $controller->refundDisposePreview($this->request(['trade_no' => $this->order->trade_no]), $service);
        $this->assertSame('fail', $response->getData(true)['status']);
        $response = $controller->refundDisposePreview($this->request(['trade_no' => 'missing']), $service);
        $this->assertSame('fail', $response->getData(true)['status']);
        $this->assertNull($this->order->fresh()->refund_disposed_at);
    }

    public function test_http_validation_rejects_unknown_policy_invalid_email_and_token(): void
    {
        foreach ([['agent_action' => 'delete'], ['target_agent_email' => 'not-email'], ['preview_token' => str_repeat('z', 64)], ['reason' => str_repeat('a', 501)], ['acknowledged' => 'maybe']] as $invalid) {
            try {
                (new OrderController())->refundDispose($this->request(['trade_no' => $this->order->trade_no] + $invalid), new OrderRefundDispositionService());
                $this->fail('Invalid input should fail validation');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey(array_key_first($invalid), $error->errors());
            }
        }
        $this->assertNull($this->order->fresh()->refund_disposed_at);
    }

    public function test_preview_and_mutation_routes_both_require_admin_middleware(): void
    {
        $router = new Router(new Dispatcher(app()), app());
        (new AdminRoute())->map($router);
        foreach (['refund-dispose', 'refund-dispose/preview'] as $path) {
            $route = $router->getRoutes()->match(Request::create('/admin/order/' . $path, 'POST'));
            $this->assertContains('admin', $route->gatherMiddleware());
            $this->assertContains('log', $route->gatherMiddleware());
        }
    }

    private function request(array $payload): Request
    {
        $request = Request::create('/', 'POST', $payload);
        $admin = new User(['is_admin' => true]);
        $admin->id = 99;
        $request->setUserResolver(fn () => $admin);
        return $request;
    }
}

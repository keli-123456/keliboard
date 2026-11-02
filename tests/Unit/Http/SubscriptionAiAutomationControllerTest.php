<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\SubscriptionControlController;
use App\Http\Routes\V2\AdminRoute;
use App\Models\User;
use App\Services\SubscriptionAiAutomationService;
use App\Services\SubscriptionAiBenchmarkService;
use Illuminate\Http\Request;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionAiAutomationControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'synthetic-route-test-key']);
        $this->bindJsonResponseFactory();
    }

    private function request(array $changes = []): Request
    {
        $request = Request::create('/', 'POST', $changes + SubscriptionAiAutomationService::DEFAULTS + ['confirmed' => true, 'expected_revision' => 7]);
        $request->setUserResolver(static fn() => (new User())->forceFill(['id' => 99, 'is_admin' => true]));
        return $request;
    }

    public function test_all_automation_routes_require_admin_and_only_reads_use_get(): void
    {
        $this->bindTestSettings(['secure_path' => 'synthetic-admin']);
        app()->register(RoutingServiceProvider::class);
        $router = app('router'); (new AdminRoute())->map($router);
        foreach (['automation' => 'GET', 'automation/policy' => 'POST', 'automation/run' => 'POST', 'automation/stop' => 'POST', 'automation/{actionId}/revoke' => 'POST', 'automation/{actionId}/review' => 'POST'] as $path => $method) {
            $route = collect($router->getRoutes())->first(fn($route) => str_ends_with($route->uri(), '/risk/subscription-control/' . $path));
            $this->assertNotNull($route);
            $this->assertContains('admin', $route->gatherMiddleware());
            $this->assertContains($method, $route->methods());
            if ($method === 'POST') $this->assertNotContains('GET', $route->methods());
        }
        foreach (['benchmark' => 'GET', 'benchmark/start' => 'POST', 'benchmark/label' => 'POST', 'benchmark/approve' => 'POST', 'benchmark/cancel' => 'POST'] as $path => $method) {
            $route = collect($router->getRoutes())->first(fn($route) => str_ends_with($route->uri(), '/automation/' . $path));
            $this->assertNotNull($route); $this->assertContains('admin', $route->gatherMiddleware()); $this->assertContains($method, $route->methods());
            if ($method === 'POST') $this->assertNotContains('GET', $route->methods());
        }
    }

    public function test_validation_rejects_missing_revision_and_confirmation_or_out_of_bounds_limits(): void
    {
        $service = $this->createMock(SubscriptionAiAutomationService::class);
        $service->expects($this->never())->method('saveSettings');
        foreach ([['expected_revision' => null], ['confirmed' => null], ['ai_auto_max_seconds' => 901], ['ai_auto_active_limit' => 0], ['ai_auto_hourly_budget' => 31], ['ai_auto_mode' => 'erase_rules']] as $invalid) {
            try {
                (new SubscriptionControlController())->saveAutomation($this->request($invalid), $service);
                $this->fail('Invalid authorization input was accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_first($invalid), $exception->errors());
            }
        }
    }

    public function test_policy_save_passes_the_authenticated_admin_and_original_revision_not_client_identity(): void
    {
        $service = $this->createMock(SubscriptionAiAutomationService::class);
        $service->expects($this->once())->method('saveSettings')->with(
            $this->callback(fn(array $values) => $values['ai_auto_enabled'] === true && !isset($values['admin_id'])), true, 99, 7,
        )->willReturn(['saved' => true]);
        $response = (new SubscriptionControlController())->saveAutomation($this->request(['ai_auto_enabled' => true, 'admin_id' => 123]), $service);
        $this->assertSame(['saved' => true], $response->getData(true)['data']);
    }

    public function test_stale_authorization_is_an_explicit_failure_not_a_success(): void
    {
        $service = $this->createMock(SubscriptionAiAutomationService::class);
        $service->method('saveSettings')->willThrowException(new \RuntimeException('policy_changed'));
        $response = (new SubscriptionControlController())->saveAutomation($this->request(), $service);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('fail', $response->getData(true)['status']);
        $this->assertSame('policy_changed', $response->getData(true)['error']);
    }

    public function test_emergency_stop_does_not_require_a_working_model_or_stale_revision(): void
    {
        $service = $this->createMock(SubscriptionAiAutomationService::class);
        $service->expects($this->once())->method('settings')->willReturn(SubscriptionAiAutomationService::DEFAULTS);
        $service->expects($this->never())->method('process');
        $service->expects($this->once())->method('saveSettings')->with($this->callback(fn(array $values) =>
            !$values['ai_auto_enabled'] && !$values['ai_auto_allow_reset_token'] && !$values['ai_auto_allow_suspend']
        ), false, 99)->willReturn(['stopped' => true]);
        $response = (new SubscriptionControlController())->stopAutomation($this->request(['expected_revision' => 1]), $service);
        $this->assertSame(['stopped' => true], $response->getData(true)['data']);
    }

    public function test_review_uses_the_authenticated_admin_and_rejects_arbitrary_learning_instructions(): void
    {
        $service = $this->createMock(SubscriptionAiAutomationService::class);
        $service->expects($this->once())->method('review')->with(5, 'false_positive', 99)->willReturn(['reviewed' => true]);
        $response = (new SubscriptionControlController())->reviewAutomation($this->request(['verdict' => 'false_positive', 'admin_id' => 123, 'prompt' => 'remove all rules']), 5, $service);
        $this->assertSame(['reviewed' => true], $response->getData(true)['data']);
        $this->expectException(ValidationException::class);
        (new SubscriptionControlController())->reviewAutomation($this->request(['verdict' => 'remove all rules']), 5, $service);
    }

    public function test_benchmark_approval_requires_explicit_operational_signoff_and_does_not_trust_client_actor(): void
    {
        $service = $this->createMock(SubscriptionAiBenchmarkService::class);
        $service->expects($this->once())->method('approve')->with(7, [10], true, true, 99)->willReturn(['approved' => true]);
        $controller = new SubscriptionControlController();
        foreach ([false, true] as $ops) {
            $request = $this->request(['run_id' => 7, 'user_ids' => [10], 'confirmed' => true, 'operations_confirmed' => $ops, 'admin_id' => 123]);
            if (!$ops) {
                try { $controller->approveBenchmark($request, $service); $this->fail('Missing signoff accepted'); }
                catch (ValidationException $error) { $this->assertArrayHasKey('operations_confirmed', $error->errors()); }
            } else $this->assertSame(['approved' => true], $controller->approveBenchmark($request, $service)->getData(true)['data']);
        }
    }
}

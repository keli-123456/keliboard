<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\PaymentController;
use App\Http\Middleware\RequestLog;
use App\Http\Routes\V2\AdminRoute;
use App\Models\Payment;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PaytaroChannelControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createPaymentTable();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings(['secure_path' => 'admin']);
        config(['app.key' => 'test-only']);
        Http::preventStrayRequests();
    }

    public function test_reading_unsaved_credentials_never_saves_or_enables_a_payment(): void
    {
        $payment = Payment::create(['uuid' => 'test', 'name' => 'Existing', 'payment' => 'PayTaro', 'enable' => false,
            'config' => ['app_id' => 'old-app', 'app_secret' => 'old-secret', 'method_uuid' => 'legacy-uuid']]);
        $before = $payment->fresh()->toArray();
        Http::fake(['*' => Http::response(['app' => ['app_id' => 'new-app'], 'methods' => []])]);
        $result = app(PaymentController::class)->getPaytaroChannels(Request::create('/payment/getPaytaroChannels', 'POST', [
            'app_id' => 'new-app', 'app_secret' => 'new-secret', 'id' => $payment->id, 'url' => 'https://untrusted.example.test',
        ]));
        $this->assertSame('success', $result->getData(true)['status']);
        $this->assertSame([], $result->getData(true)['data']);
        $this->assertSame($before, $payment->fresh()->toArray());
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://v3.paytaro.com/v1/app/methods'
            && $r->hasHeader('X-App-Secret', 'new-secret'));
        Http::assertSentCount(1);
    }

    public function test_required_fields_are_validated_before_lookup(): void
    {
        $this->expectException(ValidationException::class);
        try {
            app(PaymentController::class)->getPaytaroChannels(Request::create('/payment/getPaytaroChannels', 'POST', ['app_id' => 'test']));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_gateway_failure_returns_a_safe_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'private-secret'], 401)]);
        $response = app(PaymentController::class)->getPaytaroChannels(Request::create('/payment/getPaytaroChannels', 'POST', ['app_id' => 'app', 'app_secret' => 'secret']));
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringNotContainsString('private-secret', $response->getContent());
    }

    public function test_endpoint_is_admin_only_and_rate_limited(): void
    {
        $router = new Router(new Dispatcher(app()), app());
        (new AdminRoute())->map($router);
        $route = $router->getRoutes()->match(Request::create('/admin/payment/getPaytaroChannels', 'POST'));
        $this->assertSame([PaymentController::class, 'getPaytaroChannels'], [$route->getControllerClass(), $route->getActionMethod()]);
        $this->assertContains('admin', $route->gatherMiddleware());
        $this->assertContains('log', $route->gatherMiddleware());
        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
    }

    public function test_lookup_secret_is_redacted_from_admin_audit_payload(): void
    {
        $method = new \ReflectionMethod(RequestLog::class, 'sanitizePayload');
        $sanitized = $method->invoke(new RequestLog(), ['app_id' => 'app', 'app_secret' => 'secret']);
        $this->assertSame('[REDACTED]', $sanitized['app_secret']);
    }
}

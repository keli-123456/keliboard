<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugin\Paytaro\Plugin;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PaytaroTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private const ENDPOINT = 'https://v3.paytaro.com/v1/invoice/order';
    private const UUID = 'e5b62e61-1dff-41ed-b6ce-45404b0b60da';
    private const CHECKOUT = 'https://v3.paytaro.com/checkout/?uuid=' . self::UUID;
    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->plugin = new Plugin('paytaro');
        $this->plugin->setConfig(['app_id' => 'app-1', 'app_secret' => 'test-secret', 'checkout_mode' => 'cashier']);
    }

    public function test_registers_method_and_config_fields_without_changing_other_methods(): void
    {
        $this->plugin->boot();
        $methods = HookManager::filter('available_payment_methods', ['EPay' => ['name' => 'EPay']]);
        $this->assertSame(['name' => 'EPay'], $methods['EPay']);
        $this->assertSame('paytaro', $methods['PayTaro']['plugin_code']);
        $this->assertSame(['app_id', 'app_secret', 'checkout_mode', 'method_uuid'], array_keys($this->plugin->form()));
        $metadata = json_decode(file_get_contents(base_path('plugins/Paytaro/config.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('payment', $metadata['type']);
        $this->assertSame('paytaro', $metadata['code']);

        $this->plugin->setConfig(['enabled' => false]);
        $this->assertSame([], HookManager::filter('available_payment_methods', []));
    }

    public function test_posts_cashier_order_with_header_auth_and_bounded_verified_transport(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame(self::ENDPOINT, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('X-App-Secret', 'test-secret'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $this->assertSame([
                'merchant_no' => 'trade-1',
                'order_amount' => 12.34,
                'notify_url' => 'https://notify.example.test/api/v1/guest/payment/notify/PayTaro/agent-1',
                'return_url' => 'https://agent.example.test/#/pay-success?trade_no=trade-1',
            ], $request->data());
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(20, $options['timeout']);
            $this->assertStringNotContainsString('test-secret', $request->body());

            return Http::response(['checkout_url' => self::CHECKOUT, 'uuid' => self::UUID]);
        });

        $this->assertSame(['type' => 1, 'data' => self::CHECKOUT], $this->plugin->pay($this->orderPayload()));
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidOrderAmounts')]
    public function test_invalid_order_amounts_are_rejected_before_sending(mixed $amount): void
    {
        Http::fake();
        try {
            $this->plugin->pay(array_replace($this->orderPayload(), ['total_amount' => $amount]));
            $this->fail('Invalid order amount was accepted.');
        } catch (ApiException) {
            Http::assertNothingSent();
        }
    }

    public static function invalidOrderAmounts(): array
    {
        return array_map(fn ($value) => [$value], [0, -1, 1.23, null, true, [], '12.34', '1e3', '999999999999999999999999']);
    }

    public function test_missing_credentials_and_insecure_callback_do_not_send_requests(): void
    {
        Http::fake();
        foreach ([[], ['app_id' => 'app-1'], ['app_secret' => 'test-secret'], ['app_id' => 'app-1', 'app_secret' => "bad\r\nheader"]] as $config) {
            $this->plugin->setConfig($config);
            try {
                $this->plugin->pay($this->orderPayload());
                $this->fail('Invalid credentials were accepted.');
            } catch (ApiException) {
                Http::assertNothingSent();
            }
        }
        $this->plugin->setConfig(['app_id' => 'app-1', 'app_secret' => 'test-secret', 'checkout_mode' => 'cashier']);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTPS');
        $this->plugin->pay(array_replace($this->orderPayload(), ['notify_url' => 'http://notify.example.test/notify']));
    }

    #[DataProvider('httpFailures')]
    public function test_gateway_failure_does_not_expose_response_or_secret(int $status): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => 'private-upstream-detail test-secret'], $status)]);
        try {
            $this->plugin->pay($this->orderPayload());
            $this->fail('Failed gateway response was accepted.');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('PayTaro', $exception->getMessage());
            $this->assertStringNotContainsString('private-upstream-detail', $exception->getMessage());
            $this->assertStringNotContainsString('test-secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            Http::assertSentCount(1);
        }
    }

    public static function httpFailures(): array
    {
        return [[302], [400], [401], [403], [422], [429], [500], [502]];
    }

    public function test_connection_failure_is_redacted_and_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('transport detail test-secret');
        });
        try {
            $this->plugin->pay($this->orderPayload());
            $this->fail('Connection error was accepted.');
        } catch (ApiException $exception) {
            $this->assertSame(1, $attempts);
            $this->assertStringNotContainsString('test-secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    #[DataProvider('invalidCashierResponses')]
    public function test_invalid_cashier_response_is_rejected(mixed $body): void
    {
        Http::fake([self::ENDPOINT => Http::response($body)]);
        $this->expectException(ApiException::class);
        $this->plugin->pay($this->orderPayload());
    }

    public static function invalidCashierResponses(): array
    {
        $results = [['<html>gateway unavailable</html>'], [[]], [['checkout_url' => self::CHECKOUT]], [['uuid' => self::UUID]], [['data' => ['checkout_url' => self::CHECKOUT, 'uuid' => self::UUID]]]];
        foreach (['javascript:alert(1)', 'http://v3.paytaro.com/checkout/?uuid=' . self::UUID,
            'https://evil.example.test/checkout/?uuid=' . self::UUID,
            'https://v3.paytaro.com.evil.example.test/checkout/?uuid=' . self::UUID,
            'https://user@v3.paytaro.com/checkout/?uuid=' . self::UUID,
            'https://v3.paytaro.com:444/checkout/?uuid=' . self::UUID,
            'https://v3.paytaro.com/other/?uuid=' . self::UUID,
            'https://v3.paytaro.com/checkout/?uuid=wrong',
            'https://v3.paytaro.com/checkout/?uuid[]=wrong', self::CHECKOUT . '#fragment', []] as $url) {
            $results[] = [['checkout_url' => $url, 'uuid' => self::UUID]];
        }

        return $results;
    }

    #[DataProvider('acceptedAmounts')]
    public function test_paid_callback_uses_original_cny_amount(mixed $amount, int $cents): void
    {
        foreach (['PAID', 'SUCCESS'] as $status) {
            $request = $this->bindCallback(array_replace($this->callbackPayload(), [
                'status' => $status, 'order_amount' => $amount, 'pay_currency' => 'USDT', 'pay_amount' => '1.500001',
            ]));
            $this->assertSame(['trade_no' => 'trade-1', 'callback_no' => 'gateway-1', 'paid_amount' => $cents], $this->plugin->notify($request->input()));
        }
    }

    public static function acceptedAmounts(): array
    {
        return [[10, 1000], [10.5, 1050], ['10.50', 1050], [0.29, 29], ['0.01', 1], ['001.01', 101], ['21474836.47', 2147483647]];
    }

    #[DataProvider('invalidCallbacks')]
    public function test_invalid_callback_is_rejected(array $overrides): void
    {
        $request = $this->bindCallback(array_replace($this->callbackPayload(), $overrides));
        $this->assertFalse($this->plugin->notify($request->input()));
    }

    public static function invalidCallbacks(): array
    {
        $results = [];
        foreach ([null, '', 0, -1, 10.501, true, [], '10abc', '1e2', 'NaN', ' 10 ', '0.00', '999999999999999999999999'] as $amount) {
            $results[] = [['order_amount' => $amount]];
        }
        foreach (['UNPAID', 'CANCEL', 'REFUNDED', 'paid', null, []] as $status) {
            $results[] = [['status' => $status]];
        }
        foreach (['app_id', 'merchant_no', 'transaction_no', 'order_currency'] as $field) {
            foreach ([null, '', []] as $value) {
                $results[] = [[$field => $value]];
            }
        }
        $results[] = [['app_id' => 'another-app']];
        $results[] = [['order_currency' => 'USD']];

        return $results;
    }

    public function test_secret_must_be_in_header_and_match_configured_app(): void
    {
        foreach ([null, '', 'wrong-secret'] as $secret) {
            $request = $this->bindCallback($this->callbackPayload() + ['app_secret' => 'test-secret', 'X-App-Secret' => 'test-secret'], $secret);
            $request->query->set('X-App-Secret', 'test-secret');
            $this->assertFalse($this->plugin->notify($request->input()));
        }
        $request = $this->bindCallback($this->callbackPayload());
        $this->plugin->setConfig(['app_id' => 'app-1', 'app_secret' => '']);
        $this->assertFalse($this->plugin->notify($request->input()));
    }

    public function test_only_post_json_body_is_used_not_query_or_notify_argument(): void
    {
        $request = $this->bindCallback($this->callbackPayload());
        $request->query->replace(['merchant_no' => 'other-order', 'order_amount' => '0.01']);
        $this->assertSame('trade-1', $this->plugin->notify(['merchant_no' => 'other-order'])['trade_no']);

        $body = $this->callbackPayload();
        unset($body['order_amount']);
        $request = $this->bindCallback($body);
        $request->query->set('order_amount', '10.50');
        $this->assertFalse($this->plugin->notify($request->input()));

        foreach ([['GET', 'application/json', json_encode($this->callbackPayload())],
            ['POST', 'application/x-www-form-urlencoded', http_build_query($this->callbackPayload())],
            ['POST', 'application/json', '{broken'], ['POST', 'application/json', 'null']] as [$method, $type, $content]) {
            $request = Request::create('/notify', $method, [], [], [], ['CONTENT_TYPE' => $type, 'HTTP_X_APP_SECRET' => 'test-secret'], $content);
            app()->instance('request', $request);
            $this->assertFalse($this->plugin->notify($this->callbackPayload()));
        }
    }

    public function test_real_payment_service_preserves_agent_return_and_notify_domains(): void
    {
        [$payment] = $this->preparePaymentFlow();
        Http::fake([self::ENDPOINT => Http::response(['checkout_url' => self::CHECKOUT, 'uuid' => self::UUID])]);
        $service = new PaymentService('PayTaro', $payment->id);
        $this->assertSame('app-1', $service->form()['app_id']['value']);
        $result = $service->pay([
            'trade_no' => 'trade-1', 'total_amount' => 1050, 'user_id' => 1, 'stripe_token' => null,
            'return_base_url' => 'https://agent.example.test',
        ]);
        $this->assertSame(['type' => 1, 'data' => self::CHECKOUT], $result);
        Http::assertSent(fn ($request) => $request['notify_url'] === 'https://notify.example.test/api/v1/guest/payment/notify/PayTaro/agent-1'
            && $request['return_url'] === 'https://agent.example.test/#/pay-success?trade_no=trade-1'
            && $request['order_amount'] === 10.5);
    }

    public function test_callback_controller_completes_order_once_including_manual_replay(): void
    {
        [$payment, $order, $user] = $this->preparePaymentFlow();
        foreach (['PAID', 'SUCCESS', 'SUCCESS'] as $status) {
            $request = $this->bindCallback(array_replace($this->callbackPayload(), ['status' => $status]));
            $this->assertSame('success', (new PaymentController())->notify('PayTaro', $payment->uuid, $request));
            $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
            $this->assertSame(1300, (int) $user->fresh()->balance);
            $this->assertSame('gateway-1', $order->fresh()->callback_no);
        }
    }

    #[DataProvider('rejectedPaymentFlows')]
    public function test_callback_failures_return_non_200_and_do_not_credit(string $scenario, int $expectedStatus): void
    {
        [$payment, $order, $user] = $this->preparePaymentFlow();
        $body = $this->callbackPayload();
        $secret = 'test-secret';
        switch ($scenario) {
            case 'amount': $body['order_amount'] = 10; break;
            case 'payment': $order->update(['payment_id' => $payment->id + 1]); break;
            case 'unknown_order': $body['merchant_no'] = 'missing'; break;
            case 'app': $body['app_id'] = 'other-app'; break;
            case 'secret': $secret = 'other-secret'; break;
            case 'unpaid': $body['status'] = 'UNPAID'; break;
            case 'disabled': $payment->update(['enable' => false]); break;
        }
        $request = $this->bindCallback($body, $secret);
        $response = (new PaymentController())->notify('PayTaro', $payment->uuid, $request);
        $this->assertSame($expectedStatus, $response->getStatusCode());
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(100, (int) $user->fresh()->balance);
    }

    public static function rejectedPaymentFlows(): array
    {
        return [['amount', 400], ['payment', 400], ['unknown_order', 400], ['app', 422], ['secret', 422], ['unpaid', 422], ['disabled', 500]];
    }

    private function orderPayload(): array
    {
        return ['trade_no' => 'trade-1', 'total_amount' => 1234,
            'notify_url' => 'https://notify.example.test/api/v1/guest/payment/notify/PayTaro/agent-1',
            'return_url' => 'https://agent.example.test/#/pay-success?trade_no=trade-1'];
    }

    private function callbackPayload(): array
    {
        return ['app_id' => 'app-1', 'merchant_no' => 'trade-1', 'transaction_no' => 'gateway-1',
            'callback_no' => 'channel-1', 'status' => 'PAID', 'order_currency' => 'CNY',
            'order_amount' => '10.50', 'pay_currency' => 'CNY', 'pay_amount' => '10.50'];
    }

    private function bindCallback(array $body, ?string $secret = 'test-secret'): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_APP_SECRET'] = $secret;
        }
        $request = Request::create('/api/v1/guest/payment/notify/PayTaro/agent-1', 'POST', [], [], [], $server, json_encode($body, JSON_THROW_ON_ERROR));
        app()->instance('request', $request);

        return $request;
    }

    private function preparePaymentFlow(): array
    {
        $this->setUpInMemoryDatabase();
        $this->bindSynchronousBusDispatcher();
        $this->bindJsonResponseFactory();
        $this->bindTestUrlGenerator('https://panel.example.test');
        $this->createUserTable();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->plugin->boot();
        $manager = $this->createMock(PluginManager::class);
        $manager->method('getEnabledPaymentPlugins')->willReturn([$this->plugin]);
        app()->instance(PluginManager::class, $manager);
        $payment = Payment::create(['uuid' => 'agent-1', 'payment' => 'PayTaro', 'name' => 'PayTaro', 'enable' => true,
            'owner_type' => 'agent', 'owner_id' => 20, 'owner_domain_id' => 30,
            'notify_domain' => 'https://notify.example.test', 'config' => ['app_id' => 'app-1', 'app_secret' => 'test-secret', 'checkout_mode' => 'cashier']]);
        $user = User::create(['email' => 'paytaro@example.test', 'password' => 'unused-test-password', 'token' => 'test-token', 'uuid' => 'test-user', 'balance' => 100]);
        $order = Order::create(['user_id' => $user->id, 'plan_id' => 0, 'payment_id' => $payment->id,
            'type' => Order::TYPE_RECHARGE, 'period' => 'recharge', 'trade_no' => 'trade-1',
            'total_amount' => 1000, 'handling_amount' => 50, 'bonus_amount' => 200, 'status' => Order::STATUS_PENDING]);

        return [$payment, $order, $user];
    }
}

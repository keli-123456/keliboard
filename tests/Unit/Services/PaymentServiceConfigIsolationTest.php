<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Plugin\Paytaro\Plugin;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PaymentServiceConfigIsolationTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private const ALIPAY = '00000000-0000-4000-8000-000000000001';
    private const USDT = '00000000-0000-4000-8000-000000000002';
    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createPaymentTable();
        $this->bindTestUrlGenerator('https://panel.example.test');
        Http::preventStrayRequests();
        $this->plugin = new Plugin('paytaro');
        $this->plugin->setConfig(['display_name' => 'PayTaro']);
        $this->plugin->boot();
        app()->instance(PluginManager::class, new class($this->plugin) extends PluginManager {
            public function __construct(private Plugin $paymentPlugin) {}

            public function getEnabledPaymentPlugins(): array
            {
                return ['paytaro' => $this->paymentPlugin];
            }
        });
    }

    public function test_preparing_usdt_does_not_change_an_existing_alipay_checkout(): void
    {
        $alipay = $this->payment(self::ALIPAY, 'alipay');
        $usdt = $this->payment(self::USDT, 'usdt');
        $alipayService = new PaymentService('PayTaro', $alipay->id);
        $usdtService = new PaymentService('PayTaro', $usdt->id);
        $this->fakeInvoices();

        $this->assertSame('fiat', $alipayService->pay($this->order())['data']['currency_type']);
        $this->assertSame('USDT', $usdtService->pay($this->order())['data']['currency']);
        Http::assertSentCount(2);
        $requests = Http::recorded()->pluck(0);
        $this->assertSame(self::ALIPAY, $requests[0]['method_uuid']);
        $this->assertSame(self::USDT, $requests[1]['method_uuid']);
        $this->assertTrue($requests[0]->hasHeader('X-App-Secret', 'secret-alipay'));
        $this->assertTrue($requests[1]->hasHeader('X-App-Secret', 'secret-usdt'));
        $this->assertStringEndsWith('/PayTaro/alipay', $requests[0]['notify_url']);
        $this->assertStringEndsWith('/PayTaro/usdt', $requests[1]['notify_url']);
    }

    public function test_new_checkout_uses_saved_uuid_without_mutating_earlier_service(): void
    {
        $payment = $this->payment(self::USDT, 'channel');
        $previous = new PaymentService('PayTaro', $payment->id);
        $payment->update(['config' => array_replace($payment->config, ['method_uuid' => self::ALIPAY])]);
        $current = new PaymentService('PayTaro', $payment->id);
        $this->fakeInvoices();

        $this->assertSame(self::ALIPAY, $current->form()['method_uuid']['value']);
        $this->assertSame('CNY', $current->pay($this->order())['data']['currency']);
        $this->assertSame('USDT', $previous->pay($this->order())['data']['currency']);
    }

    public function test_channel_configuration_does_not_modify_registered_plugin_defaults(): void
    {
        $payment = $this->payment(self::ALIPAY, 'alipay');
        new PaymentService('PayTaro', $payment->id);

        $this->assertSame(['display_name' => 'PayTaro'], $this->plugin->getConfig());
        $blank = new PaymentService('PayTaro');
        $this->assertSame('', $blank->form()['method_uuid']['value']);
        $this->assertSame('', $blank->form()['app_secret']['value']);
    }

    public function test_callback_keeps_its_own_secret_after_another_channel_is_loaded(): void
    {
        $alipay = $this->payment(self::ALIPAY, 'alipay');
        $usdt = $this->payment(self::USDT, 'usdt');
        $callback = new PaymentService('PayTaro', null, $alipay->uuid);
        new PaymentService('PayTaro', $usdt->id);
        $body = ['app_id' => 'app-alipay', 'merchant_no' => 'trade-test', 'transaction_no' => 'gateway-test',
            'status' => 'PAID', 'order_currency' => 'CNY', 'order_amount' => 10.5];
        $request = Request::create('/notify', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_APP_SECRET' => 'secret-alipay',
        ], json_encode($body));
        app()->instance('request', $request);

        $this->assertSame(['trade_no' => 'trade-test', 'callback_no' => 'gateway-test', 'paid_amount' => 1050], $callback->notify([]));
        $request->headers->set('X-App-Secret', 'secret-usdt');
        $this->assertFalse($callback->notify([]));
        $this->assertSame($alipay->id, $callback->getPaymentId());
        Http::assertNothingSent();
    }

    private function payment(string $methodUuid, string $name): Payment
    {
        return Payment::create(['name' => $name, 'uuid' => $name, 'payment' => 'PayTaro', 'enable' => true,
            'config' => ['app_id' => 'app-' . $name, 'app_secret' => 'secret-' . $name,
                'checkout_mode' => 'inline', 'method_uuid' => $methodUuid]]);
    }

    private function order(): array
    {
        return ['trade_no' => 'trade-test', 'total_amount' => 1050, 'user_id' => 1, 'stripe_token' => null,
            'return_base_url' => 'https://panel.example.test'];
    }

    private function fakeInvoices(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('https://v3.paytaro.com/v1/invoice/pay', $request->url());
            $this->assertSame('POST', $request->method());
            $crypto = $request['method_uuid'] === self::USDT;
            return Http::response(['merchant_no' => $request['merchant_no'],
                'uuid' => '00000000-0000-4000-8000-000000000003', 'status' => 'UNPAID',
                'order_currency' => 'CNY', 'order_amount' => $request['order_amount'],
                'expired_at' => 1700001800, 'server_time' => 1700000000,
                'payment' => $crypto
                    ? ['currency_type' => 'crypto', 'type' => 'TRON', 'pay_currency' => 'USDT',
                        'pay_amount' => '1.5', 'link_type' => 'address', 'data' => 'TTestAddress1234567890']
                    : ['currency_type' => 'fiat', 'type' => 'alipay', 'pay_currency' => 'CNY',
                        'pay_amount' => 10.5, 'link_type' => 'h5',
                        'data' => 'https://openapi.alipay.com/gateway.do?method=alipay.trade.wap.pay&sign=test'],
            ]);
        });
    }
}

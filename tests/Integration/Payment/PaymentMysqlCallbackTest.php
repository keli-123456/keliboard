<?php

declare(strict_types=1);

namespace Tests\Integration\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\GiftCardMysqlFixture;

final class PaymentMysqlCallbackTest extends TestCase
{
    private GiftCardMysqlFixture $fixture;
    private \Illuminate\Routing\Router $router;
    private Payment $channel;
    private User $user;
    private Order $order;

    protected function setUp(): void
    {
        $this->fixture = new GiftCardMysqlFixture();
        $this->fixture->createSchema();
        $this->router = $this->fixture->paymentRouter();
        $this->user = $this->fixture->user();
        $this->channel = Payment::create(['name' => 'Isolated gateway fixture', 'uuid' => 'fixture-gateway', 'payment' => 'EPay',
            'enable' => true, 'config' => ['key' => 'fixture-signing-key', 'pid' => 'fixture-merchant']]);
        $this->order = Order::create(['user_id' => $this->user->id, 'plan_id' => 0, 'payment_id' => $this->channel->id,
            'type' => Order::TYPE_RECHARGE, 'period' => 'recharge', 'trade_no' => 'fixture-order',
            'total_amount' => 1234, 'handling_amount' => 0, 'bonus_amount' => 0, 'status' => Order::STATUS_PENDING]);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function test_valid_signed_http_callbacks_credit_the_order_only_once(): void
    {
        foreach ([1, 2] as $attempt) {
            $response = $this->callback($this->payload());
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('success', $response->getContent());
            $this->assertSame(1234, (int) $this->user->fresh()->balance);
            $this->assertSame(Order::STATUS_COMPLETED, (int) $this->order->fresh()->status);
        }
    }

    #[DataProvider('invalidReceipts')]
    public function test_invalid_receipts_never_credit_the_order(array $changes): void
    {
        $response = $this->callback($this->payload($changes));
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertSame(0, (int) $this->user->fresh()->balance);
        $this->assertSame(Order::STATUS_PENDING, (int) $this->order->fresh()->status);
        $this->assertNull($this->order->fresh()->paid_at);
    }

    public static function invalidReceipts(): array
    {
        return [[['pid' => 'another-merchant']], [['money' => '0.01']],
            [['sign' => str_repeat('0', 32)]], [['trade_status' => 'WAIT_BUYER_PAY']]];
    }

    public function test_callback_path_cannot_select_a_different_gateway_for_the_channel(): void
    {
        $response = $this->callback($this->payload(), 'MGate');
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertSame(0, (int) $this->user->fresh()->balance);
        $this->assertSame(Order::STATUS_PENDING, (int) $this->order->fresh()->status);
    }

    private function payload(array $changes = []): array
    {
        $params = array_replace(['pid' => 'fixture-merchant', 'out_trade_no' => 'fixture-order',
            'trade_no' => 'fixture-receipt', 'trade_status' => 'TRADE_SUCCESS', 'money' => '12.34'], $changes);
        $signed = $params;
        unset($signed['sign'], $signed['sign_type']);
        ksort($signed);
        $params['sign'] ??= md5(stripslashes(urldecode(http_build_query($signed))) . 'fixture-signing-key');
        $params['sign_type'] = 'MD5';
        return $params;
    }

    private function callback(array $params, string $method = 'EPay'): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/api/v1/guest/payment/notify/' . $method . '/' . $this->channel->uuid, 'POST', $params);
        app()->instance('request', $request);
        app()->instance(Request::class, $request);
        return $this->router->dispatch($request);
    }
}

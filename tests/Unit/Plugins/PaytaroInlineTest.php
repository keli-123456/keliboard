<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugin\Paytaro\Plugin;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class PaytaroInlineTest extends TestCase
{
    private const UUID = 'e5b62e61-1dff-41ed-b6ce-45404b0b60da';
    private const URL = 'https://openapi.alipay.com/gateway.do?method=alipay.trade.wap.pay&sign=a%2Bb%3D%3D';
    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app()->instance('log', new NullLogger());
        $this->plugin = new Plugin('paytaro');
        $this->plugin->setConfig(['app_id' => 'app-1', 'app_secret' => 'test-secret', 'method_uuid' => self::UUID]);
    }

    public function test_defaults_to_native_alipay_without_rewriting_signed_link(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('https://v3.paytaro.com/v1/invoice/pay', $request->url());
            $this->assertSame(self::UUID, $request['method_uuid']);
            $this->assertSame(10.5, $request['order_amount']);
            $this->assertTrue($request->hasHeader('X-App-Secret', 'test-secret'));
            return Http::response($this->invoice());
        });
        $result = $this->plugin->pay($this->order());
        $this->assertSame(0, $result['type']);
        $this->assertSame('paytaro', $result['data']['provider']);
        $this->assertSame(self::URL, $result['data']['qr_data']);
        $this->assertSame(self::URL, $result['data']['mobile_url']);
        $this->assertSame('10.815', $result['data']['amount']);
        $this->assertSame('10.50', $result['data']['fiat_amount']);
        $this->assertSame(1800, $result['data']['expires_in']);
        $this->assertSame('', $result['data']['address']);
        $this->assertArrayNotHasKey('app_secret', $result['data']);
        $this->assertArrayNotHasKey('uuid', $result['data']);
        Http::assertSentCount(1);
    }

    public function test_pc_payment_preserves_mobile_deep_link(): void
    {
        $body = $this->invoice();
        $body['payment']['link_type'] = 'pc';
        $body['payment']['mobile_url'] = 'alipays://platformapi/startapp?appId=20000067&url=' . rawurlencode(self::URL);
        Http::fake(['*' => Http::response($body)]);
        $result = $this->plugin->pay($this->order());
        $this->assertSame($body['payment']['mobile_url'], $result['data']['mobile_url']);
    }

    #[DataProvider('cryptoAmounts')]
    public function test_crypto_address_amount_network_and_server_clock_are_returned(mixed $amount, string $expected): void
    {
        $body = $this->invoice();
        $body['payment'] = ['data' => 'TTestAddress1234567890', 'pay_amount' => $amount, 'type' => 'tron',
            'name' => 'USDT-TRC20', 'currency_type' => 'crypto', 'pay_currency' => 'USDT', 'link_type' => 'address'];
        Http::fake(['*' => Http::response($body)]);
        $data = $this->plugin->pay($this->order())['data'];
        $this->assertSame($expected, $data['amount']);
        $this->assertSame('USDT', $data['currency']);
        $this->assertSame('TRON', $data['network']);
        $this->assertSame('TTestAddress1234567890', $data['address']);
        $this->assertSame($data['address'], $data['qr_data']);
        $this->assertSame('', $data['payment_url']);
        $this->assertSame('', $data['mobile_url']);
        $this->assertSame(1800, $data['expires_in']);
    }

    public static function cryptoAmounts(): array
    {
        return [['1.123456789012345678', '1.123456789012345678'], [1.000001, '1.000001'], [0.00000012, '0.00000012'], [12, '12']];
    }

    public function test_usdt_accepts_exact_decimal_and_timestamp_strings_without_changing_address_or_quantity(): void
    {
        $body = $this->cryptoInvoice();
        $body['order_amount'] = '10.50000000';
        $body['expired_at'] = '1700001800';
        $body['server_time'] = '1700000000';
        $body['payment']['currency_type'] = ' CRYPTO ';
        $body['payment']['type'] = ' TRON ';
        $body['payment']['pay_currency'] = 'usdt';
        $body['payment']['link_type'] = 'ADDRESS';
        Http::fake(['*' => Http::response($body)]);

        $data = $this->plugin->pay($this->order())['data'];

        $this->assertSame('crypto', $data['currency_type']);
        $this->assertSame('address', $data['link_type']);
        $this->assertSame('TRON', $data['network']);
        $this->assertSame('USDT', $data['currency']);
        $this->assertSame('1.123456789012345678', $data['amount']);
        $this->assertSame($body['payment']['data'], $data['qr_data']);
        $this->assertSame($body['payment']['data'], $data['address']);
        $this->assertSame('10.50', $data['fiat_amount']);
        $this->assertSame(1700001800, $data['expiration_time']);
        $this->assertSame(1700000000, $data['server_time']);
        $this->assertSame(1800, $data['expires_in']);
        $this->assertSame('', $data['payment_url']);
        $this->assertSame('', $data['mobile_url']);
        Http::assertSentCount(1);
    }

    #[DataProvider('omittedCryptoLinkTypes')]
    public function test_crypto_can_omit_link_type_without_changing_address_amount_or_network(array $hint): void
    {
        $body = $this->cryptoInvoice();
        unset($body['payment']['link_type']);
        $body['payment'] = array_replace($body['payment'], $hint);
        Http::fake(['*' => Http::response($body)]);
        $data = $this->plugin->pay($this->order())['data'];
        $this->assertSame('address', $data['link_type']);
        $this->assertSame('crypto', $data['currency_type']);
        $this->assertSame('USDT', $data['currency']);
        $this->assertSame('TRON', $data['network']);
        $this->assertSame($body['payment']['data'], $data['address']);
        $this->assertSame($body['payment']['data'], $data['qr_data']);
        $this->assertSame($body['payment']['pay_amount'], $data['amount']);
        $this->assertSame('', $data['payment_url']);
        $this->assertSame('', $data['mobile_url']);
        Http::assertSentCount(1);
    }

    public static function omittedCryptoLinkTypes(): array
    {
        return [[[]], [['link_type' => null]], [['link_type' => '']], [['link_type' => '  ']]];
    }

    #[DataProvider('logShapedCryptoPayments')]
    public function test_log_shaped_crypto_response_preserves_payment_or_reports_precise_error(array $changes, ?string $reason): void
    {
        $body = $this->cryptoInvoice();
        // Synthetic address: reproduce the reported length, never use a real recipient.
        $body['payment'] = array_replace($body['payment'], [
            'data' => 'T' . str_repeat('1', 33),
            'pay_amount' => 16.906408,
            'mobile_url' => null,
        ], $changes);
        $this->assertIsFloat($body['order_amount']);
        $this->assertIsFloat($body['payment']['pay_amount']);
        $this->assertIsString($body['payment']['type']);
        $this->assertIsString($body['payment']['link_type']);
        $this->assertSame(34, strlen($body['payment']['data']));
        Http::fake(['*' => Http::response($body)]);

        if ($reason !== null) {
            try {
                $this->plugin->pay($this->order());
                $this->fail('Invalid crypto metadata was accepted.');
            } catch (ApiException $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
                Http::assertSentCount(1);
            }
            return;
        }

        $data = $this->plugin->pay($this->order())['data'];
        $this->assertSame('16.906408', $data['amount']);
        $this->assertSame('10.50', $data['fiat_amount']);
        $this->assertSame('USDT', $data['currency']);
        $this->assertSame('TRON', $data['network']);
        $this->assertSame('address', $data['link_type']);
        $this->assertSame($body['payment']['data'], $data['address']);
        $this->assertSame($body['payment']['data'], $data['qr_data']);
        $this->assertSame('', $data['mobile_url']);
        $this->assertSame('', $data['payment_url']);
        Http::assertSentCount(1);
    }

    public static function logShapedCryptoPayments(): array
    {
        return [
            'documented-address-hint' => [[], null],
            'empty-string-hint' => [['link_type' => ''], null],
            'blank-string-hint' => [['link_type' => '  '], null],
            'conflicting-alipay-hint' => [['link_type' => 'h5'], 'PT_CRYPTO_LINK'],
            'empty-network-string' => [['type' => ''], 'PT_CRYPTO_NETWORK'],
            'invalid-character-at-same-length' => [['data' => 'T' . str_repeat('1', 32) . '<'], 'PT_CRYPTO_ADDRESS'],
        ];
    }

    #[DataProvider('invalidCryptoDetails')]
    public function test_crypto_fallback_never_bypasses_network_address_or_explicit_type_checks(array $changes, string $reason): void
    {
        $body = $this->cryptoInvoice();
        unset($body['payment']['link_type']);
        $body['payment'] = array_replace($body['payment'], $changes);
        Http::fake(['*' => Http::response($body)]);
        $this->expectExceptionMessage($reason);
        $this->plugin->pay($this->order());
    }

    public static function invalidCryptoDetails(): array
    {
        return [
            [['link_type' => 'h5'], 'PT_CRYPTO_LINK'],
            [['link_type' => 'pc'], 'PT_CRYPTO_LINK'],
            [['link_type' => 'url'], 'PT_CRYPTO_LINK'],
            [['link_type' => false], 'PT_CRYPTO_LINK'],
            [['link_type' => []], 'PT_CRYPTO_LINK'],
            [['type' => null], 'PT_CRYPTO_NETWORK'],
            [['type' => ''], 'PT_CRYPTO_NETWORK'],
            [['type' => 'unknown<script>'], 'PT_CRYPTO_NETWORK'],
            [['data' => 'https://example.test/payment'], 'PT_CRYPTO_ADDRESS'],
            [['data' => '<script>'], 'PT_CRYPTO_ADDRESS'],
            [['data' => 'javascript:alert(1)'], 'PT_CRYPTO_ADDRESS'],
            [['data' => str_repeat('T', 257)], 'PT_CRYPTO_ADDRESS'],
        ];
    }

    #[DataProvider('malformedUsdtInvoices')]
    public function test_usdt_rejects_invalid_data_with_a_specific_reason(array $changes, string $reason): void
    {
        Http::fake(['*' => Http::response(array_replace($this->cryptoInvoice(), $changes))]);
        try {
            $this->plugin->pay($this->order());
            $this->fail('Invalid USDT data was accepted.');
        } catch (ApiException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public static function malformedUsdtInvoices(): array
    {
        return [
            [['order_currency' => 'USDT'], 'PT_CURRENCY'],
            [['order_amount' => '10.50000001'], 'PT_AMOUNT'],
            [['order_amount' => '10.49'], 'PT_AMOUNT'],
            [['order_amount' => '10.51'], 'PT_AMOUNT'],
            [['order_amount' => true], 'PT_AMOUNT'],
            [['merchant_no' => 'other'], 'PT_ORDER'],
            [['uuid' => null], 'PT_ORDER'],
            [['status' => 'SUCCESS'], 'PT_STATUS'],
            [['payment' => null], 'PT_PAYMENT'],
            [['expired_at' => '1700000000'], 'PT_EXPIRED'],
            [['expired_at' => '1699999999'], 'PT_EXPIRED'],
            [['expired_at' => '1700001800000'], 'PT_TIME'],
            [['expired_at' => '1700001800.9'], 'PT_TIME'],
            [['server_time' => true], 'PT_TIME'],
            [['server_time' => '1700000000abc'], 'PT_TIME'],
            [['server_time' => '1.7e9'], 'PT_TIME'],
            [['server_time' => '99999999999999999999999'], 'PT_TIME'],
            [['server_time' => null], 'PT_TIME'],
        ];
    }

    public function test_currency_error_explains_cny_order_currency_does_not_disable_usdt_channel(): void
    {
        Http::fake(['*' => Http::response(array_replace($this->cryptoInvoice(), ['order_currency' => 'USDT']))]);
        $this->expectExceptionMessage('应用订单币种须为 CNY；USDT 收款渠道可以继续使用');
        $this->plugin->pay($this->order());
    }

    public function test_diagnostics_record_only_field_types_not_payment_or_customer_secrets(): void
    {
        $body = $this->cryptoInvoice();
        $body['server_time'] = 'not-a-time';
        $body['app_secret'] = 'upstream-secret';
        $logged = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->willReturnCallback(
            function ($message, array $context) use (&$logged): void { $logged = [$message, $context]; }
        );
        app()->instance('log', $logger);
        Http::fake(['*' => Http::response($body)]);
        try {
            $this->plugin->pay($this->order());
            $this->fail('Invalid timestamp was accepted.');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('PT_TIME', $exception->getMessage());
        }
        $this->assertIsArray($logged);
        $this->assertSame('PayTaro payment response rejected', $logged[0]);
        $context = $logged[1];
        $this->assertSame('PT_TIME', $context['reason']);
        $this->assertSame('string', $context['field_types']['server_time']);
        $this->assertSame('string', $context['field_types']['payment.data']);
        $this->assertSame(strlen($body['payment']['data']), $context['payment_data_length']);
        $serialized = json_encode($context);
        foreach (['test-secret', 'upstream-secret', 'trade-1', self::UUID, self::URL,
            $body['payment']['data'], '1.123456789012345678', 'not-a-time'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_non_json_response_is_reported_without_exposing_the_upstream_body(): void
    {
        Http::fake(['*' => Http::response('<html>private-upstream-data</html>', 200)]);
        try {
            $this->plugin->pay($this->order());
            $this->fail('Non-JSON response was accepted.');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('PT_RESPONSE', $exception->getMessage());
            $this->assertStringNotContainsString('private-upstream-data', $exception->getMessage());
        }
    }

    #[DataProvider('absentMobileLinks')]
    public function test_absent_alipay_mobile_link_uses_only_the_validated_signed_payment_url(string $type, mixed $mobile): void
    {
        $body = $this->invoice();
        $body['payment']['link_type'] = $type;
        $body['payment']['mobile_url'] = $mobile;
        Http::fake(['*' => Http::response($body)]);
        $data = $this->plugin->pay($this->order())['data'];
        $this->assertSame(self::URL, $data['qr_data']);
        $this->assertSame($type === 'h5' ? self::URL
            : 'alipays://platformapi/startapp?appId=20000067&url=' . rawurlencode(self::URL), $data['mobile_url']);
    }

    public static function absentMobileLinks(): array
    {
        return [['h5', null], ['h5', ''], ['pc', null], ['pc', '']];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_or_mismatched_native_data_is_rejected(array $invoiceChanges, array $paymentChanges): void
    {
        $body = array_replace($this->invoice(), $invoiceChanges);
        $body['payment'] = array_replace($body['payment'], $paymentChanges);
        Http::fake(['*' => Http::response($body)]);
        $this->expectException(ApiException::class);
        $this->plugin->pay($this->order());
    }

    public static function invalidResponses(): array
    {
        $cases = [];
        foreach ([['merchant_no' => 'other'], ['order_amount' => 10], ['order_currency' => 'USD'], ['status' => 'PAID'],
            ['uuid' => 'bad'], ['expired_at' => 1700000000], ['server_time' => null]] as $change) {
            $cases[] = [$change, []];
        }
        foreach ([['link_type' => 'bad'], ['currency_type' => 'bad'], ['pay_currency' => 'USD'], ['pay_amount' => 0],
            ['pay_amount' => -1], ['pay_amount' => []], ['pay_amount' => 'abc'], ['data' => 'javascript:alert(1)'],
            ['data' => 'http://openapi.alipay.com/pay'], ['data' => 'https://alipay.com.evil.test/pay'],
            ['data' => 'https://user:pass@openapi.alipay.com/pay'], ['data' => 'https://openapi.alipay.com:444/pay'],
            ['mobile_url' => 'javascript:alert(1)'], ['mobile_url' => 'https://evil.test/pay'],
            ['mobile_url' => 'alipays://platformapi/startapp?appId=wrong&url=' . rawurlencode(self::URL)],
            ['mobile_url' => 'alipays://platformapi/startapp?appId=20000067&url=' . rawurlencode('https://evil.test/pay')],
            ['mobile_url' => 'alipays://evil/startapp?appId=20000067&url=' . rawurlencode(self::URL)],
            ['currency_type' => 'crypto', 'link_type' => 'h5'],
            ['currency_type' => 'crypto', 'link_type' => 'address', 'type' => 'tron', 'data' => '<script>'],
            ['data' => str_repeat('x', 3000)]] as $change) {
            $cases[] = [[], $change];
        }
        return $cases;
    }

    public function test_native_mode_requires_channel_uuid_before_request(): void
    {
        Http::fake();
        $this->plugin->setConfig(['app_id' => 'app-1', 'app_secret' => 'test-secret']);
        try {
            $this->plugin->pay($this->order());
            $this->fail('Missing channel was accepted.');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('UUID', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    private function order(): array
    {
        return ['trade_no' => 'trade-1', 'total_amount' => 1050, 'notify_url' => 'https://notify.example.test/notify',
            'return_url' => 'https://agent.example.test/#/pay-success?trade_no=trade-1'];
    }

    private function invoice(): array
    {
        return ['merchant_no' => 'trade-1', 'uuid' => self::UUID, 'transaction_no' => 'gateway-1', 'status' => 'UNPAID',
            'order_currency' => 'CNY', 'order_amount' => 10.5, 'expired_at' => 1700001800, 'server_time' => 1700000000,
            'payment' => ['data' => self::URL, 'mobile_url' => self::URL, 'pay_amount' => '10.815', 'type' => 'alipay',
                'name' => 'Alipay', 'currency_type' => 'fiat', 'pay_currency' => 'CNY', 'link_type' => 'h5']];
    }

    private function cryptoInvoice(): array
    {
        return array_replace($this->invoice(), ['payment' => [
            'data' => 'TTestAddress1234567890', 'pay_amount' => '1.123456789012345678', 'type' => 'tron',
            'name' => 'USDT-TRC20', 'currency_type' => 'crypto', 'pay_currency' => 'USDT', 'link_type' => 'address',
        ]]);
    }
}

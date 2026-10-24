<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use Plugin\Epay\Plugin as Epay;
use Plugin\Mgate\Plugin as Mgate;
use Illuminate\Http\Request;
use Tests\TestCase;

final class PaymentCallbackHardeningTest extends TestCase
{
    #[DataProvider('emptySecrets')]
    public function test_empty_secrets_cannot_authenticate_callbacks(mixed $secret): void
    {
        $epay = new Epay('epay');
        $epay->setConfig(['key' => $secret, 'pid' => 'merchant-1']);
        $mgate = new Mgate('mgate');
        $mgate->setConfig(['mgate_app_secret' => $secret, 'mgate_app_id' => 'app-1']);

        $this->assertFalse($epay->notify($this->epayPayload([], is_string($secret) ? $secret : '')));
        $this->assertFalse($mgate->notify($this->mgatePayload([], is_string($secret) ? $secret : '')));
        foreach ([\Plugin\Bepusdt\Plugin::class => 'bepusdt_apitoken',
            \Plugin\Btcpay\Plugin::class => 'btcpay_webhook_key',
            \Plugin\Coinbase\Plugin::class => 'coinbase_webhook_key'] as $class => $key) {
            $plugin = new $class('test');
            $plugin->setConfig([$key => $secret]);
            $this->assertFalse($plugin->notify([]));
        }
    }

    public static function emptySecrets(): array
    {
        return [[null], [''], ['  '], [false], [0], [[]]];
    }

    #[DataProvider('malformedPayloads')]
    public function test_non_array_payloads_are_rejected(mixed $payload): void
    {
        $epay = new Epay('epay');
        $epay->setConfig(['key' => 'secret', 'pid' => 'merchant-1']);
        $mgate = new Mgate('mgate');
        $mgate->setConfig(['mgate_app_secret' => 'secret']);
        $this->assertFalse($epay->notify($payload));
        $this->assertFalse($mgate->notify($payload));
    }

    public static function malformedPayloads(): array
    {
        return [[null], [false], ['signed-string'], [42], [new \stdClass()]];
    }

    #[DataProvider('epayMutations')]
    public function test_epay_rejects_malformed_or_wrong_merchant_receipts(array $changes): void
    {
        $plugin = new Epay('epay');
        $plugin->setConfig(['key' => 'secret', 'pid' => 'merchant-1']);
        $this->assertFalse($plugin->notify($this->epayPayload($changes)));
    }

    public static function epayMutations(): array
    {
        return [
            [['pid' => 'other-merchant']], [['pid' => null]], [['pid' => []]],
            [['sign' => []]], [['sign' => str_repeat('0', 32)]], [['sign_type' => 'RSA']],
            [['out_trade_no' => ' ']], [['trade_no' => 123]], [['trade_no' => []]],
            [['money' => true]], [['money' => '12.341']], [['money' => '-12.34']],
            [['money' => '12.34junk']], [['money' => []]], [['extra' => []]],
            [['trade_status' => 'WAIT_BUYER_PAY']],
        ];
    }

    #[DataProvider('mgateMutations')]
    public function test_mgate_rejects_malformed_or_wrong_application_receipts(array $changes): void
    {
        $plugin = new Mgate('mgate');
        $plugin->setConfig(['mgate_app_secret' => 'secret', 'mgate_app_id' => 'app-1']);
        $this->assertFalse($plugin->notify($this->mgatePayload($changes)));
    }

    public static function mgateMutations(): array
    {
        return [
            [['app_id' => 'other-app']], [['sign' => []]], [['sign' => str_repeat('0', 32)]],
            [['out_trade_no' => ' ']], [['trade_no' => false]], [['trade_no' => []]],
            [['total_amount' => true]], [['total_amount' => '12.34']], [['total_amount' => '-1']],
            [['total_amount' => '1234junk']], [['total_amount' => []]], [['extra' => []]],
            [['status' => 'pending']],
        ];
    }

    public function test_valid_receipts_keep_their_amount_units(): void
    {
        $epay = new Epay('epay');
        $epay->setConfig(['key' => 'secret', 'pid' => 'merchant-1']);
        $mgate = new Mgate('mgate');
        $mgate->setConfig(['mgate_app_secret' => 'secret', 'mgate_app_id' => 'app-1']);
        $this->assertSame(1234, $epay->notify($this->epayPayload())['paid_amount']);
        $this->assertSame(1234, $mgate->notify($this->mgatePayload())['paid_amount']);

        $legacy = $this->mgatePayload();
        unset($legacy['status'], $legacy['app_id'], $legacy['sign']);
        ksort($legacy);
        $legacy['sign'] = md5(http_build_query($legacy) . 'secret');
        $this->assertSame(1234, $mgate->notify($legacy)['paid_amount']);
    }

    #[DataProvider('emptySecrets')]
    public function test_coinpayments_rejects_empty_secrets(mixed $secret): void
    {
        $plugin = new \Plugin\CoinPayments\Plugin('coin_payments');
        $plugin->setConfig(['coinpayments_ipn_secret' => $secret, 'coinpayments_merchant_id' => 'merchant-1']);
        $this->expectException(\App\Exceptions\ApiException::class);
        $plugin->notify(['merchant' => 'merchant-1']);
    }

    public function test_coinbase_verifies_framework_headers_and_rejects_a_different_secret(): void
    {
        $plugin = new \Plugin\Coinbase\Plugin('coinbase');
        $plugin->setConfig(['coinbase_webhook_key' => 'secret']);
        $body = json_encode(['event' => ['id' => 'receipt-test', 'type' => 'charge:confirmed',
            'data' => ['metadata' => ['outTradeNo' => 'order-test'], 'pricing' => ['local' => ['amount' => '12.34']]]]]);
        $request = Request::create('/notify', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_CC_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'secret')], $body);
        app()->instance('request', $request);
        $this->assertSame(1234, $plugin->notify([])['paid_amount']);
        $request->headers->set('x-cc-webhook-signature', hash_hmac('sha256', $body, 'other-secret'));
        $this->expectException(\App\Exceptions\ApiException::class);
        $plugin->notify([]);
    }

    public function test_coinpayments_keeps_signed_merchant_and_amount_verification(): void
    {
        $plugin = new \Plugin\CoinPayments\Plugin('coin_payments');
        $plugin->setConfig(['coinpayments_ipn_secret' => 'secret', 'coinpayments_merchant_id' => 'merchant-1']);
        $params = ['merchant' => 'merchant-1', 'status' => '100', 'item_number' => 'order-test',
            'txn_id' => 'receipt-test', 'amount1' => '12.34'];
        ksort($params);
        $request = Request::create('/notify', 'POST');
        $request->headers->set('hmac', hash_hmac('sha512', stripslashes(http_build_query($params)), 'secret'));
        app()->instance('request', $request);
        $this->assertSame(1234, $plugin->notify($params)['paid_amount']);
        $params['merchant'] = 'other-merchant';
        $this->expectException(\App\Exceptions\ApiException::class);
        $plugin->notify($params);
    }

    public function test_btcpay_rejects_an_invalid_signature_before_fetching_an_invoice(): void
    {
        $plugin = new \Plugin\Btcpay\Plugin('btcpay');
        $plugin->setConfig(['btcpay_webhook_key' => 'secret']);
        app()->instance('request', Request::create('/notify', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_BTCPAY_SIG' => 'sha256=wrong'], '{"invoiceId":"test-invoice"}'));
        $this->expectException(\App\Exceptions\ApiException::class);
        $plugin->notify([]);
    }

    public function test_alipay_checks_the_application_even_for_a_valid_signature(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $plugin = new \Plugin\AlipayF2f\Plugin('alipay_f2f');
        $plugin->setConfig(['app_id' => 'app-1', 'public_key' => openssl_pkey_get_details($key)['key']]);
        $params = ['app_id' => 'app-1', 'trade_status' => 'TRADE_SUCCESS', 'out_trade_no' => 'order-test',
            'trade_no' => 'receipt-test', 'total_amount' => '12.34'];
        ksort($params);
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $this->assertTrue(openssl_sign(implode('&', $pairs), $signature, $key, OPENSSL_ALGO_SHA256));
        $params['sign'] = base64_encode($signature);
        $params['sign_type'] = 'RSA2';
        $this->assertSame(1234, $plugin->notify($params)['paid_amount']);
        $plugin->setConfig(['app_id' => 'other-app', 'public_key' => openssl_pkey_get_details($key)['key']]);
        $this->assertFalse($plugin->notify($params));
    }

    private function epayPayload(array $changes = [], string $secret = 'secret'): array
    {
        $params = array_replace(['pid' => 'merchant-1', 'out_trade_no' => 'order-test',
            'trade_no' => 'receipt-test', 'trade_status' => 'TRADE_SUCCESS', 'money' => '12.34'], $changes);
        $signed = $params;
        unset($signed['sign'], $signed['sign_type']);
        ksort($signed);
        $params['sign'] ??= md5(stripslashes(urldecode(http_build_query($signed))) . $secret);
        $params['sign_type'] ??= 'MD5';
        return $params;
    }

    private function mgatePayload(array $changes = [], string $secret = 'secret'): array
    {
        $params = array_replace(['app_id' => 'app-1', 'out_trade_no' => 'order-test',
            'trade_no' => 'receipt-test', 'status' => 'paid', 'total_amount' => '1234'], $changes);
        $signed = $params;
        unset($signed['sign']);
        ksort($signed);
        $params['sign'] ??= md5(http_build_query($signed) . $secret);
        return $params;
    }
}

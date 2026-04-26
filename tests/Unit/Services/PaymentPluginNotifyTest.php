<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Plugin\Bepusdt\Plugin as BepusdtPlugin;
use Plugin\Epay\Plugin as EpayPlugin;
use Tests\TestCase;

final class PaymentPluginNotifyTest extends TestCase
{
    public function test_epay_notify_returns_paid_amount_in_cents_only_for_success_status(): void
    {
        $plugin = new EpayPlugin('Epay');
        $plugin->setConfig(['key' => 'secret']);

        $params = [
            'out_trade_no' => 'trade-1',
            'trade_no' => 'gateway-1',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '12.34',
        ];
        $params['sign'] = $this->epaySign($params, 'secret');
        $params['sign_type'] = 'MD5';

        $result = $plugin->notify($params);

        $this->assertIsArray($result);
        $this->assertSame('trade-1', $result['trade_no']);
        $this->assertSame('gateway-1', $result['callback_no']);
        $this->assertSame(1234, $result['paid_amount']);

        $params['trade_status'] = 'WAIT_BUYER_PAY';
        $params['sign'] = $this->epaySign($params, 'secret');

        $this->assertFalse($plugin->notify($params));
    }

    public function test_bepusdt_notify_requires_success_status_and_returns_paid_amount(): void
    {
        $plugin = new BepusdtPlugin('Bepusdt');
        $plugin->setConfig(['bepusdt_apitoken' => 'secret']);

        $params = [
            'order_id' => 'trade-1',
            'trade_id' => 'gateway-1',
            'status' => 2,
            'amount' => '88.88',
        ];
        $params['signature'] = $this->bepusdtSign($params, 'secret');

        $result = $plugin->notify($params);

        $this->assertIsArray($result);
        $this->assertSame('trade-1', $result['trade_no']);
        $this->assertSame('gateway-1', $result['callback_no']);
        $this->assertSame(8888, $result['paid_amount']);

        $params['status'] = 1;
        $params['signature'] = $this->bepusdtSign($params, 'secret');

        $this->assertFalse($plugin->notify($params));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function epaySign(array $params, string $key): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);

        return md5(stripslashes(urldecode(http_build_query($params))) . $key);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bepusdtSign(array $params, string $apiToken): string
    {
        unset($params['signature']);
        ksort($params);

        return md5(stripslashes(urldecode(http_build_query($params))) . $apiToken);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use Illuminate\Support\Facades\Http;
use Plugin\AlipayF2f\library\AlipayF2F;
use Tests\TestCase;

final class AlipayF2FTest extends TestCase
{
    public function test_send_posts_precreate_request_and_stores_qr_code(): void
    {
        $method = null;
        $payload = [];

        Http::fake(function ($request) use (&$method, &$payload) {
            $method = $request->method();
            $payload = $request->data();

            return Http::response([
                'alipay_trade_precreate_response' => [
                    'code' => '10000',
                    'msg' => 'Success',
                    'qr_code' => 'https://qr.alipay.test/pay',
                ],
            ]);
        });

        $gateway = $this->gateway();
        $gateway->send();

        $this->assertSame('POST', $method);
        $this->assertSame('alipay.trade.precreate', $payload['method'] ?? null);
        $this->assertSame('https://panel.example.test/notify', $payload['notify_url'] ?? null);
        $this->assertSame('https://qr.alipay.test/pay', $gateway->getQrCodeUrl());
    }

    public function test_send_throws_detailed_gateway_error(): void
    {
        Http::fake([
            'https://openapi.alipay.com/gateway.do' => Http::response([
                'alipay_trade_precreate_response' => [
                    'code' => '40004',
                    'msg' => 'Business Failed',
                    'sub_code' => 'ACQ.ACCESS_FORBIDDEN',
                    'sub_msg' => '访问被禁止',
                ],
            ]),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('支付宝当面付请求失败：访问被禁止（ACQ.ACCESS_FORBIDDEN）');

        $this->gateway()->send();
    }

    public function test_send_throws_http_status_error_when_gateway_rejects_request(): void
    {
        Http::fake([
            'https://openapi.alipay.com/gateway.do' => Http::response('<html>访问被禁止</html>', 403),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('支付宝当面付请求失败：网关 HTTP 403 访问被禁止');

        $this->gateway()->send();
    }

    private function gateway(): AlipayF2F
    {
        $gateway = new AlipayF2F();
        $gateway->setMethod('alipay.trade.precreate');
        $gateway->setAppId('app-1');
        $gateway->setPrivateKey($this->privateKey());
        $gateway->setAlipayPublicKey('public-key');
        $gateway->setNotifyUrl('https://panel.example.test/notify');
        $gateway->setBizContent([
            'subject' => 'test product',
            'out_trade_no' => 'trade-1',
            'total_amount' => 1.23,
        ]);

        return $gateway;
    }

    private function privateKey(): string
    {
        return 'MIICXgIBAAKBgQCuUpkBHbZiJt5PHwY+c1VaMaFE8qNJL2LJOfAYwuxUrkc6QPKezWts0hx4luXuRdSSmzKNNMogvf8+dFq++6GuM2uLjnJBrU9iuGi2mqvWjUkCOfYqRiKrf8lq2wqWp0t/7hmbtWtxJfVgvDldJ3H78804gb9QUO5jAb7VUfd3tQIDAQABAoGBAKFhJ/JHjnOZJg87Wm1wGiEQdwq8UXvMGXjOYT6bHWxblucP/0wSQZQRg3gDwkLudJdwg8EDkOf03Jn135iUnRx/p6AA0KdOGfhnMSeNNRBdY6vm24VMJ6Z/CxwhR5t8aW58pxZArty7xzP6ij16Aw7hnt/3oB7xdMCxGSRBw+XVAkEA1XHxo4Nw9+DjEIwb0+CNb+5sJY41lxAHCrod/EOKxqwCYi0WE9zJwjinNw9RewlfWqTaKSYV2dEc+pYm/rY/WwJBANET4JvnSps013D32uX/rkDvwq58rDuKHCgfmoYcSg9P2A3VxEl/CStKDqHio6h0+r2MKTQwNAHOQTbTuouTYi8CQF5wdO7ZKHG0oiLfKyzbDRl6T4VqX5HAOK1pXf0Q0WVIFCHmOv980BRMRsgY0f9zTSppCFHulPp0CLNjHkvSzUMCQQCmnAF0G3c/gXdhZZIBkKNKygVIyL7zX1aavryDvI1j8EuKktuteddTsNtCM/oY5sddPxEirnrzKWqch1LzoQovAkEApl2N2ajd0gF0+I4ZsvLbq5QML3bCblyKyU/k3+rddPDJpEZOSVPtcJxEDRcLf0q+QlX2Q/gBsfeAzq0/ItEfBA==';
    }
}

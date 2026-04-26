<?php

namespace Plugin\Bepusdt;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\OrderService;
use App\Services\Plugin\AbstractPlugin;
use Curl\Curl;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const NETWORK_LABELS = [
        'trc20' => 'Tron',
        'erc20' => 'Ethereum',
        'polygon' => 'Polygon',
        'bep20' => 'BSC',
        'aptos' => 'Aptos',
        'solana' => 'Solana',
        'xlayer' => 'X-Layer',
        'arbitrum' => 'Arbitrum-One',
        'base' => 'Base',
        'plasma' => 'Plasma',
        'tron' => 'Tron',
        'ethereum' => 'Ethereum',
        'bsc' => 'BSC',
    ];

    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['BEPUSDT'] = [
                    'name' => $this->getConfig('display_name', 'BEP USDT'),
                    'icon' => $this->getConfig('icon', '🪙'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'bepusdt_url' => [
                'label' => 'API 地址',
                'description' => '您的 BEPUSDT API 接口地址 (例如: https://xxx.com)',
                'type' => 'string',
                'required' => true,
            ],
            'bepusdt_apitoken' => [
                'label' => 'API Token',
                'description' => '您的 BEPUSDT API Token',
                'type' => 'string',
                'required' => true,
            ],
            'bepusdt_trade_type' => [
                'label' => '交易类型',
                'description' => '您的 BEPUSDT 交易类型，可在 https://github.com/v03413/BEpusdt/blob/main/docs/trade-type.md 查看列表',
                'type' => 'string',
                'required' => true,
            ],
        ];
    }

    public function pay($order): array
    {
        $apiUrl = rtrim($this->getConfig('bepusdt_url'), '/');
        $apiToken = $this->getConfig('bepusdt_apitoken');
        $tradeType = $this->getConfig('bepusdt_trade_type');

        if (!$apiUrl || !$apiToken || !$tradeType) {
            throw new ApiException('缺少支付网关配置');
        }

        $params = [
            'amount' => $order['total_amount'] / 100,
            'trade_type' => $tradeType,
            'notify_url' => $order['notify_url'],
            'order_id' => $order['trade_no'],
            'redirect_url' => $order['return_url'],
        ];

        ksort($params);
        $signString = stripslashes(urldecode(http_build_query($params))) . $apiToken;
        $params['signature'] = md5($signString);

        $curl = new Curl();
        $curl->setUserAgent('BEPUSDT');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->setOpt(CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
        $curl->post($apiUrl . '/api/v1/order/create-transaction', json_encode($params));
        $result = $curl->response;
        $curl->close();

        if (!is_object($result) || !isset($result->status_code)) {
            throw new ApiException('支付网关返回异常');
        }

        if ((int) $result->status_code !== 200) {
            $message = $result->message ?? 'Failed to create order';
            throw new ApiException("创建订单失败：{$message}");
        }

        if (!isset($result->data->payment_url)) {
            throw new ApiException('支付网关未返回支付地址');
        }

        $paymentData = (array) $result->data;
        $tradeTypeMeta = $this->parseTradeType($tradeType);
        $token = isset($paymentData['token']) ? (string) $paymentData['token'] : '';

        return [
            'type' => 0,
            'data' => [
                'qr_data' => $token ?: (string) $paymentData['payment_url'],
                'address' => $token,
                'amount' => isset($paymentData['actual_amount']) ? (string) $paymentData['actual_amount'] : '',
                'fiat_amount' => isset($paymentData['amount']) ? (string) $paymentData['amount'] : '',
                'fiat' => isset($paymentData['fiat']) ? (string) $paymentData['fiat'] : 'CNY',
                'currency' => $tradeTypeMeta['currency'],
                'network' => $tradeTypeMeta['network'],
                'trade_type' => $tradeType,
                'trade_id' => isset($paymentData['trade_id']) ? (string) $paymentData['trade_id'] : '',
                'payment_url' => (string) $paymentData['payment_url'],
                'expiration_time' => isset($paymentData['expiration_time']) ? (int) $paymentData['expiration_time'] : null,
            ],
        ];
    }

    public function notify($params): array|bool
    {
        $signature = $params['signature'] ?? null;
        if (!$signature) {
            return false;
        }

        $paramsForSign = $params;
        unset($paramsForSign['signature']);
        ksort($paramsForSign);
        $signString = stripslashes(urldecode(http_build_query($paramsForSign))) . $this->getConfig('bepusdt_apitoken');

        $generatedSignature = md5($signString);
        if (!hash_equals($generatedSignature, (string) $signature)) {
            return false;
        }

        if (($params['status'] ?? null) != 2) {
            return false;
        }

        if (empty($params['order_id']) || empty($params['trade_id']) || !isset($params['amount'])) {
            return false;
        }

        return [
            'trade_no' => $params['order_id'],
            'callback_no' => $params['trade_id'],
            'paid_amount' => OrderService::amountToCents($params['amount']),
            'custom_result' => 'ok',
        ];
    }

    private function parseTradeType(string $tradeType): array
    {
        $parts = array_values(array_filter(explode('.', strtolower($tradeType))));
        if (count($parts) !== 2) {
            return [
                'currency' => strtoupper($tradeType),
                'network' => '',
            ];
        }

        [$first, $second] = $parts;
        if (in_array($first, ['usdt', 'usdc'], true)) {
            return [
                'currency' => strtoupper($first),
                'network' => self::NETWORK_LABELS[$second] ?? strtoupper($second),
            ];
        }

        return [
            'currency' => strtoupper($second),
            'network' => self::NETWORK_LABELS[$first] ?? strtoupper($first),
        ];
    }
}

<?php

namespace Plugin\AlipayF2f;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\OrderService;
use Illuminate\Support\Facades\Log;
use Plugin\AlipayF2f\library\AlipayF2F;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['AlipayF2F'] = [
                    'name' => $this->getConfig('display_name', '支付宝当面付'),
                    'icon' => $this->getConfig('icon', '💙'),
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
            'app_id' => [
                'label' => '支付宝APPID',
                'type' => 'string',
                'required' => true,
                'description' => '支付宝开放平台应用的APPID'
            ],
            'private_key' => [
                'label' => '支付宝私钥',
                'type' => 'text',
                'required' => true,
                'description' => '应用私钥，用于签名'
            ],
            'public_key' => [
                'label' => '支付宝公钥',
                'type' => 'text',
                'required' => true,
                'description' => '支付宝公钥，用于验签'
            ],
            'product_name' => [
                'label' => '自定义商品名称',
                'type' => 'string',
                'description' => '将会体现在支付宝账单中'
            ],
            'product_code' => [
                'label' => '销售产品码',
                'type' => 'select',
                'default' => 'FACE_TO_FACE_PAYMENT',
                'description' => '普通当面付选择 FACE_TO_FACE_PAYMENT；当面付快捷版选择 OFFLINE_PAYMENT。若支付宝提示 ACQ.ACCESS_FORBIDDEN，请优先确认这里是否与签约产品一致。',
                'select_options' => [
                    'FACE_TO_FACE_PAYMENT' => 'FACE_TO_FACE_PAYMENT - 当面付产品',
                    'OFFLINE_PAYMENT' => 'OFFLINE_PAYMENT - 当面付快捷版',
                ],
            ],
            'seller_id' => [
                'label' => '收款支付宝用户ID',
                'type' => 'string',
                'description' => '可选。需要指定收款账号时填写，不填则默认使用签约商户账号。'
            ]
        ];
    }

    public function pay($order): array
    {
        try {
            $gateway = new AlipayF2F();
            $gateway->setMethod('alipay.trade.precreate');
            $gateway->setAppId($this->getConfig('app_id'));
            $gateway->setPrivateKey($this->getConfig('private_key'));
            $gateway->setAlipayPublicKey($this->getConfig('public_key'));
            $gateway->setNotifyUrl($order['notify_url']);
            $bizContent = [
                'subject' => $this->getConfig('product_name') ?? (admin_setting('app_name', 'XBoard') . ' - 订阅'),
                'out_trade_no' => $order['trade_no'],
                'total_amount' => number_format($order['total_amount'] / 100, 2, '.', ''),
                'product_code' => $this->normalizeProductCode($this->getConfig('product_code')),
            ];
            $sellerId = trim((string) $this->getConfig('seller_id', ''));
            if ($sellerId !== '') {
                $bizContent['seller_id'] = $sellerId;
            }
            $gateway->setBizContent($bizContent);
            $gateway->send();
            return [
                'type' => 0,
                'data' => $gateway->getQrCodeUrl()
            ];
        } catch (\Exception $e) {
            Log::error($e);
            throw new ApiException($e->getMessage());
        }
    }

    public function notify($params): array|bool
    {
        if ($params['trade_status'] !== 'TRADE_SUCCESS')
            return false;

        $gateway = new AlipayF2F();
        $gateway->setAppId($this->getConfig('app_id'));
        $gateway->setPrivateKey($this->getConfig('private_key'));
        $gateway->setAlipayPublicKey($this->getConfig('public_key'));

        try {
            if ($gateway->verify($params)) {
                if (empty($params['out_trade_no']) || empty($params['trade_no']) || !isset($params['total_amount'])) {
                    return false;
                }

                return [
                    'trade_no' => $params['out_trade_no'],
                    'callback_no' => $params['trade_no'],
                    'paid_amount' => OrderService::amountToCents($params['total_amount']),
                ];
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    private function normalizeProductCode($value): string
    {
        $value = strtoupper(trim((string) $value));

        return in_array($value, ['FACE_TO_FACE_PAYMENT', 'OFFLINE_PAYMENT'], true)
            ? $value
            : 'FACE_TO_FACE_PAYMENT';
    }
}

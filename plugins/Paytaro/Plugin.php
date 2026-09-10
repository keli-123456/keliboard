<?php

declare(strict_types=1);

namespace Plugin\Paytaro;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const API_BASE = 'https://v3.paytaro.com/v1/invoice/';

    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['PayTaro'] = [
                    'name' => $this->getConfig('display_name', 'PayTaro'),
                    'icon' => $this->getConfig('icon', ''),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin',
                ];
            }

            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'app_id' => [
                'label' => 'App ID',
                'type' => 'string',
                'required' => true,
                'description' => 'PayTaro 商户后台的应用 ID；应用订单币种须为 CNY。',
            ],
            'app_secret' => [
                'label' => 'App Secret',
                'type' => 'string',
                'required' => true,
                'description' => '同一应用的 App Secret，仅填写到支付配置中。回调域名必须使用 HTTPS。',
            ],
            'checkout_mode' => [
                'label' => '支付展示方式',
                'type' => 'select',
                'default' => 'inline',
                'select_options' => ['inline' => '站内支付弹窗', 'cashier' => '跳转 PayTaro 收银台（兼容模式）'],
                'description' => '站内模式需要支持 PayTaro 显码的新版 keli-user 主题。',
            ],
            'method_uuid' => [
                'label' => '支付渠道 UUID',
                'type' => 'string',
                'description' => '站内模式必填：PayTaro 应用管理 → 付款方式 → 复制已开通且展示中的渠道 UUID。每个渠道单独添加一条支付方式。',
            ],
        ];
    }

    public function pay($order): array
    {
        $secret = $this->configuredString('app_secret');
        if ($secret === '' || $this->configuredString('app_id') === '') {
            throw new ApiException('请先配置 PayTaro App ID 和 App Secret。');
        }

        $mode = $this->getConfig('checkout_mode', 'inline');
        if (!in_array($mode, ['inline', 'cashier'], true)) {
            throw new ApiException('PayTaro 支付展示方式无效。');
        }
        $methodUuid = $this->configuredString('method_uuid');
        if ($mode === 'inline' && !$this->validUuid($methodUuid)) {
            throw new ApiException('PayTaro 站内支付需要填写有效的支付渠道 UUID。');
        }

        $cents = $order['total_amount'] ?? null;
        if ((!is_int($cents) && !is_string($cents))
            || !preg_match('/\A[1-9][0-9]*\z/', (string) $cents)
            || filter_var($cents, FILTER_VALIDATE_INT) === false) {
            throw new ApiException('PayTaro 订单金额无效。');
        }
        $amount = (int) $cents / 100;
        if ($this->amountToCents($amount) !== (int) $cents) {
            throw new ApiException('PayTaro 订单金额超出可精确处理范围。');
        }

        $notifyUrl = $order['notify_url'] ?? '';
        if (!is_string($notifyUrl) || !filter_var($notifyUrl, FILTER_VALIDATE_URL)
            || parse_url($notifyUrl, PHP_URL_SCHEME) !== 'https'
            || parse_url($notifyUrl, PHP_URL_USER) !== null
            || parse_url($notifyUrl, PHP_URL_PASS) !== null) {
            throw new ApiException('PayTaro 回调地址必须是有效的 HTTPS 地址，请检查回调域名配置。');
        }

        $payload = [
            'merchant_no' => $order['trade_no'],
            'order_amount' => $amount,
            'notify_url' => $notifyUrl,
            'return_url' => $order['return_url'] ?? '',
        ];
        if ($mode === 'inline') {
            $payload['method_uuid'] = $methodUuid;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['X-App-Secret' => $secret])
                ->withOptions(['verify' => true])
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(20)
                ->post(self::API_BASE . ($mode === 'inline' ? 'pay' : 'order'), $payload);
        } catch (ConnectionException) {
            // Do not expose transport diagnostics or automatically retry a payment POST.
            throw new ApiException('PayTaro 连接失败或超时，请稍后重试。');
        }

        if (!$response->successful()) {
            $message = match ($response->status()) {
                401, 403 => 'PayTaro 应用认证失败，请管理员检查 App Secret 和应用状态。',
                400, 422 => 'PayTaro 拒绝创建订单，请管理员检查应用及回调地址配置。',
                429 => 'PayTaro 请求过于频繁，请稍后重试。',
                default => 'PayTaro 下单失败，请稍后重试（HTTP ' . $response->status() . '）。',
            };
            throw new ApiException($message);
        }

        $result = $response->json();
        if ($mode === 'inline') {
            return ['type' => 0, 'data' => $this->inlinePayment($result, $order['trade_no'], (int) $cents)];
        }
        if (!is_array($result) || !$this->validCheckoutUrl($result)) {
            throw new ApiException('PayTaro 返回的收银台地址无效，请联系管理员检查。');
        }

        return ['type' => 1, 'data' => $result['checkout_url']];
    }

    public function notify($params): array|bool
    {
        $request = request();
        $secret = $this->configuredString('app_secret');
        $appId = $this->configuredString('app_id');
        $headerSecret = $request->header('X-App-Secret');
        if (!$request->isMethod('POST') || !$request->isJson()
            || $secret === '' || $appId === '' || !is_string($headerSecret)
            || !hash_equals($secret, $headerSecret)) {
            return false;
        }

        // Only the authenticated JSON body is authoritative, never merged query parameters.
        try {
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($body) || ($body['app_id'] ?? null) !== $appId
            || !in_array($body['status'] ?? null, ['PAID', 'SUCCESS'], true)
            || ($body['order_currency'] ?? null) !== 'CNY') {
            return false;
        }
        foreach (['merchant_no', 'transaction_no'] as $field) {
            if (!is_string($body[$field] ?? null) || trim($body[$field]) === ''
                || strlen($body[$field]) > 255) {
                return false;
            }
        }

        // Crypto pay_amount is not the merchant's CNY order_amount.
        $cents = $this->amountToCents($body['order_amount'] ?? null);
        if ($cents === null) {
            return false;
        }

        return [
            'trade_no' => $body['merchant_no'],
            'callback_no' => $body['transaction_no'],
            'paid_amount' => $cents,
        ];
    }

    private function configuredString(string $key): string
    {
        $value = $this->getConfig($key);
        if (!is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return '';
        }

        return trim($value);
    }

    private function amountToCents(mixed $amount): ?int
    {
        if ((!is_int($amount) && !is_float($amount) && !is_string($amount))
            || !preg_match('/\A([0-9]+)(?:\.([0-9]{1,2}))?\z/', (string) $amount, $parts)) {
            return null;
        }

        $cents = ltrim($parts[1] . str_pad($parts[2] ?? '', 2, '0'), '0');
        $limit = (string) PHP_INT_MAX;
        if ($cents === '' || strlen($cents) > strlen($limit)
            || (strlen($cents) === strlen($limit) && strcmp($cents, $limit) > 0)) {
            return null;
        }

        return (int) $cents;
    }

    private function validCheckoutUrl(array $result): bool
    {
        $url = $result['checkout_url'] ?? null;
        $uuid = $result['uuid'] ?? null;
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)
            || !$this->validUuid($uuid)) {
            return false;
        }

        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'v3.paytaro.com'
            || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['/checkout', '/checkout/'], true)) {
            return false;
        }
        parse_str($parts['query'] ?? '', $query);

        return ($query['uuid'] ?? null) === $uuid;
    }

    private function validUuid(mixed $uuid): bool
    {
        return is_string($uuid) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $uuid) === 1;
    }

    private function inlinePayment(mixed $result, string $tradeNo, int $cents): array
    {
        $invalid = fn () => new ApiException('PayTaro 返回的支付数据无效，请检查支付渠道配置或稍后重试。');
        if (!is_array($result) || ($result['merchant_no'] ?? null) !== $tradeNo
            || ($result['status'] ?? null) !== 'UNPAID' || !$this->validUuid($result['uuid'] ?? null)
            || ($result['order_currency'] ?? null) !== 'CNY'
            || $this->amountToCents($result['order_amount'] ?? null) !== $cents) {
            throw $invalid();
        }

        $payment = $result['payment'] ?? null;
        $expiresAt = $result['expired_at'] ?? null;
        $serverTime = $result['server_time'] ?? null;
        if (!is_array($payment) || !is_int($expiresAt) || !is_int($serverTime)
            || $serverTime <= 0 || $expiresAt <= $serverTime) {
            throw $invalid();
        }
        $amount = $this->decimalAmount($payment['pay_amount'] ?? null);
        $currency = $payment['pay_currency'] ?? null;
        $data = $payment['data'] ?? null;
        $crypto = ($payment['currency_type'] ?? null) === 'crypto';
        if ($amount === null || !is_string($currency) || !preg_match('/\A[A-Z0-9]{2,16}\z/', $currency)
            || !is_string($data) || $data === '' || preg_match('/[\x00-\x20\x7f]/', $data)) {
            throw $invalid();
        }

        $mobileUrl = '';
        if ($crypto) {
            if (($payment['link_type'] ?? null) !== 'address' || strlen($data) > 256
                || !preg_match('/\A[A-Za-z0-9:_-]+\z/', $data)
                || !is_string($payment['type'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]{1,40}\z/', $payment['type'])) {
                throw $invalid();
            }
        } else {
            $mobileUrl = $payment['mobile_url'] ?? '';
            if (($payment['currency_type'] ?? null) !== 'fiat' || ($payment['type'] ?? null) !== 'alipay'
                || !in_array($payment['link_type'] ?? null, ['h5', 'pc'], true) || $currency !== 'CNY'
                || strlen($data) > 2800 || !$this->validAlipayUrl($data)
                || !is_string($mobileUrl) || !$this->validAlipayMobileUrl($mobileUrl)) {
                throw $invalid();
            }
        }

        return [
            'provider' => 'paytaro',
            'qr_data' => $data,
            'address' => $crypto ? $data : '',
            'amount' => $amount,
            'fiat_amount' => number_format($cents / 100, 2, '.', ''),
            'fiat' => 'CNY',
            'currency' => $currency,
            'currency_type' => $payment['currency_type'],
            'network' => $crypto ? strtoupper($payment['type']) : '',
            'payment_name' => is_string($payment['name'] ?? null) ? mb_substr($payment['name'], 0, 80) : '',
            'payment_url' => $crypto ? '' : $data,
            'mobile_url' => $mobileUrl,
            'link_type' => $payment['link_type'],
            'expiration_time' => $expiresAt,
            'server_time' => $serverTime,
            'expires_in' => $expiresAt - $serverTime,
        ];
    }

    private function decimalAmount(mixed $amount): ?string
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
            return null;
        }
        $value = is_string($amount) ? $amount : json_encode($amount, JSON_PRESERVE_ZERO_FRACTION);
        // Expand numeric JSON exponent notation without rounding a crypto amount.
        if (is_string($value) && preg_match('/\A([0-9]+)(?:\.([0-9]+))?[eE]([+-]?[0-9]+)\z/', $value, $parts)) {
            $digits = $parts[1] . ($parts[2] ?? '');
            $position = strlen($parts[1]) + (int) $parts[3];
            if (abs((int) $parts[3]) > 30) {
                return null;
            }
            $value = $position <= 0 ? '0.' . str_repeat('0', -$position) . $digits
                : ($position >= strlen($digits) ? str_pad($digits, $position, '0') : substr($digits, 0, $position) . '.' . substr($digits, $position));
        }

        return is_string($value) && strlen($value) <= 64 && preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $value)
            && preg_match('/[1-9]/', $value) ? $value : null;
    }

    private function validAlipayUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? '') === 'https' && ($host === 'alipay.com' || str_ends_with($host, '.alipay.com'))
            && ($parts['port'] ?? 443) === 443 && !isset($parts['user']) && !isset($parts['pass']);
    }

    private function validAlipayMobileUrl(string $url): bool
    {
        if ($this->validAlipayUrl($url)) {
            return true;
        }
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'alipays' || ($parts['host'] ?? '') !== 'platformapi'
            || ($parts['path'] ?? '') !== '/startapp' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        parse_str($parts['query'] ?? '', $query);

        return ($query['appId'] ?? null) === '20000067' && is_string($query['url'] ?? null) && $this->validAlipayUrl($query['url']);
    }
}

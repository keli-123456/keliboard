<?php

declare(strict_types=1);

namespace Plugin\Paytaro;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            || strlen((string) $amount) > 64
            || !preg_match('/\A([0-9]+)(?:\.([0-9]{1,2})0*)?\z/', (string) $amount, $parts)) {
            return null;
        }

        // Extra decimal places are acceptable only when they are zero; never round money.
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
        if (!is_array($result)) {
            throw $this->invalidPayment('PT_RESPONSE', '网关未返回有效的 JSON 支付数据', $result);
        }
        if (($result['merchant_no'] ?? null) !== $tradeNo || !$this->validUuid($result['uuid'] ?? null)) {
            throw $this->invalidPayment('PT_ORDER', '网关返回的订单标识缺失或不匹配', $result);
        }
        if (($result['status'] ?? null) !== 'UNPAID') {
            throw $this->invalidPayment('PT_STATUS', '网关订单不是待支付状态，请先检查订单状态', $result);
        }
        if (($result['order_currency'] ?? null) !== 'CNY') {
            throw $this->invalidPayment('PT_CURRENCY', '应用订单币种须为 CNY；USDT 收款渠道可以继续使用，请检查 PayTaro 应用设置', $result);
        }
        if ($this->amountToCents($result['order_amount'] ?? null) !== $cents) {
            throw $this->invalidPayment('PT_AMOUNT', '网关返回的原始订单金额无效或与面板不一致', $result);
        }

        $payment = $result['payment'] ?? null;
        if (!is_array($payment)) {
            throw $this->invalidPayment('PT_PAYMENT', '网关未返回收款信息，请检查所选渠道是否已开通并展示', $result);
        }
        $expiresAt = $this->unixSeconds($result['expired_at'] ?? null);
        $serverTime = $this->unixSeconds($result['server_time'] ?? null);
        if ($expiresAt === null || $serverTime === null) {
            throw $this->invalidPayment('PT_TIME', '网关返回的过期时间或服务器时间无效', $result);
        }
        if ($expiresAt <= $serverTime) {
            throw $this->invalidPayment('PT_EXPIRED', '网关返回的支付订单已过期，请重新发起支付', $result);
        }
        $amount = $this->decimalAmount($payment['pay_amount'] ?? null);
        $currency = is_string($payment['pay_currency'] ?? null) ? strtoupper(trim($payment['pay_currency'])) : '';
        $currencyType = is_string($payment['currency_type'] ?? null) ? strtolower(trim($payment['currency_type'])) : '';
        $paymentType = is_string($payment['type'] ?? null) ? strtolower(trim($payment['type'])) : '';
        $linkType = is_string($payment['link_type'] ?? null) ? strtolower(trim($payment['link_type'])) : '';
        $data = $payment['data'] ?? null;
        $crypto = $currencyType === 'crypto';
        // The official widget can render crypto addresses without an explicit link_type.
        // Infer only a missing hint; do not reinterpret an explicit URL/Alipay type.
        $rawLinkType = $payment['link_type'] ?? null;
        if ($crypto && ($rawLinkType === null || (is_string($rawLinkType) && trim($rawLinkType) === ''))) {
            $linkType = 'address';
        }
        if ($amount === null || !preg_match('/\A[A-Z0-9]{2,16}\z/', $currency)) {
            throw $this->invalidPayment('PT_PAY_AMOUNT', '网关返回的实际应付数量或收款币种无效', $result);
        }
        if (!is_string($data) || $data === '' || preg_match('/[\x00-\x20\x7f]/', $data)) {
            throw $this->invalidPayment('PT_PAY_DATA', '网关未返回有效的收款地址或支付链接', $result);
        }

        $mobileUrl = '';
        $network = '';
        if ($crypto) {
            if ($linkType !== 'address') {
                throw $this->invalidPayment('PT_CRYPTO_LINK', '网关返回的加密货币显码类型与收款地址不匹配', $result);
            }
            if (strlen($data) > 256 || !preg_match('/\A[A-Za-z0-9:_-]+\z/', $data)) {
                throw $this->invalidPayment('PT_CRYPTO_ADDRESS', '网关返回的加密货币收款地址格式无效', $result);
            }
            $network = $this->cryptoNetwork($payment, $currency, $result);
        } else {
            if ($currencyType !== 'fiat' || $paymentType !== 'alipay'
                || !in_array($linkType, ['h5', 'pc'], true) || $currency !== 'CNY') {
                throw $this->invalidPayment('PT_CHANNEL', '网关返回了不支持的支付渠道类型，请检查渠道 UUID', $result);
            }
            if (strlen($data) > 2800 || !$this->validAlipayUrl($data)) {
                throw $this->invalidPayment('PT_ALIPAY_URL', '网关返回的支付宝支付链接无效', $result);
            }
            $mobileUrl = $payment['mobile_url'] ?? '';
            if ($mobileUrl === '') {
                $mobileUrl = $linkType === 'h5' ? $data
                    : 'alipays://platformapi/startapp?appId=20000067&url=' . rawurlencode($data);
            }
            if (!is_string($mobileUrl) || !$this->validAlipayMobileUrl($mobileUrl)) {
                throw $this->invalidPayment('PT_MOBILE_URL', '网关返回的支付宝手机唤起链接无效', $result);
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
            'currency_type' => $currencyType,
            'network' => $network,
            'payment_name' => is_string($payment['name'] ?? null) ? mb_substr($payment['name'], 0, 80) : '',
            'payment_url' => $crypto ? '' : $data,
            'mobile_url' => $mobileUrl,
            'link_type' => $linkType,
            'expiration_time' => $expiresAt,
            'server_time' => $serverTime,
            'expires_in' => $expiresAt - $serverTime,
        ];
    }

    private function cryptoNetwork(array $payment, string $currency, array $result): string
    {
        $rawType = $payment['type'] ?? null;
        $network = $this->networkLabel($rawType);
        if ($network !== null) {
            return $network;
        }
        if ($rawType !== null && (!is_string($rawType) || trim($rawType) !== '')) {
            throw $this->invalidPayment('PT_CRYPTO_NETWORK', '网关返回的加密货币网络名称格式无效', $result);
        }

        // Resolve only the configured channel within this merchant's authenticated method list.
        // Never retry the order POST or infer a chain from the address or currency alone.
        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-App-Secret' => $this->configuredString('app_secret')])
                ->withOptions(['verify' => true])
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(5)
                ->get('https://v3.paytaro.com/v1/app/methods');
        } catch (ConnectionException) {
            throw $this->invalidPayment('PT_CRYPTO_NETWORK_LOOKUP', '订单未返回网络名称，且渠道信息查询超时或连接失败，请稍后重试', $result);
        }
        $metadata = $response->json();
        if (!$response->successful() || !is_array($metadata)
            || !is_array($metadata['app'] ?? null)
            || ($metadata['app']['app_id'] ?? null) !== $this->configuredString('app_id')
            || !is_array($metadata['methods'] ?? null)) {
            throw $this->invalidPayment('PT_CRYPTO_NETWORK_LOOKUP', '订单未返回网络名称，且无法读取当前应用的渠道信息，请检查 PayTaro 应用配置', $result);
        }
        $methodUuid = strtolower($this->configuredString('method_uuid'));
        $matches = array_values(array_filter($metadata['methods'], static fn ($method) => is_array($method)
            && is_string($method['uuid'] ?? null) && strtolower($method['uuid']) === $methodUuid));
        $method = count($matches) === 1 ? $matches[0] : [];
        if (($method['show'] ?? null) !== true
            || !is_string($method['currency_type'] ?? null) || strtolower(trim($method['currency_type'])) !== 'crypto'
            || !is_string($method['pay_currency'] ?? null) || strtoupper(trim($method['pay_currency'])) !== $currency) {
            throw $this->invalidPayment('PT_CRYPTO_NETWORK_CHANNEL', '未找到与当前订单币种匹配且已展示的加密货币渠道，请检查支付渠道 UUID', $result);
        }
        $network = $this->networkLabel($method['type'] ?? null);
        $missingType = !isset($method['type']) || (is_string($method['type']) && trim($method['type']) === '');
        // The documented USDT-TRC20 method name identifies TRON explicitly, not by address shape.
        if ($network === null && $missingType && $currency === 'USDT'
            && is_string($method['name'] ?? null)
            && preg_match('/\AUSDT[ _-]+TRC[ _-]?20\z/i', trim($method['name'])) === 1) {
            $network = 'TRON';
        }
        if ($network === null) {
            throw $this->invalidPayment('PT_CRYPTO_NETWORK', '订单及对应渠道均未返回可确认的网络名称，请在 PayTaro 核对该渠道的网络信息', $result);
        }
        return $network;
    }

    private function networkLabel(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 240 || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return null;
        }
        $label = trim($value);
        // Network names are display text, not necessarily machine identifiers such as "tron".
        if ($label === '' || mb_strlen($label, 'UTF-8') > 80
            || preg_match('/\A[\p{L}\p{N} _().（）-]+\z/u', $label) !== 1
            || preg_match('/[\p{L}\p{N}]/u', $label) !== 1) {
            return null;
        }
        return strtoupper($label);
    }

    private function unixSeconds(mixed $value): ?int
    {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $value)) {
            return null;
        }
        $seconds = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($seconds) ? $seconds : null;
    }

    private function invalidPayment(string $reason, string $message, mixed $result): ApiException
    {
        // Record field types, never API secrets, customer identifiers, addresses or signed URLs.
        $fields = [];
        foreach (['merchant_no', 'uuid', 'status', 'order_currency', 'order_amount', 'expired_at', 'server_time', 'payment'] as $field) {
            $fields[$field] = get_debug_type(is_array($result) ? ($result[$field] ?? null) : null);
        }
        $payment = is_array($result) && is_array($result['payment'] ?? null) ? $result['payment'] : [];
        foreach (['pay_amount', 'pay_currency', 'currency_type', 'type', 'link_type', 'data', 'mobile_url'] as $field) {
            $fields['payment.' . $field] = get_debug_type($payment[$field] ?? null);
        }
        try {
            Log::warning('PayTaro payment response rejected', [
                'reason' => $reason,
                'response_type' => get_debug_type($result),
                'field_types' => $fields,
                'payment_data_length' => is_string($payment['data'] ?? null) ? strlen($payment['data']) : null,
            ]);
        } catch (\Throwable) {
            // Logging must not hide the original payment failure.
        }
        return new ApiException("PayTaro：{$message}（{$reason}）。");
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

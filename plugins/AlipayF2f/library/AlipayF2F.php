<?php
namespace Plugin\AlipayF2f\library;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AlipayF2F
{
    private const GATEWAY_URL = 'https://openapi.alipay.com/gateway.do';
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const REQUEST_TIMEOUT_SECONDS = 15;

    private $appId;
    private $privateKey;
    private $alipayPublicKey;
    private $signType = 'RSA2';
    public $bizContent;
    public $method;
    public $notifyUrl;
    public $response;

    public function __construct()
    {
    }

    public function verify($data): bool
    {
        if (is_string($data)) {
            parse_str($data, $data);
        }
        if (!is_array($data) || empty($data['sign'])) {
            return false;
        }
        $sign = $data['sign'];
        unset($data['sign']);
        unset($data['sign_type']);
        ksort($data);
        $data = $this->buildQuery($data);
        $res = $this->formatPublicKey($this->alipayPublicKey);
        $publicKey = openssl_pkey_get_public($res);
        if (!$publicKey) {
            return false;
        }
        if ("RSA2" == $this->signType) {
            $result = (openssl_verify($data, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256) === 1);
        } else {
            $result = (openssl_verify($data, base64_decode($sign), $publicKey) === 1);
        }
        openssl_free_key($publicKey);
        return $result;
    }

    public function setBizContent($bizContent = [])
    {
        $this->bizContent = json_encode($bizContent);
    }

    public function setMethod($method)
    {
        $this->method = $method;
    }

    public function setAppId($appId)
    {
        $this->appId = $appId;
    }

    public function setPrivateKey($privateKey)
    {
        $this->privateKey = $privateKey;
    }

    public function setAlipayPublicKey($alipayPublicKey)
    {
        $this->alipayPublicKey = $alipayPublicKey;
    }

    public function setNotifyUrl($url)
    {
        $this->notifyUrl = $url;
    }

    public function send()
    {
        try {
            $httpResponse = Http::asForm()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->post(self::GATEWAY_URL, $this->buildParam());
        } catch (ConnectionException $e) {
            throw new \Exception('支付宝当面付请求超时或网络异常：' . $e->getMessage(), 0, $e);
        }

        if (!$httpResponse->successful()) {
            throw new \Exception(sprintf(
                '支付宝当面付请求失败：网关 HTTP %d %s',
                $httpResponse->status(),
                $this->summarizeBody($httpResponse->body())
            ));
        }

        $response = $httpResponse->json();
        if (!is_array($response)) {
            throw new \Exception('支付宝当面付请求失败：网关返回空响应');
        }

        $resKey = str_replace('.', '_', $this->method) . '_response';
        if (!isset($response[$resKey])) {
            if (isset($response['error_response']) && is_array($response['error_response'])) {
                throw new \Exception($this->formatGatewayError($response['error_response']));
            }
            throw new \Exception('支付宝当面付请求失败：网关响应格式异常');
        }

        $response = $response[$resKey];
        if (($response['code'] ?? null) !== '10000' && ($response['msg'] ?? null) !== 'Success') {
            throw new \Exception($this->formatGatewayError($response));
        }
        $this->response = $response;
    }

    public function getQrCodeUrl()
    {
        $response = $this->response;
        if (!isset($response['qr_code']))
            throw new \Exception('获取付款二维码失败');
        return $response['qr_code'];
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function buildParam(): array
    {
        $params = [
            'app_id' => $this->appId,
            'method' => $this->method,
            'charset' => 'UTF-8',
            'sign_type' => $this->signType,
            'timestamp' => date('Y-m-d H:i:s'),
            'biz_content' => $this->bizContent,
            'version' => '1.0',
            '_input_charset' => 'UTF-8'
        ];
        if ($this->notifyUrl)
            $params['notify_url'] = $this->notifyUrl;
        ksort($params);
        $params['sign'] = $this->buildSign($this->buildQuery($params));
        return $params;
    }

    public function buildQuery($query)
    {
        if (!$query) {
            throw new \Exception('参数构造错误');
        }
        //将要 参数 排序
        ksort($query);

        //重新组装参数
        $params = array();
        foreach ($query as $key => $value) {
            $params[] = $key . '=' . $value;
        }
        $data = implode('&', $params);
        return $data;
    }

    private function buildSign(string $signData): string
    {
        $privateId = openssl_pkey_get_private($this->formatPrivateKey($this->privateKey), '');
        if (!$privateId) {
            throw new \Exception('支付宝应用私钥格式错误');
        }

        // 签名
        $signature = '';

        if ("RSA2" == $this->signType) {

            $signed = openssl_sign($signData, $signature, $privateId, OPENSSL_ALGO_SHA256);
        } else {

            $signed = openssl_sign($signData, $signature, $privateId, OPENSSL_ALGO_SHA1);
        }

        openssl_free_key($privateId);

        if (!$signed) {
            throw new \Exception('支付宝应用私钥签名失败');
        }

        //加密后的内容通常含有特殊字符，需要编码转换下
        $signature = base64_encode($signature);
        return $signature;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function formatGatewayError(array $response): string
    {
        $message = (string) ($response['sub_msg'] ?? $response['msg'] ?? '未知错误');
        $code = (string) ($response['sub_code'] ?? $response['code'] ?? '');

        return $code !== ''
            ? "支付宝当面付请求失败：{$message}（{$code}）"
            : "支付宝当面付请求失败：{$message}";
    }

    private function formatPrivateKey(string $privateKey): string
    {
        $privateKey = trim($privateKey);
        if (str_contains($privateKey, '-----BEGIN')) {
            return $privateKey;
        }

        $body = preg_replace('/\s+/', '', $privateKey) ?: '';
        return "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($body, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
    }

    private function formatPublicKey(string $publicKey): string
    {
        $publicKey = trim($publicKey);
        if (str_contains($publicKey, '-----BEGIN')) {
            return $publicKey;
        }

        $body = preg_replace('/\s+/', '', $publicKey) ?: '';
        return "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap($body, 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
    }

    private function summarizeBody(string $body): string
    {
        $summary = trim(strip_tags($body));
        $summary = preg_replace('/\s+/', ' ', $summary) ?: '';

        return $summary !== '' ? substr($summary, 0, 180) : '无响应内容';
    }
}

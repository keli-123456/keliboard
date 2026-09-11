<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

final class PaytaroChannelService
{
    public function fetch(string $appId, string $secret): array
    {
        $appId = trim($appId);
        $secret = trim($secret);
        if ($appId === '' || $secret === '' || strlen($appId) > 128 || strlen($secret) > 512
            || preg_match('/[\x00-\x20\x7f]/', $appId . $secret)) {
            throw new ApiException('请先填写有效的 PayTaro App ID 和 App Secret。');
        }

        try {
            // Only a fixed, authenticated, read-only endpoint; never create an invoice here.
            $response = Http::acceptJson()
                ->withHeaders(['X-App-Secret' => $secret])
                ->withOptions(['verify' => true])
                ->withoutRedirecting()->connectTimeout(3)->timeout(10)
                ->get('https://v3.paytaro.com/v1/app/methods');
        } catch (\Throwable) {
            // Transport exceptions can contain headers. Do not return or log them.
            throw new ApiException('PayTaro 渠道读取失败或超时，请稍后重试。');
        }
        if (!$response->successful()) {
            throw new ApiException(match ($response->status()) {
                401, 403 => 'PayTaro 应用认证失败，请检查 App Secret 和应用状态。',
                429 => 'PayTaro 渠道查询过于频繁，请稍后重试。',
                default => 'PayTaro 渠道读取失败（HTTP ' . $response->status() . '）。',
            });
        }
        if (strlen($response->body()) > 1048576) {
            throw new ApiException('PayTaro 渠道数据过大，请联系管理员检查。');
        }
        $data = $response->json();
        if (!is_array($data) || !is_array($data['app'] ?? null)
            || ($data['app']['app_id'] ?? null) !== $appId) {
            throw new ApiException('PayTaro 返回的应用与 App ID 不一致，请检查 App ID 和 App Secret 是否属于同一应用。');
        }
        if (isset($data['app']['currency']) && $data['app']['currency'] !== 'CNY') {
            throw new ApiException('PayTaro 应用订单币种须为 CNY；收款渠道可以使用加密货币。');
        }
        $methods = $data['methods'] ?? null;
        if (!is_array($methods) || !array_is_list($methods) || count($methods) > 200) {
            throw new ApiException('PayTaro 渠道列表格式无效，请稍后重试。');
        }

        $channels = [];
        foreach ($methods as $method) {
            $uuid = is_array($method) ? ($method['uuid'] ?? null) : null;
            if (!is_string($uuid) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $uuid) !== 1
                || isset($channels[strtolower($uuid)])) {
                throw new ApiException('PayTaro 渠道 UUID 缺失、重复或格式无效，请联系 PayTaro 核对。');
            }
            $name = $this->label($method['name'] ?? '', 80);
            $type = $this->label($method['type'] ?? '', 240);
            $currencyType = strtolower($this->label($method['currency_type'] ?? '', 16));
            $currency = strtoupper($this->label($method['pay_currency'] ?? '', 16));
            $network = $currencyType === 'crypto' ? PaytaroNetwork::fromMethod($method, $currency) : null;
            $supported = preg_match('/\A[A-Z0-9]{2,16}\z/', $currency) === 1
                && (($currencyType === 'fiat' && strcasecmp($type, 'alipay') === 0 && $currency === 'CNY')
                    || ($currencyType === 'crypto' && $network !== null));
            $channels[strtolower($uuid)] = [
                'uuid' => strtolower($uuid),
                'name' => $name !== '' ? $name : ($type !== '' ? $type : $currency),
                'type' => $type,
                'currency_type' => $currencyType,
                'pay_currency' => $currency,
                'network' => $network,
                'available' => ($method['show'] ?? null) === true && $supported,
                'unavailable_reason' => ($method['show'] ?? null) !== true ? 'hidden' : ($supported ? null : 'unsupported'),
            ];
        }

        // Return an explicit whitelist, never the upstream app object or credentials.
        return array_values($channels);
    }

    private function label(mixed $value, int $limit): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return '';
        }
        return mb_substr(trim($value), 0, $limit);
    }
}

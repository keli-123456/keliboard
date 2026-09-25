<?php

namespace App\Support;

use Illuminate\Validation\Validator;

class Hysteria2Settings
{
    public static function validate(array $settings, Validator $validator): void
    {
        $error = static fn (string $field, string $message) => $validator->errors()->add('protocol_settings.' . $field, $message);
        $ech = $settings['ech'] ?? null;
        if ($ech !== null) {
            if (!is_array($ech) || !isset($ech['enabled']) || !is_bool($ech['enabled'])) {
                $error('ech', 'ECH enabled 必须为布尔值');
            } elseif (!$ech['enabled']) {
                if (array_diff(array_keys($ech), ['enabled'])) $error('ech', '关闭 ECH 时请清除其配置');
            } else {
                if ((int) ($settings['version'] ?? 2) !== 2) $error('version', 'ECH 仅支持 Hysteria2');
                $path = $ech['key_file'] ?? null;
                if (!is_string($path) || strlen($path) > 4096 || !str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f]/', $path) || in_array('..', explode('/', $path), true)) {
                    $error('ech.key_file', '请填写节点上的绝对私钥文件路径');
                }
                if (!Hysteria2Ech::validConfig($ech['config'] ?? null)) $error('ech.config', 'ECH 公共配置无效；只接受 ECH CONFIGS 的单行 Base64，不接受私钥');
                if (!Hysteria2Ech::validName(data_get($settings, 'tls.server_name'))) $error('tls.server_name', 'ECH 需要有效的证书域名 SNI');
                if (data_get($settings, 'tls.allow_insecure')) $error('tls.allow_insecure', 'ECH 必须启用证书验证');
            }
        }
        $network = $settings['network_settings'] ?? [];
        if (!is_array($network)) {
            return; // The request's array rule reports malformed containers.
        }
        $gecko = data_get($settings, 'obfs.open') && data_get($settings, 'obfs.type') === 'gecko';
        if ((int) ($settings['version'] ?? 2) !== 2) {
            if ($gecko || $network !== [] || !empty($settings['congestion_control'])) {
                $error('version', '新增传输设置仅支持 Hysteria2');
            }
            return;
        }
        if (array_key_exists('brutal_disable_loss_compensation', $network)) {
            $disabled = $network['brutal_disable_loss_compensation'];
            if (!is_bool($disabled) || ($disabled && ($settings['congestion_control'] ?? null) !== 'brutal')) {
                $error('network_settings.brutal_disable_loss_compensation', '必须为 JSON 布尔值；禁用丢包补偿仅适用于 Brutal');
            }
        }
        if ($gecko && (!is_string(data_get($settings, 'obfs.password')) || strlen(data_get($settings, 'obfs.password')) < 4)) {
            $error('obfs.password', 'Gecko 密码至少需要 4 字节');
        }
        $min = $network['gecko_min_packet_size'] ?? 0;
        $max = $network['gecko_max_packet_size'] ?? 0;
        foreach (['gecko_min_packet_size', 'gecko_max_packet_size'] as $field) {
            if (array_key_exists($field, $network) && !is_int($network[$field])) {
                $error('network_settings.' . $field, '包长必须为 JSON 整数');
            }
        }
        if (!$gecko && ($min || $max)) {
            $error('network_settings', 'Gecko 包长仅可用于已启用的 Gecko 混淆');
        }
        if (is_numeric($min) && is_numeric($max) && ($min ?: 512) > ($max ?: 1200)) {
            $error('network_settings.gecko_max_packet_size', '最大包长不能小于最小包长');
        }
        $masquerade = $network['masquerade'] ?? null;
        if ($masquerade === null || !is_array($masquerade)) {
            return;
        }
        $mode = $masquerade['type'] ?? null;
        $fields = match ($mode) {
            'not_found' => ['type'],
            'string' => ['type', 'body', 'status', 'content_type'],
            'file' => ['type', 'root'],
            'proxy' => ['type', 'url'],
            default => null,
        };
        if ($fields === null || array_diff(array_keys($masquerade), $fields)) {
            $error('network_settings.masquerade', '伪装模式或字段不支持');
            return;
        }
        if ($mode === 'string') {
            $body = $masquerade['body'] ?? null;
            $status = array_key_exists('status', $masquerade) ? $masquerade['status'] : 200;
            $contentType = array_key_exists('content_type', $masquerade) ? $masquerade['content_type'] : 'text/plain; charset=utf-8';
            if (!is_int($status) || $status < 200 || $status > 599 || $status === 233) {
                $error('network_settings.masquerade.status', '状态码必须为 200–599，且不能为 233');
            }
            if (!is_string($body) || strlen($body) > 1048576 || (in_array($status, [204, 205, 304], true) && $body !== '')) {
                $error('network_settings.masquerade.body', '响应内容无效，最大 1 MiB；204/205/304 的内容必须为空');
            }
            if (!is_string($contentType) || trim($contentType) === '' || strlen($contentType) > 256 || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $contentType)) {
                $error('network_settings.masquerade.content_type', 'Content-Type 无效');
            }
        } elseif ($mode === 'file') {
            $root = $masquerade['root'] ?? null;
            if (!is_string($root) || trim($root) === '' || str_contains($root, "\0")) {
                $error('network_settings.masquerade.root', '请填写节点上的目录路径');
            }
        } elseif ($mode === 'proxy') {
            $url = $masquerade['url'] ?? null;
            $parts = is_string($url) ? parse_url($url) : false;
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
                || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
                $error('network_settings.masquerade.url', '请填写不含凭据、查询或片段的 HTTP(S) 地址');
            }
        }
    }
}

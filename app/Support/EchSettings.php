<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Validator;

final class EchSettings
{
    public static function validate(array $settings, Validator $validator, string $tlsKey = 'tls'): void
    {
        $error = static fn (string $field, string $message) => $validator->errors()->add('protocol_settings.' . $field, $message);
        $ech = $settings['ech'] ?? null;
        if ($ech === null) return;
        if (!is_array($ech) || !isset($ech['enabled']) || !is_bool($ech['enabled'])) {
            $error('ech', 'ECH enabled 必须为布尔值');
            return;
        }
        if (array_diff(array_keys($ech), ['enabled', 'mode', 'request_id', 'state', 'key_file', 'config'])
            || (isset($ech['mode']) && !in_array($ech['mode'], ['manual', 'automatic'], true))) {
            $error('ech', 'ECH 配置包含不支持的字段或模式');
            return;
        }
        if (!$ech['enabled']) {
            if (array_diff(array_keys($ech), ['enabled'])) $error('ech', '关闭 ECH 时请清除其配置');
            return;
        }
        if ($tlsKey === 'tls' && (int) ($settings['version'] ?? 2) !== 2) $error('version', 'ECH 仅支持 Hysteria2');
        if (!Hysteria2Ech::automatic($settings)) {
            $path = $ech['key_file'] ?? null;
            if (!is_string($path) || strlen($path) > 4096 || !str_starts_with($path, '/')
                || preg_match('/[\x00-\x20\x7f]/', $path) || in_array('..', explode('/', $path), true)) {
                $error('ech.key_file', '请填写节点上的绝对私钥文件路径');
            }
            if (!Hysteria2Ech::validConfig($ech['config'] ?? null)) $error('ech.config', 'ECH 公共配置无效；只接受 ECH CONFIGS 的单行 Base64，不接受私钥');
        }
        if (!Hysteria2Ech::validName(data_get($settings, $tlsKey . '.server_name'))) $error($tlsKey . '.server_name', 'ECH 需要有效的证书域名 SNI');
        if (data_get($settings, $tlsKey . '.allow_insecure')) $error($tlsKey . '.allow_insecure', 'ECH 必须启用证书验证');
    }
}

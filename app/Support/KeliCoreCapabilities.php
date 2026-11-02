<?php

namespace App\Support;

final class KeliCoreCapabilities
{
    public const MIN_VERSION = '0.1.425';
    public const PROTOCOLS = ['shadowsocks', 'vmess', 'vless', 'trojan', 'hysteria', 'tuic', 'anytls', 'naive', 'mieru', 'socks', 'http'];

    public static function supports(array $server, ?string $version): SupportResult
    {
        if (!$version || !preg_match('/^\d+\.\d+\.\d+$/D', $version) || version_compare($version, self::MIN_VERSION, '<')) {
            return SupportResult::drop('需要可识别的 kelicore 版本，请更新客户端');
        }
        $type = (string) ($server['type'] ?? '');
        $settings = (array) ($server['protocol_settings'] ?? []);
        if (!in_array($type, self::PROTOCOLS, true)) {
            return SupportResult::drop('当前 kelicore 尚未支持此协议');
        }
        $network = strtolower((string) ($settings['network'] ?? 'tcp')) ?: 'tcp';
        if (in_array($type, ['vless', 'vmess', 'trojan'], true)) {
            if (!in_array($network, ['tcp', 'ws', 'grpc', 'httpupgrade', 'http', 'h2', 'quic'], true)) {
                return SupportResult::drop('当前 kelicore 尚未支持此传输方式');
            }
            if ($network === 'tcp' && (data_get($settings, 'network_settings.header.type') ?: 'none') !== 'none') {
                return SupportResult::drop('当前 kelicore 尚未支持 TCP 头部伪装');
            }
        }
        if ($type === 'vless') {
            if (!in_array(trim((string) ($settings['encryption'] ?? 'none')), ['', 'none'], true)) {
                return SupportResult::drop('当前 kelicore 尚未支持此 VLESS 加密方式');
            }
            $flow = trim((string) ($settings['flow'] ?? ''));
            if ($flow !== '' && ($flow !== 'xtls-rprx-vision' || $network !== 'tcp' || !(int) ($settings['tls'] ?? 0))) {
                return SupportResult::drop('Vision 仅支持 TCP 与 TLS/Reality 组合');
            }
            if ((int) ($settings['tls'] ?? 0) === 2 && $network !== 'tcp') {
                return SupportResult::drop('Reality 仅支持 TCP 传输');
            }
        }
        if ($type === 'vmess' && (int) ($settings['alter_id'] ?? $settings['alterId'] ?? 0) !== 0) {
            return SupportResult::drop('当前 kelicore 仅支持 VMess AEAD');
        }
        if ($type === 'shadowsocks' && (!in_array($settings['cipher'] ?? '', ['aes-128-gcm', 'aes-256-gcm', 'chacha20-ietf-poly1305'], true) || !empty($settings['plugin']))) {
            return SupportResult::drop('当前 kelicore 仅支持传统 AEAD，不支持 SS2022 或插件');
        }
        if ($type === 'hysteria') {
            if ((int) ($settings['version'] ?? 1) !== 2) {
                return SupportResult::drop('当前 kelicore 仅支持 Hysteria 2');
            }
            if (data_get($settings, 'obfs.open') && !in_array(data_get($settings, 'obfs.type'), ['salamander', 'gecko'], true)) {
                return SupportResult::drop('当前 kelicore 尚未支持此 HY2 混淆方式');
            }
            if (Hysteria2Ech::waiting($settings) || (Hysteria2Ech::enabled($settings) && !Hysteria2Ech::validConfig(Hysteria2Ech::publicConfig($settings)))) {
                return SupportResult::drop('ECH 公共配置尚未就绪，请稍后更新订阅');
            }
        }
        if ($type === 'tuic' && ((int) ($settings['version'] ?? 5) !== 5 || !empty($settings['zero_rtt_handshake']))) {
            return SupportResult::drop('当前 kelicore 支持 TUIC v5，但不支持零往返握手');
        }
        if ($type === 'anytls' && ((int) ($settings['tls_mode'] ?? 1) !== 1 || $network !== 'tcp')) {
            return SupportResult::drop('当前 kelicore 的 AnyTLS 仅支持普通 TLS/TCP');
        }
        if ($type === 'mieru' && strtolower((string) ($settings['transport'] ?? 'tcp')) !== 'tcp') {
            return SupportResult::drop('当前 kelicore 的 Mieru 仅支持 TCP 底层传输');
        }
        if ($type === 'naive' && !in_array($network, ['tcp', 'quic'], true)) {
            return SupportResult::drop('当前 kelicore 的 Naive 仅支持 H2/H3');
        }
        if (in_array($type, ['socks', 'http'], true) && (!empty($settings['tls']) || !empty($settings['udp_over_tcp']))) {
            return SupportResult::drop('此代理的 TLS 或 UDP-over-TCP 选项尚不支持');
        }
        return SupportResult::allow();
    }
}

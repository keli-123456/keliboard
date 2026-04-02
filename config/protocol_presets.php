<?php

$transportDoc = 'https://xtls.github.io/en/config/transport.html';
$xhttpDiscussion = 'https://github.com/XTLS/Xray-core/discussions/4113';
$examplesRepo = 'https://github.com/XTLS/Xray-examples';
$tcpNetworkSettings = ['header' => ['type' => 'none']];
$anytlsDefaultPaddingScheme = [
    'stop=8',
    '0=30-30',
    '1=100-400',
    '2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000',
    '3=9-9,500-1000',
    '4=500-1000',
    '5=500-1000',
    '6=500-1000',
    '7=500-1000',
];

return [
    'vless' => [
        [
            'id' => 'vless_reality_vision',
            'label' => 'VLESS + REALITY + Vision',
            'tone' => 'recommended',
            'summary_key' => 'server.protocol_presets.summaries.vless_reality_vision',
            'fill_fields' => [
                'reality_settings.server_name',
                'reality_settings.dest',
                'reality_settings.public_key',
                'reality_settings.private_key',
                'reality_settings.short_id',
            ],
            'protocol_settings' => [
                'tls' => 2,
                'network' => 'tcp',
                'flow' => 'xtls-rprx-vision',
                'client_fingerprint' => 'chrome',
                'network_settings' => $tcpNetworkSettings,
                'reality_settings' => [
                    'server_name' => '',
                    'server_port' => 443,
                    'dest' => '',
                    'xver' => 0,
                    'public_key' => '',
                    'private_key' => '',
                    'short_id' => '',
                    'allow_insecure' => false,
                ],
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/VLESS-TCP-XTLS-Vision-REALITY"],
            ],
        ],
        [
            'id' => 'vless_tls_xhttp',
            'label' => 'VLESS + TLS + XHTTP',
            'tone' => 'recommended',
            'summary_key' => 'server.protocol_presets.summaries.vless_tls_xhttp',
            'fill_fields' => ['tls_settings.server_name', 'network_settings.path'],
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'xhttp',
                'client_fingerprint' => 'chrome',
                'tls_settings' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
                'network_settings' => [
                    'path' => '/xhttp',
                ],
            ],
            'references' => [
                ['label' => 'XHTTP', 'url' => $xhttpDiscussion],
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/VLESS-XHTTP-Reality/minimal-steal_others"],
            ],
        ],
        [
            'id' => 'vless_tls_ws',
            'label' => 'VLESS + TLS + WebSocket',
            'tone' => 'compatibility',
            'summary_key' => 'server.protocol_presets.summaries.vless_tls_ws',
            'fill_fields' => ['tls_settings.server_name', 'network_settings.path'],
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'ws',
                'client_fingerprint' => 'chrome',
                'tls_settings' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
                'network_settings' => [
                    'path' => '/ws',
                ],
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/VLESS-WSS-Nginx"],
            ],
        ],
        [
            'id' => 'vless_reality_xhttp',
            'label' => 'VLESS + REALITY + XHTTP',
            'tone' => 'advanced',
            'summary_key' => 'server.protocol_presets.summaries.vless_reality_xhttp',
            'fill_fields' => [
                'reality_settings.server_name',
                'reality_settings.dest',
                'reality_settings.public_key',
                'reality_settings.private_key',
                'reality_settings.short_id',
                'network_settings.path',
            ],
            'protocol_settings' => [
                'tls' => 2,
                'network' => 'xhttp',
                'client_fingerprint' => 'chrome',
                'reality_settings' => [
                    'server_name' => '',
                    'server_port' => 443,
                    'dest' => '',
                    'xver' => 0,
                    'public_key' => '',
                    'private_key' => '',
                    'short_id' => '',
                    'allow_insecure' => false,
                ],
                'network_settings' => [
                    'path' => '/xhttp',
                    'mode' => 'packet-up',
                ],
            ],
            'references' => [
                ['label' => 'XHTTP', 'url' => $xhttpDiscussion],
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/VLESS-XHTTP-Reality/minimal-steal_others"],
            ],
        ],
    ],
    'vmess' => [
        [
            'id' => 'vmess_tls_ws',
            'label' => 'VMess + TLS + WebSocket',
            'tone' => 'compatibility',
            'summary_key' => 'server.protocol_presets.summaries.vmess_tls_ws',
            'fill_fields' => ['tls_settings.server_name', 'network_settings.path'],
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'ws',
                'tls_settings' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
                'network_settings' => [
                    'path' => '/vmess',
                ],
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/VMess-Websocket-TLS"],
            ],
        ],
        [
            'id' => 'vmess_tls_grpc',
            'label' => 'VMess + TLS + gRPC',
            'tone' => 'compatibility',
            'summary_key' => 'server.protocol_presets.summaries.vmess_tls_grpc',
            'fill_fields' => ['tls_settings.server_name', 'network_settings.serviceName'],
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'grpc',
                'tls_settings' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
                'network_settings' => [
                    'serviceName' => 'vmess-grpc',
                ],
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
            ],
        ],
        [
            'id' => 'vmess_tcp_tls',
            'label' => 'VMess + TCP + TLS',
            'tone' => 'recommended',
            'summary_key' => 'server.protocol_presets.summaries.vmess_tcp_tls',
            'fill_fields' => ['tls_settings.server_name'],
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'tcp',
                'tls_settings' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
                'network_settings' => $tcpNetworkSettings,
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/VMess-TCP-TLS"],
            ],
        ],
    ],
    'trojan' => [
        [
            'id' => 'trojan_tcp_tls',
            'label' => 'Trojan + TCP + TLS',
            'tone' => 'recommended',
            'summary_key' => 'server.protocol_presets.summaries.trojan_tcp_tls',
            'fill_fields' => ['server_name'],
            'protocol_settings' => [
                'network' => 'tcp',
                'server_name' => '',
                'allow_insecure' => false,
                'network_settings' => $tcpNetworkSettings,
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/Trojan-TCP-TLS%20(minimal)"],
            ],
        ],
        [
            'id' => 'trojan_tls_ws',
            'label' => 'Trojan + TLS + WebSocket',
            'tone' => 'compatibility',
            'summary_key' => 'server.protocol_presets.summaries.trojan_tls_ws',
            'fill_fields' => ['server_name', 'network_settings.path'],
            'protocol_settings' => [
                'network' => 'ws',
                'server_name' => '',
                'allow_insecure' => false,
                'network_settings' => [
                    'path' => '/trojan',
                ],
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
            ],
        ],
        [
            'id' => 'trojan_tls_grpc',
            'label' => 'Trojan + TLS + gRPC',
            'tone' => 'compatibility',
            'summary_key' => 'server.protocol_presets.summaries.trojan_tls_grpc',
            'fill_fields' => ['server_name', 'network_settings.serviceName'],
            'protocol_settings' => [
                'network' => 'grpc',
                'server_name' => '',
                'allow_insecure' => false,
                'network_settings' => [
                    'serviceName' => 'trojan',
                ],
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/Trojan-gRPC-Caddy2%EF%BC%8FNginx"],
            ],
        ],
    ],
    'shadowsocks' => [
        [
            'id' => 'shadowsocks_aead',
            'label' => 'Shadowsocks AEAD',
            'tone' => 'recommended',
            'summary_key' => 'server.protocol_presets.summaries.shadowsocks_aead',
            'fill_fields' => ['cipher'],
            'protocol_settings' => [
                'cipher' => 'aes-128-gcm',
            ],
            'references' => [
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/Shadowsocks-AEAD"],
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/Shadowsocks-TCP"],
            ],
        ],
        [
            'id' => 'shadowsocks_2022',
            'label' => 'Shadowsocks 2022',
            'tone' => 'advanced',
            'summary_key' => 'server.protocol_presets.summaries.shadowsocks_2022',
            'fill_fields' => ['cipher'],
            'protocol_settings' => [
                'cipher' => '2022-blake3-aes-128-gcm',
            ],
            'references' => [
                ['label' => 'Example', 'url' => "{$examplesRepo}/tree/main/Shadowsocks-2022"],
            ],
        ],
        [
            'id' => 'shadowsocks_obfs_http',
            'label' => 'Shadowsocks + Obfs HTTP',
            'tone' => 'compatibility',
            'summary_key' => 'server.protocol_presets.summaries.shadowsocks_obfs_http',
            'fill_fields' => ['cipher', 'obfs_settings.host'],
            'protocol_settings' => [
                'cipher' => 'aes-128-gcm',
                'plugin' => 'obfs',
                'obfs' => 'http',
                'obfs_settings' => [
                    'host' => '',
                ],
                'plugin_opts' => 'obfs=http',
            ],
        ],
    ],
    'tuic' => [
        [
            'id' => 'tuic_v5_bbr_h3',
            'label' => 'TUIC v5 + BBR + h3',
            'tone' => 'recommended',
            'summary_key' => 'server.protocol_presets.summaries.tuic_v5_bbr_h3',
            'fill_fields' => ['tls.server_name'],
            'protocol_settings' => [
                'version' => 5,
                'congestion_control' => 'bbr',
                'udp_relay_mode' => 'native',
                'zero_rtt_handshake' => false,
                'alpn' => ['h3'],
                'tls' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
            ],
        ],
        [
            'id' => 'tuic_v5_cubic_h3',
            'label' => 'TUIC v5 + CUBIC + h3',
            'tone' => 'compatibility',
            'summary_key' => 'server.protocol_presets.summaries.tuic_v5_cubic_h3',
            'fill_fields' => ['tls.server_name'],
            'protocol_settings' => [
                'version' => 5,
                'congestion_control' => 'cubic',
                'udp_relay_mode' => 'native',
                'zero_rtt_handshake' => false,
                'alpn' => ['h3'],
                'tls' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
            ],
        ],
    ],
    'anytls' => [
        [
            'id' => 'anytls_tls_tcp',
            'label' => 'AnyTLS + TLS + TCP',
            'tone' => 'recommended',
            'summary_key' => 'server.protocol_presets.summaries.anytls_tls_tcp',
            'fill_fields' => ['tls.server_name'],
            'protocol_settings' => [
                'tls_mode' => 1,
                'network' => 'tcp',
                'tls' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
                'network_settings' => $tcpNetworkSettings,
                'padding_scheme' => $anytlsDefaultPaddingScheme,
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
            ],
        ],
        [
            'id' => 'anytls_reality_tcp',
            'label' => 'AnyTLS + REALITY + TCP',
            'tone' => 'advanced',
            'summary_key' => 'server.protocol_presets.summaries.anytls_reality_tcp',
            'fill_fields' => [
                'reality_settings.server_name',
                'reality_settings.dest',
                'reality_settings.public_key',
                'reality_settings.private_key',
                'reality_settings.short_id',
            ],
            'protocol_settings' => [
                'tls_mode' => 2,
                'network' => 'tcp',
                'client_fingerprint' => 'chrome',
                'network_settings' => $tcpNetworkSettings,
                'padding_scheme' => $anytlsDefaultPaddingScheme,
                'reality_settings' => [
                    'server_name' => '',
                    'server_port' => 443,
                    'dest' => '',
                    'xver' => 0,
                    'public_key' => '',
                    'private_key' => '',
                    'short_id' => '',
                    'allow_insecure' => false,
                ],
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
            ],
        ],
    ],
    'hysteria' => [
        [
            'id' => 'hysteria2_default',
            'label' => 'Hysteria2 Default',
            'tone' => 'recommended',
            'summary_key' => 'server.protocol_presets.summaries.hysteria2_default',
            'fill_fields' => ['tls.server_name'],
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
                'obfs' => [
                    'open' => false,
                    'type' => 'salamander',
                    'password' => '',
                ],
                'hop_interval' => 30,
                'bandwidth' => [],
            ],
            'form_patch' => [
                'hy_version' => '2',
                'hy_obfs_enabled' => false,
                'hy_obfs_type' => 'salamander',
                'hy_obfs_password' => '',
                'hy_sni' => '',
                'hy_up_mbps' => '',
                'hy_down_mbps' => '',
                'hy_hop_interval' => '30',
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
            ],
        ],
        [
            'id' => 'hysteria2_salamander',
            'label' => 'Hysteria2 + Salamander Obfs',
            'tone' => 'compatibility',
            'summary_key' => 'server.protocol_presets.summaries.hysteria2_salamander',
            'fill_fields' => ['tls.server_name', 'obfs.password'],
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => '',
                    'allow_insecure' => false,
                ],
                'obfs' => [
                    'open' => true,
                    'type' => 'salamander',
                    'password' => '',
                ],
                'hop_interval' => 30,
                'bandwidth' => [],
            ],
            'form_patch' => [
                'hy_version' => '2',
                'hy_obfs_enabled' => true,
                'hy_obfs_type' => 'salamander',
                'hy_obfs_password' => '',
                'hy_sni' => '',
                'hy_up_mbps' => '',
                'hy_down_mbps' => '',
                'hy_hop_interval' => '30',
            ],
            'references' => [
                ['label' => 'Transport', 'url' => $transportDoc],
            ],
        ],
    ],
];

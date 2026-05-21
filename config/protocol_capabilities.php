<?php

return [
    'runtimes' => [
        'v2node' => [
            'protocols' => [
                'vmess' => [
                    'networks' => ['tcp', 'ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp'],
                ],
                'trojan' => [
                    'networks' => ['tcp', 'ws', 'grpc'],
                ],
                'vless' => [
                    'networks' => ['tcp', 'ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp'],
                    'tls_modes' => [0, 1, 2],
                ],
                'anytls' => [
                    'networks' => ['tcp', 'ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp'],
                    'tls_modes' => [1, 2],
                    'features' => ['padding_scheme', 'reality', 'alpn'],
                ],
                'shadowsocks' => [
                    'features' => ['ports'],
                ],
                'hysteria' => [
                    'versions' => [2],
                    'features' => ['ports', 'hop_interval', 'obfs', 'bandwidth'],
                ],
                'tuic' => [
                    'versions' => [5],
                    'features' => ['alpn', 'congestion_control', 'zero_rtt_handshake'],
                ],
                'socks' => [],
                'naive' => [],
                'http' => [],
                'mieru' => [
                    'networks' => ['tcp'],
                ],
            ],
        ],
    ],

    'clients' => [
        'sing-box' => [
            'aliases' => ['sing-box', 'hiddify', 'hiddifynext', 'sfm'],
            'version_kind' => 'semver',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['shadowsocks', 'trojan', 'vmess', 'vless', 'hysteria', 'tuic', 'anytls', 'socks', 'http'],
            'variants' => [
                'hiddify' => [
                    'supports' => [
                        'hysteria' => [
                            ['when' => ['version' => 1], 'support' => 'yes'],
                            ['when' => ['version' => 2], 'support' => 'yes'],
                            ['when' => ['feature' => 'ports'], 'support' => 'yes'],
                            ['when' => ['feature' => 'hop_interval'], 'support' => 'yes'],
                            ['when' => ['feature' => 'obfs'], 'support' => 'yes'],
                            ['when' => ['feature' => 'bandwidth'], 'support' => 'yes'],
                        ],
                    ],
                ],
                'hiddifynext' => [
                    'supports' => [
                        'hysteria' => [
                            ['when' => ['version' => 1], 'support' => 'yes'],
                            ['when' => ['version' => 2], 'support' => 'yes'],
                            ['when' => ['feature' => 'ports'], 'support' => 'yes'],
                            ['when' => ['feature' => 'hop_interval'], 'support' => 'yes'],
                            ['when' => ['feature' => 'obfs'], 'support' => 'yes'],
                            ['when' => ['feature' => 'bandwidth'], 'support' => 'yes'],
                        ],
                    ],
                ],
            ],
            'supports' => [
                'vless' => [
                    ['when' => ['network' => ['tcp', 'ws', 'grpc', 'http', 'h2', 'quic']], 'support' => 'yes'],
                    ['when' => ['network' => 'httpupgrade'], 'support' => 'yes'],
                    ['when' => ['network' => ['xhttp', 'splithttp']], 'support' => 'unknown'],
                    ['when' => ['tls_mode' => 2], 'min_version' => '1.6.0', 'support' => 'yes'],
                    ['when' => ['flow' => 'xtls-rprx-vision'], 'min_version' => '1.5.0', 'support' => 'yes'],
                ],
                'anytls' => [
                    ['when' => ['network' => ['ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp']], 'support' => 'no', 'reason' => 'AnyTLS custom transport is not exported for sing-box'],
                    ['when' => [], 'min_version' => '1.12.0', 'support' => 'yes'],
                    ['when' => ['feature' => 'alpn'], 'min_version' => '1.12.0', 'support' => 'yes'],
                    ['when' => ['feature' => 'client_fingerprint'], 'min_version' => '1.12.0', 'support' => 'yes'],
                    ['when' => ['feature' => 'idle_session'], 'min_version' => '1.12.0', 'support' => 'yes'],
                    ['when' => ['tls_mode' => 2], 'support' => 'unknown'],
                ],
                'hysteria' => [
                    ['when' => ['version' => 1], 'min_version' => '1.5.0', 'support' => 'yes'],
                    ['when' => ['version' => 2], 'min_version' => '1.5.0', 'support' => 'yes'],
                    ['when' => ['feature' => 'ports'], 'min_version' => '1.11.0', 'support' => 'yes'],
                    ['when' => ['feature' => 'hop_interval'], 'min_version' => '1.11.0', 'support' => 'yes'],
                ],
                'tuic' => [
                    ['when' => ['version' => 4], 'min_version' => '1.5.0', 'support' => 'yes'],
                    ['when' => ['version' => 5], 'min_version' => '1.5.0', 'support' => 'yes'],
                    ['when' => ['feature' => 'zero_rtt_handshake'], 'support' => 'yes'],
                ],
            ],
        ],

        'mihomo' => [
            'aliases' => ['meta', 'mihomo', 'clashmeta', 'clash-meta', 'verge', 'flclash', 'nekobox', 'nekoray', 'clashmetaforandroid', 'clashx meta', 'clashxmeta'],
            'version_kind' => 'semver',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['shadowsocks', 'vmess', 'trojan', 'vless', 'hysteria', 'tuic', 'anytls', 'socks', 'http', 'mieru'],
            'variants' => [
                'nekobox' => [
                    'supports' => [
                        'hysteria' => [
                            ['when' => ['version' => 2], 'min_version' => '1.2.7', 'support' => 'yes'],
                        ],
                    ],
                ],
                'clashmetaforandroid' => [
                    'supports' => [
                        'hysteria' => [
                            ['when' => ['version' => 2], 'min_version' => '2.9.0', 'support' => 'yes'],
                        ],
                    ],
                ],
                'nekoray' => [
                    'supports' => [
                        'hysteria' => [
                            ['when' => ['version' => 2], 'min_version' => '3.24', 'support' => 'yes'],
                        ],
                    ],
                ],
                'verge' => [
                    'supports' => [
                        'hysteria' => [
                            ['when' => ['version' => 2], 'min_version' => '1.3.8', 'support' => 'yes'],
                        ],
                    ],
                ],
                'clashx meta' => [
                    'supports' => [
                        'hysteria' => [
                            ['when' => ['version' => 2], 'min_version' => '1.3.5', 'support' => 'yes'],
                        ],
                    ],
                ],
                'flclash' => [
                    'supports' => [
                        'hysteria' => [
                            ['when' => ['version' => 2], 'min_version' => '0.8.0', 'support' => 'yes'],
                        ],
                    ],
                ],
            ],
            'supports' => [
                'vless' => [
                    ['when' => ['network' => ['tcp', 'ws', 'grpc', 'http', 'h2']], 'support' => 'yes'],
                    ['when' => ['network' => ['httpupgrade', 'xhttp', 'splithttp']], 'support' => 'no', 'reason' => 'network unsupported by mihomo docs'],
                    ['when' => ['tls_mode' => 2], 'support' => 'yes'],
                    ['when' => ['flow' => 'xtls-rprx-vision'], 'support' => 'yes'],
                ],
                'anytls' => [
                    ['when' => ['network' => ['ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp']], 'support' => 'no', 'reason' => 'AnyTLS custom transport is not exported for mihomo'],
                    ['when' => [], 'support' => 'yes'],
                    ['when' => ['feature' => 'alpn'], 'support' => 'yes'],
                    ['when' => ['feature' => 'client_fingerprint'], 'support' => 'yes'],
                    ['when' => ['feature' => 'idle_session'], 'support' => 'yes'],
                    ['when' => ['tls_mode' => 2], 'support' => 'no', 'reason' => 'AnyTLS+Reality unsupported by mihomo'],
                ],
                'hysteria' => [
                    ['when' => ['version' => 1], 'support' => 'yes'],
                    ['when' => ['version' => 2], 'support' => 'yes'],
                    ['when' => ['feature' => 'ports'], 'support' => 'yes'],
                    ['when' => ['feature' => 'hop_interval'], 'support' => 'yes'],
                    ['when' => ['feature' => 'obfs'], 'support' => 'yes'],
                    ['when' => ['feature' => 'bandwidth'], 'support' => 'yes'],
                ],
                'tuic' => [
                    ['when' => ['version' => 4], 'support' => 'yes'],
                    ['when' => ['version' => 5], 'support' => 'yes'],
                    ['when' => ['feature' => 'alpn'], 'support' => 'yes'],
                    ['when' => ['feature' => 'congestion_control'], 'support' => 'yes'],
                    ['when' => ['feature' => 'udp_relay_mode'], 'support' => 'yes'],
                ],
            ],
        ],

        'stash' => [
            'aliases' => ['stash'],
            'version_kind' => 'semver',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['shadowsocks', 'vmess', 'vless', 'hysteria', 'trojan', 'tuic', 'anytls', 'socks', 'http'],
            'supports' => [
                'shadowsocks' => [
                    ['when' => ['cipher' => ['2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm', '2022-blake3-chacha20-poly1305']], 'min_version' => '3.0.0', 'support' => 'yes'],
                ],
                'vless' => [
                    ['when' => ['network' => ['tcp', 'ws', 'grpc', 'http', 'h2']], 'support' => 'yes'],
                    ['when' => ['network' => ['httpupgrade', 'xhttp', 'splithttp']], 'support' => 'no'],
                    ['when' => ['tls_mode' => 2], 'min_version' => '3.1.0', 'support' => 'yes'],
                    ['when' => ['flow' => 'xtls-rprx-vision'], 'min_version' => '3.1.0', 'support' => 'yes'],
                ],
                'anytls' => [
                    ['when' => [], 'support' => 'no', 'reason' => 'AnyTLS not treated as supported baseline'],
                ],
                'hysteria' => [
                    ['when' => ['version' => 1], 'min_version' => '2.0.0', 'support' => 'yes'],
                    ['when' => ['version' => 2], 'min_version' => '2.5.0', 'support' => 'yes'],
                    ['when' => ['feature' => 'ports'], 'min_version' => '2.6.4', 'support' => 'yes'],
                    ['when' => ['feature' => 'hop_interval'], 'min_version' => '2.6.4', 'support' => 'yes'],
                ],
                'tuic' => [
                    ['when' => ['version' => 4], 'min_version' => '2.3.0', 'support' => 'yes'],
                    ['when' => ['version' => 5], 'min_version' => '2.3.0', 'support' => 'yes'],
                    ['when' => ['feature' => 'zero_rtt_handshake'], 'support' => 'no', 'reason' => 'TUIC zero-RTT is not exported for stash'],
                ],
            ],
        ],

        'shadowrocket' => [
            'aliases' => ['shadowrocket'],
            'version_kind' => 'build',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['shadowsocks', 'vmess', 'vless', 'trojan', 'hysteria', 'tuic', 'anytls', 'socks'],
            'supports' => [
                'vless' => [
                    ['when' => ['network' => ['tcp', 'ws', 'grpc', 'kcp', 'httpupgrade', 'xhttp']], 'support' => 'yes'],
                    ['when' => ['network' => 'splithttp'], 'support' => 'no', 'reason' => 'VLESS splithttp is not exported for shadowrocket'],
                    ['when' => ['tls_mode' => 2], 'support' => 'yes'],
                    ['when' => ['flow' => 'xtls-rprx-splice'], 'support' => 'no', 'reason' => 'VLESS splice flow is not exported for shadowrocket'],
                ],
                'anytls' => [
                    ['when' => ['network' => ['ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp']], 'support' => 'no', 'reason' => 'AnyTLS custom transport is not exported for shadowrocket'],
                    ['when' => ['tls_mode' => 2], 'support' => 'no', 'reason' => 'AnyTLS reality is not exported for shadowrocket'],
                    ['when' => ['feature' => 'alpn'], 'support' => 'no', 'reason' => 'AnyTLS ALPN is not exported for shadowrocket'],
                    ['when' => ['feature' => 'client_fingerprint'], 'support' => 'no', 'reason' => 'AnyTLS client fingerprint is not exported for shadowrocket'],
                    ['when' => ['feature' => 'idle_session'], 'support' => 'no', 'reason' => 'AnyTLS idle session fields are not exported for shadowrocket'],
                    ['when' => [], 'min_version' => '2592', 'support' => 'yes'],
                ],
                'hysteria' => [
                    ['when' => ['version' => 1], 'support' => 'yes'],
                    ['when' => ['version' => 2], 'min_version' => '1993', 'support' => 'yes'],
                ],
                'tuic' => [
                    ['when' => ['version' => 4], 'support' => 'yes'],
                    ['when' => ['version' => 5], 'support' => 'yes'],
                    ['when' => ['feature' => 'zero_rtt_handshake'], 'support' => 'no', 'reason' => 'TUIC zero-RTT is not exported for shadowrocket'],
                    ['when' => ['udp_relay_mode' => 'quic'], 'support' => 'no', 'reason' => 'TUIC udp relay mode is not exported for shadowrocket'],
                    ['when' => ['congestion_control' => ['bbr', 'new_reno']], 'support' => 'no', 'reason' => 'TUIC congestion control is not exported for shadowrocket'],
                ],
            ],
        ],

        'loon' => [
            'aliases' => ['loon'],
            'version_kind' => 'build',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['shadowsocks', 'vmess', 'trojan', 'hysteria', 'anytls'],
            'supports' => [
                'anytls' => [
                    ['when' => ['network' => ['ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp']], 'support' => 'no', 'reason' => 'AnyTLS custom transport is not exported for loon'],
                    ['when' => ['tls_mode' => 2], 'support' => 'no', 'reason' => 'AnyTLS reality is not exported for loon'],
                    ['when' => ['feature' => 'alpn'], 'support' => 'no', 'reason' => 'AnyTLS ALPN is not exported for loon'],
                    ['when' => ['feature' => 'client_fingerprint'], 'support' => 'no', 'reason' => 'AnyTLS client fingerprint is not exported for loon'],
                    ['when' => ['feature' => 'idle_session'], 'support' => 'no', 'reason' => 'AnyTLS idle session fields are not exported for loon'],
                    ['when' => ['feature' => 'padding_scheme'], 'support' => 'no', 'reason' => 'AnyTLS padding scheme is not exported for loon'],
                    ['when' => [], 'min_version' => '941', 'support' => 'yes'],
                ],
                'hysteria' => [
                    ['when' => ['version' => 1], 'support' => 'no', 'reason' => 'Loon exporter only emits hysteria2'],
                    ['when' => ['version' => 2], 'min_version' => '637', 'support' => 'yes'],
                ],
            ],
        ],

        'quantumult-x' => [
            'aliases' => ['quantumult%20x', 'quantumult-x'],
            'version_kind' => 'semver',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['shadowsocks', 'vmess', 'vless', 'trojan'],
            'supports' => [
                'vmess' => [
                    ['when' => ['network' => ['tcp', 'ws', null]], 'support' => 'yes'],
                    ['when' => ['network' => ['grpc', 'http', 'h2', 'httpupgrade', 'quic', 'xhttp', 'splithttp', 'kcp']], 'support' => 'no', 'reason' => 'Quantumult X only exports vmess tcp/ws'],
                ],
                'vless' => [
                    ['when' => ['feature' => 'encryption'], 'support' => 'no', 'reason' => 'Quantumult X does not export VLESS encryption'],
                    ['when' => ['network' => ['tcp', 'ws', null]], 'support' => 'yes'],
                    ['when' => ['network' => ['grpc', 'http', 'h2', 'httpupgrade', 'quic', 'xhttp', 'splithttp', 'kcp']], 'support' => 'no', 'reason' => 'Quantumult X only exports vless tcp/ws'],
                ],
                'trojan' => [
                    ['when' => ['network' => ['tcp', 'ws', null]], 'support' => 'yes'],
                    ['when' => ['network' => ['grpc', 'http', 'h2', 'httpupgrade', 'quic', 'xhttp', 'splithttp', 'kcp']], 'support' => 'no', 'reason' => 'Quantumult X only exports trojan tcp/ws'],
                ],
            ],
        ],

        'surge' => [
            'aliases' => ['surge'],
            'version_kind' => 'build',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['shadowsocks', 'vmess', 'trojan', 'hysteria', 'anytls', 'socks', 'http'],
            'supports' => [
                'hysteria' => [
                    ['when' => ['version' => 1], 'support' => 'no', 'reason' => 'Surge exporter only emits hysteria2'],
                    ['when' => ['version' => 2], 'min_version' => '2398', 'support' => 'yes'],
                ],
                'anytls' => [
                    ['when' => ['tls_mode' => 2], 'support' => 'no', 'reason' => 'AnyTLS reality is not exported for surge'],
                    ['when' => ['feature' => 'alpn'], 'support' => 'no', 'reason' => 'AnyTLS ALPN is not exported for surge'],
                    ['when' => ['feature' => 'client_fingerprint'], 'support' => 'no', 'reason' => 'AnyTLS client fingerprint is not exported for surge'],
                    ['when' => ['feature' => 'idle_session'], 'support' => 'no', 'reason' => 'AnyTLS idle session fields are not exported for surge'],
                    ['when' => [], 'support' => 'yes'],
                ],
            ],
        ],

        'surfboard' => [
            'aliases' => ['surfboard'],
            'version_kind' => 'semver',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['shadowsocks', 'vmess', 'trojan', 'anytls'],
            'supports' => [
                'anytls' => [
                    ['when' => ['tls_mode' => 2], 'support' => 'no', 'reason' => 'AnyTLS reality is not exported for surfboard'],
                    ['when' => ['network' => ['ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp']], 'support' => 'no', 'reason' => 'AnyTLS custom transport is not exported for surfboard'],
                    ['when' => ['feature' => 'alpn'], 'support' => 'no', 'reason' => 'AnyTLS ALPN is not exported for surfboard'],
                    ['when' => ['feature' => 'client_fingerprint'], 'support' => 'no', 'reason' => 'AnyTLS client fingerprint is not exported for surfboard'],
                    ['when' => ['feature' => 'idle_session'], 'support' => 'no', 'reason' => 'AnyTLS idle session fields are not exported for surfboard'],
                    ['when' => ['feature' => 'padding_scheme'], 'support' => 'no', 'reason' => 'AnyTLS padding scheme is not exported for surfboard'],
                    ['when' => [], 'support' => 'yes'],
                ],
            ],
        ],

        'general' => [
            'aliases' => ['general', 'passwall', 'ssrplus', 'sagernet'],
            'version_kind' => 'semver',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['vmess', 'vless', 'shadowsocks', 'trojan', 'hysteria', 'socks'],
            'supports' => [
                'hysteria' => [
                    ['when' => ['version' => 1], 'support' => 'no', 'reason' => 'General exporter only emits hysteria2'],
                    ['when' => ['version' => 2], 'support' => 'yes'],
                ],
            ],
        ],

        'v2rayng' => [
            'aliases' => ['v2rayng'],
            'version_kind' => 'semver',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['vmess', 'vless', 'shadowsocks', 'trojan', 'hysteria', 'socks'],
            'supports' => [
                'hysteria' => [
                    ['when' => ['version' => 1], 'support' => 'no', 'reason' => 'General exporter only emits hysteria2'],
                    ['when' => ['version' => 2], 'min_version' => '1.9.5', 'support' => 'yes'],
                ],
            ],
        ],

        'v2rayn' => [
            'aliases' => ['v2rayn'],
            'version_kind' => 'semver',
            'unknown_version_policy' => 'conservative',
            'protocols' => ['vmess', 'vless', 'shadowsocks', 'trojan', 'hysteria', 'socks'],
            'supports' => [
                'hysteria' => [
                    ['when' => ['version' => 1], 'support' => 'no', 'reason' => 'General exporter only emits hysteria2'],
                    ['when' => ['version' => 2], 'min_version' => '6.31', 'support' => 'yes'],
                ],
            ],
        ],
    ],
];

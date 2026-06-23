<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\ServerService;
use App\Support\ProtocolCapabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    private const DEFAULT_PROTOCOL_OPTIONS = [
        'shadowsocks' => [
            'ciphers' => [
                'aes-128-gcm',
                'aes-192-gcm',
                'aes-256-gcm',
                'chacha20-ietf-poly1305',
                '2022-blake3-aes-128-gcm',
                '2022-blake3-aes-256-gcm',
            ],
            'plugins' => ['none', 'obfs', 'v2ray-plugin'],
            'obfs_modes' => ['http', 'tls'],
            'v2ray_modes' => ['websocket', 'quic'],
        ],
        'vless' => [
            'networkOptions' => ['tcp', 'ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp'],
            'flowOptions' => ['none', 'xtls-rprx-vision', 'xtls-rprx-direct', 'xtls-rprx-splice'],
        ],
    ];

    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers();
        $groupIds = $servers
            ->flatMap(fn (Server $server): array => (array) ($server->group_ids ?? []))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $groups = $groupIds->isEmpty()
            ? collect()
            : ServerGroup::whereIn('id', $groupIds)->get(['name', 'id'])->keyBy('id');
        $parentIds = $servers
            ->pluck('parent_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $parents = $parentIds->isEmpty()
            ? collect()
            : Server::whereIn('id', $parentIds)->get()->keyBy('id');

        $servers = $servers->map(function (Server $item) use ($groups, $parents) {
            $item['groups'] = collect((array) ($item->group_ids ?? []))
                ->map(fn ($id) => $groups->get((int) $id))
                ->filter()
                ->values();
            $item['parent'] = $item->parent_id ? $parents->get((int) $item->parent_id) : null;
            return $item;
        });
        return $this->success($servers);
    }

    public function getOptions()
    {
        $version = Server::query()
            ->selectRaw('COUNT(*) as server_count, COALESCE(MAX(updated_at), 0) as server_updated_at')
            ->first();
        $cacheKey = sprintf(
            'admin:server:options:%s:%s',
            (int) ($version->server_count ?? 0),
            (int) ($version->server_updated_at ?? 0)
        );

        $options = Cache::remember($cacheKey, now()->addMinutes(10), function (): array {
            $options = self::DEFAULT_PROTOCOL_OPTIONS;
            $vlessEnums = Server::getProtocolEnums(Server::TYPE_VLESS);
            if (!empty($vlessEnums['network'])) {
                $options['vless']['networkOptions'] = $vlessEnums['network'];
            }
            if (!empty($vlessEnums['flow'])) {
                $options['vless']['flowOptions'] = $vlessEnums['flow'];
            }

            Server::query()->get(['type', 'protocol_settings'])->each(function (Server $server) use (&$options) {
                $settings = is_array($server->protocol_settings) ? $server->protocol_settings : [];

                if ($server->type === Server::TYPE_SHADOWSOCKS) {
                    $this->pushOption($options['shadowsocks']['ciphers'], data_get($settings, 'cipher'));

                    $plugin = $this->normalizeShadowsocksPlugin(
                        data_get($settings, 'plugin'),
                        data_get($settings, 'obfs')
                    );
                    $this->pushOption($options['shadowsocks']['plugins'], $plugin ?: 'none');

                    $pluginOptions = $this->parsePluginOptions(data_get($settings, 'plugin_opts'));
                    if ($plugin === 'obfs' || data_get($settings, 'obfs')) {
                        $this->pushOption($options['shadowsocks']['obfs_modes'], $pluginOptions['obfs'] ?? data_get($settings, 'obfs'));
                    }
                    if ($plugin === 'v2ray-plugin') {
                        $this->pushOption($options['shadowsocks']['v2ray_modes'], $pluginOptions['mode'] ?? null);
                    }
                }

                if ($server->type === Server::TYPE_VLESS) {
                    $this->pushOption($options['vless']['networkOptions'], data_get($settings, 'network'));
                    $this->pushOption($options['vless']['flowOptions'], data_get($settings, 'flow'));
                }
            });

            return $options;
        });

        return $this->success($options);
    }

    public function getCapabilities(Request $request, ProtocolCapabilityService $capabilities)
    {
        $requestedType = $request->query('type');
        if ($requestedType !== null && !Server::isValidType($requestedType)) {
            return $this->fail([422, '节点类型不存在']);
        }

        $type = Server::normalizeType($requestedType);
        $types = $type ? [$type] : [
            Server::TYPE_VMESS,
            Server::TYPE_TROJAN,
            Server::TYPE_VLESS,
            Server::TYPE_SHADOWSOCKS,
            Server::TYPE_ANYTLS,
            Server::TYPE_HYSTERIA,
            Server::TYPE_TUIC,
            Server::TYPE_SOCKS,
            Server::TYPE_NAIVE,
            Server::TYPE_HTTP,
            Server::TYPE_MIERU,
        ];

        $clientLabels = [
            'sing-box' => 'sing-box / Hiddify',
            'mihomo' => 'Mihomo / Clash',
            'stash' => 'Stash',
            'shadowrocket' => 'Shadowrocket',
            'loon' => 'Loon',
            'quantumult-x' => 'Quantumult X',
            'surge' => 'Surge',
            'surfboard' => 'Surfboard',
            'general' => 'General / Passwall / SSRPlus / SagerNet',
            'v2rayng' => 'v2rayNG',
            'v2rayn' => 'v2rayN',
        ];

        $clients = [];
        foreach ($capabilities->getClientDefinitions() as $family => $definition) {
            $clients[$family] = [
                'label' => $clientLabels[$family] ?? $family,
                'aliases' => array_values($definition['aliases'] ?? []),
                'version_kind' => $definition['version_kind'] ?? 'semver',
                'unknown_version_policy' => $definition['unknown_version_policy'] ?? 'conservative',
                'primary_scope' => $definition['primary_scope'] ?? ProtocolCapabilityService::DEFAULT_CLIENT_SCOPE,
                'evaluated_scopes' => array_values($definition['evaluated_scopes'] ?? [ProtocolCapabilityService::DEFAULT_CLIENT_SCOPE]),
            ];
        }

        $runtimeProtocols = [];
        foreach ($capabilities->getRuntimeDefinitions() as $runtime => $definition) {
            $runtimeProtocols[$runtime] = [
                'label' => $runtime,
                'protocols' => array_keys($definition['protocols'] ?? []),
            ];
        }

        $typeDefinitions = [];
        foreach ($types as $serverType) {
            $typeDefinitions[$serverType] = [
                'label' => strtoupper($serverType),
                'schema' => Server::getProtocolSchema($serverType),
                'defaults' => Server::getProtocolDefaults($serverType),
                'enums' => Server::getProtocolEnums($serverType),
                'presets' => $this->buildProtocolPresets($serverType, $capabilities),
                'runtime_support' => [
                    'v2node' => [
                        'supported' => $capabilities->supportsRuntime('v2node', [
                            'type' => $serverType,
                            'protocol_settings' => Server::getProtocolDefaults($serverType),
                        ])->supported,
                    ],
                ],
            ];
        }

        return $this->success([
            'version' => 1,
            'capability_scopes' => [
                'subscription' => [
                    'label' => '订阅导入',
                    'description' => '当前兼容矩阵按订阅/远程配置导入评估，不按单节点 URI 参数完整度评估。',
                ],
                'node_uri' => [
                    'label' => '单节点 URI',
                    'description' => '单节点导入兼容性保留给单独矩阵，不与订阅导入结论混用。',
                ],
            ],
            'clients' => $clients,
            'runtimes' => $runtimeProtocols,
            'types' => $typeDefinitions,
            'combination_rules' => [
                [
                    'id' => 'anytls_reality_mihomo_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_ANYTLS,
                        'protocol_settings.tls_mode' => 2,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'mihomo' => 'block',
                        'stash' => 'block',
                        'sing-box' => 'partial',
                        'shadowrocket' => 'partial',
                        'quantumult-x' => 'partial',
                    ],
                    'message' => 'Mihomo 不支持 AnyTLS + Reality',
                ],
                [
                    'id' => 'vless_xhttp_core_version_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_VLESS,
                        'protocol_settings.network' => 'xhttp',
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'mihomo' => 'partial',
                        'stash' => 'block',
                        'sing-box' => 'partial',
                        'shadowrocket' => 'partial',
                    ],
                    'message' => 'VLESS XHTTP 对 Mihomo/Clash 需要 mihomo core 1.19.22+；无法确认核心版本时订阅会自动过滤',
                ],
                [
                    'id' => 'vless_splithttp_general_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_VLESS,
                        'protocol_settings.network' => 'splithttp',
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'mihomo' => 'block',
                        'stash' => 'block',
                        'sing-box' => 'partial',
                        'shadowrocket' => 'partial',
                    ],
                    'message' => 'VLESS SplitHTTP 当前不应标记为通用客户端可用',
                ],
                [
                    'id' => 'vless_splithttp_shadowrocket_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_VLESS,
                        'protocol_settings.network' => 'splithttp',
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'shadowrocket' => 'block',
                    ],
                    'message' => 'Shadowrocket 当前不导出 VLESS splithttp',
                ],
                [
                    'id' => 'vless_quantumultx_network_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_VLESS,
                        'protocol_settings.network' => ['grpc', 'http', 'h2', 'httpupgrade', 'quic', 'xhttp', 'splithttp'],
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'quantumult-x' => 'block',
                    ],
                    'message' => 'Quantumult X 当前仅导出 VLESS tcp/ws',
                ],
                [
                    'id' => 'vmess_quantumultx_network_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_VMESS,
                        'protocol_settings.network' => ['grpc', 'http', 'h2', 'httpupgrade', 'quic', 'xhttp', 'splithttp', 'kcp'],
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'quantumult-x' => 'block',
                    ],
                    'message' => 'Quantumult X 当前仅导出 VMess tcp/ws',
                ],
                [
                    'id' => 'trojan_quantumultx_network_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_TROJAN,
                        'protocol_settings.network' => ['grpc', 'http', 'h2', 'httpupgrade', 'quic', 'xhttp', 'splithttp', 'kcp'],
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'quantumult-x' => 'block',
                    ],
                    'message' => 'Quantumult X 当前仅导出 Trojan tcp/ws',
                ],
                [
                    'id' => 'vless_quantumultx_encryption_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_VLESS,
                        'protocol_settings.encryption' => 'mlkem768x25519plus',
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'quantumult-x' => 'block',
                    ],
                    'message' => 'Quantumult X 当前不导出 VLESS 加密扩展',
                ],
                [
                    'id' => 'vless_legacy_family_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_VLESS,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'surge' => 'block',
                        'surfboard' => 'block',
                        'loon' => 'block',
                    ],
                    'message' => 'Surge / Surfboard / Loon 当前不导出 VLESS',
                ],
                [
                    'id' => 'vless_shadowrocket_splice_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_VLESS,
                        'protocol_settings.flow' => 'xtls-rprx-splice',
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'shadowrocket' => 'block',
                    ],
                    'message' => 'Shadowrocket 当前不导出 VLESS xtls-rprx-splice',
                ],
                [
                    'id' => 'vless_kcp_runtime_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_VLESS,
                        'protocol_settings.network' => 'kcp',
                    ],
                    'runtime' => [
                        'v2node' => 'block',
                    ],
                    'clients' => [
                        'mihomo' => 'block',
                        'stash' => 'block',
                        'sing-box' => 'block',
                        'shadowrocket' => 'partial',
                    ],
                    'message' => 'v2node 当前不支持 VLESS mKCP 传输',
                ],
                [
                    'id' => 'anytls_transport_general_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_ANYTLS,
                        'protocol_settings.network' => ['ws', 'grpc', 'httpupgrade', 'xhttp', 'splithttp'],
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'mihomo' => 'block',
                        'stash' => 'block',
                        'sing-box' => 'block',
                        'shadowrocket' => 'block',
                        'quantumult-x' => 'block',
                    ],
                    'message' => 'AnyTLS 自定义传输当前无法导出到主流客户端',
                ],
                [
                    'id' => 'hysteria1_general_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_HYSTERIA,
                        'protocol_settings.version' => 1,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'general' => 'block',
                        'v2rayng' => 'block',
                        'v2rayn' => 'block',
                    ],
                    'message' => 'General / v2rayNG / v2rayN 当前仅按 Hysteria2 导出',
                ],
                [
                    'id' => 'hysteria2_v2ray_version_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_HYSTERIA,
                        'protocol_settings.version' => 2,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'general' => 'allow',
                        'v2rayng' => 'partial',
                        'v2rayn' => 'partial',
                    ],
                    'message' => 'Hysteria2 对 v2rayNG 需 1.9.5+，对 v2rayN 需 6.31+',
                ],
                [
                    'id' => 'hysteria2_mihomo_variant_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_HYSTERIA,
                        'protocol_settings.version' => 2,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'mihomo' => 'partial',
                    ],
                    'message' => 'Hysteria2 对部分 Mihomo 客户端有最低版本要求：Verge 1.3.8+、ClashX Meta 1.3.5+、FLClash 0.8.0+、NekoBox 1.2.7+、NekoRay 3.24+、Android 2.9.0+',
                ],
                [
                    'id' => 'hysteria2_loon_version_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_HYSTERIA,
                        'protocol_settings.version' => 2,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'loon' => 'partial',
                    ],
                    'message' => 'Hysteria2 对 Loon 需 637+，且当前仅导出 Hysteria2',
                ],
                [
                    'id' => 'hysteria2_surge_version_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_HYSTERIA,
                        'protocol_settings.version' => 2,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'surge' => 'partial',
                    ],
                    'message' => 'Hysteria2 对 Surge 需 2398+',
                ],
                [
                    'id' => 'hysteria2_stash_port_hop_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_HYSTERIA,
                        'protocol_settings.version' => 2,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'stash' => 'partial',
                    ],
                    'message' => 'Stash 的 Hysteria2 端口跳跃与 hop_interval 需 2.6.4+',
                ],
                [
                    'id' => 'hysteria_legacy_family_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_HYSTERIA,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'quantumult-x' => 'block',
                        'surfboard' => 'block',
                    ],
                    'message' => 'Quantumult X / Surfboard 当前不导出 Hysteria',
                ],
                [
                    'id' => 'shadowsocks2022_stash_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_SHADOWSOCKS,
                        'protocol_settings.cipher' => ['2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm', '2022-blake3-chacha20-poly1305'],
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'stash' => 'partial',
                    ],
                    'message' => 'Stash 的 Shadowsocks 2022 需 3.0.0+',
                ],
                [
                    'id' => 'anytls_general_family_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_ANYTLS,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'general' => 'block',
                        'v2rayng' => 'block',
                        'v2rayn' => 'block',
                    ],
                    'message' => 'General / v2rayNG / v2rayN 当前不导出 AnyTLS',
                ],
                [
                    'id' => 'anytls_loon_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_ANYTLS,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'loon' => 'block',
                    ],
                    'message' => 'Loon 当前不导出 AnyTLS',
                ],
                [
                    'id' => 'anytls_legacy_feature_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_ANYTLS,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'mihomo' => 'partial',
                        'quantumult-x' => 'partial',
                        'shadowrocket' => 'partial',
                        'surge' => 'partial',
                        'surfboard' => 'partial',
                    ],
                    'message' => 'AnyTLS 对 Mihomo/Clash 需要 mihomo core 1.19.3+；未知或旧版本订阅会自动过滤，其他客户端也只适合部分字段',
                ],
                [
                    'id' => 'tuic_general_family_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_TUIC,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'general' => 'block',
                        'v2rayng' => 'block',
                        'v2rayn' => 'block',
                    ],
                    'message' => 'General / v2rayNG / v2rayN 当前不导出 TUIC',
                ],
                [
                    'id' => 'tuic_legacy_family_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_TUIC,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'quantumult-x' => 'block',
                        'surge' => 'block',
                        'surfboard' => 'block',
                        'loon' => 'block',
                    ],
                    'message' => 'Quantumult X / Surge / Surfboard / Loon 当前不导出 TUIC',
                ],
                [
                    'id' => 'tuic_partial_feature_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_TUIC,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'stash' => 'partial',
                        'shadowrocket' => 'partial',
                    ],
                    'message' => 'TUIC 的 zero-RTT 以及部分高级项并非所有客户端都能完整导出；Stash 不导出 zero-RTT，Shadowrocket 不导出 zero-RTT / 非默认 congestion_control / udp_relay_mode',
                ],
                [
                    'id' => 'naive_quic_shadowrocket_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_NAIVE,
                        'protocol_settings.network' => 'quic',
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'shadowrocket' => 'block',
                    ],
                    'message' => 'Shadowrocket 订阅当前仅导出 Naive HTTPS，不导出 Naive QUIC',
                ],
                [
                    'id' => 'naive_shadowrocket_public_tls_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_NAIVE,
                        'protocol_settings.tls' => 0,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'shadowrocket' => 'block',
                    ],
                    'message' => 'Shadowrocket 的 Naive 订阅导出要求公开 TLS',
                ],
                [
                    'id' => 'naive_shadowrocket_insecure_tls_block',
                    'type' => 'block',
                    'when' => [
                        'server_type' => Server::TYPE_NAIVE,
                        'protocol_settings.tls_settings.allow_insecure' => true,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'shadowrocket' => 'block',
                    ],
                    'message' => 'Shadowrocket 的 Naive 订阅导出不表达跳过证书校验',
                ],
                [
                    'id' => 'mieru_shadowrocket_version_warn',
                    'type' => 'warn',
                    'when' => [
                        'server_type' => Server::TYPE_MIERU,
                    ],
                    'runtime' => [
                        'v2node' => 'allow',
                    ],
                    'clients' => [
                        'mihomo' => 'partial',
                        'shadowrocket' => 'partial',
                    ],
                    'message' => 'Mieru 对 Mihomo/Clash 需要 mihomo core 1.19.22+，Shadowrocket 也需要较新版本；旧版客户端不会下发',
                ],
            ],
        ]);
    }

    private function buildProtocolPresets(string $serverType, ProtocolCapabilityService $capabilities): array
    {
        return array_values(array_map(function (array $preset) use ($serverType, $capabilities) {
            $protocolSettings = is_array($preset['protocol_settings'] ?? null) ? $preset['protocol_settings'] : [];
            $runtimeSupport = $capabilities->supportsRuntime('v2node', [
                'type' => $serverType,
                'protocol_settings' => $protocolSettings,
            ]);
            $warningKeys = $this->resolveV2NodePresetWarningKeys($serverType, $protocolSettings);

            $preset['runtime_support'] = [
                'v2node' => [
                    'supported' => $runtimeSupport->supported && empty($warningKeys),
                    'warnings' => array_values($warningKeys),
                ],
            ];

            return $preset;
        }, Server::getProtocolPresets($serverType)));
    }

    private function resolveV2NodePresetWarningKeys(string $serverType, array $protocolSettings): array
    {
        $warnings = [];
        $type = Server::normalizeType($serverType) ?? $serverType;

        if ($type === Server::TYPE_TUIC) {
            if ((int) data_get($protocolSettings, 'version', 5) !== 5) {
                $warnings[] = 'server.v2node.runtime_notice.tuic_version';
            }

            $udpRelayMode = strtolower(trim((string) data_get($protocolSettings, 'udp_relay_mode', 'native')));
            if ($udpRelayMode !== '' && $udpRelayMode !== 'native') {
                $warnings[] = 'server.v2node.runtime_notice.tuic_udp_relay_mode';
            }
        }

        if ($type === Server::TYPE_ANYTLS) {
            $clientFingerprint = trim((string) data_get($protocolSettings, 'client_fingerprint', ''));
            if ($clientFingerprint !== '') {
                $warnings[] = 'server.v2node.runtime_notice.anytls_client_fingerprint';
            }

            foreach (['idle_session_check_interval', 'idle_session_timeout', 'min_idle_session'] as $field) {
                if (data_get($protocolSettings, $field) !== null) {
                    $warnings[] = 'server.v2node.runtime_notice.anytls_idle_session';
                    break;
                }
            }
        }

        if ($type === Server::TYPE_SHADOWSOCKS) {
            $plugin = strtolower(trim((string) data_get($protocolSettings, 'plugin', '')));
            if ($plugin !== '' && $plugin !== 'none') {
                $warnings[] = 'server.v2node.runtime_notice.shadowsocks_plugin';
            }
        }

        return array_values(array_unique($warnings));
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                $oldGroupIds = $this->normalizeIdList((array) ($server->group_ids ?? []));
                $server->update($params);
                $publisher = app(NodeRealtimePublisher::class);
                $publisher->invalidateConfigForServers([(int) $server->id], 'admin.server.saved');
                $newGroupIds = $this->normalizeIdList((array) ($server->group_ids ?? []));
                if ($oldGroupIds !== $newGroupIds) {
                    $publisher->invalidateUsersForServers([(int) $server->id], 'admin.server.groups_saved');
                }
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            Server::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }


    }

    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'show' => 'integer',
            'enabled' => 'nullable|integer',
        ]);

        $updates = [];
        if ($request->has('show')) {
            $updates['show'] = (int) $request->show;
        }
        if ($request->has('enabled')) {
            $updates['enabled'] = (int) $request->enabled ? 1 : 0;
        }
        if (empty($updates) || !Server::where('id', $request->id)->update($updates)) {
            return $this->fail([500, '保存失败']);
        }
        app(NodeRealtimePublisher::class)->invalidateConfigForServers([(int) $request->id], 'admin.server.updated');
        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        $serverId = (int) $server->id;
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }
        app(NodeRealtimePublisher::class)->invalidateConfigForServers([$serverId], 'admin.server.deleted');
        return $this->success(true);
    }


    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        $server->show = 0;
        $server->code = null;
        Server::create($server->toArray());
        return $this->success(true);
    }

    private function pushOption(array &$options, $value): void
    {
        $text = trim((string) $value);
        if ($text === '' || in_array($text, $options, true)) {
            return;
        }
        $options[] = $text;
    }

    private function normalizeShadowsocksPlugin($plugin, $obfs): string
    {
        $text = trim((string) $plugin);
        if ($text === 'obfs-local' || $text === 'simple-obfs') {
            return 'obfs';
        }
        if ($text !== '') {
            return $text;
        }
        return trim((string) $obfs) !== '' ? 'obfs' : '';
    }

    private function parsePluginOptions($raw): array
    {
        $pairs = [];
        foreach (explode(';', (string) $raw) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || strpos($segment, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $segment, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            $pairs[$key] = trim($value);
        }
        return $pairs;
    }

    private function normalizeIdList(array $values): array
    {
        $ids = array_map(
            fn ($value): int => (int) $value,
            array_filter($values, fn ($value): bool => is_numeric($value))
        );
        $ids = array_values(array_unique(array_filter($ids, fn (int $value): bool => $value > 0)));
        sort($ids);

        return $ids;
    }
}

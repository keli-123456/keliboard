<?php


namespace App\Http\Requests\Admin;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ServerSave extends FormRequest
{
    private const V2NODE_SUPPORTED_TYPES = [
        Server::TYPE_VMESS,
        Server::TYPE_VLESS,
        Server::TYPE_TROJAN,
        Server::TYPE_SHADOWSOCKS,
        Server::TYPE_HYSTERIA,
        Server::TYPE_TUIC,
        Server::TYPE_ANYTLS,
        Server::TYPE_SOCKS,
        Server::TYPE_HTTP,
        Server::TYPE_MIERU,
    ];

    private const V2NODE_REALITY_UNSUPPORTED_NETWORKS = [
        'ws',
        'httpupgrade',
    ];

    private const TLS_CERT_RULES = [
        'tls_settings.cert_mode' => 'nullable|string|in:file,dns,http,self',
        'tls_settings.cert_file' => 'nullable|string',
        'tls_settings.key_file' => 'nullable|string',
        'tls_settings.provider' => 'nullable|string',
        'tls_settings.dns_env' => 'nullable|string',
        'tls_settings.reject_unknown_sni' => 'nullable|in:0,1',
    ];

    private const PROTOCOL_RULES = [
        'shadowsocks' => [
            'cipher' => 'required|string',
            'obfs' => 'nullable|string',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
            'plugin' => 'nullable|string',
            'plugin_opts' => 'nullable|string',
        ],
        'vmess' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
            ...self::TLS_CERT_RULES,
        ],
        'trojan' => [
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable|string',
            'allow_insecure' => 'nullable|boolean',
            ...self::TLS_CERT_RULES,
        ],
        'hysteria' => [
            'version' => 'required|integer',
            'alpn' => 'nullable|string',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'string|nullable',
            'obfs.password' => 'string|nullable',
            'tls.server_name' => 'nullable|string',
            'tls.allow_insecure' => 'nullable|boolean',
            ...self::TLS_CERT_RULES,
            'bandwidth.up' => 'nullable|integer',
            'bandwidth.down' => 'nullable|integer',
            'hop_interval' => 'integer|nullable',
        ],
        'vless' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
            ...self::TLS_CERT_RULES,
            'reality_settings.allow_insecure' => 'nullable|boolean',
            'reality_settings.server_name' => 'nullable|string',
            'reality_settings.server_port' => 'nullable|integer',
            'reality_settings.public_key' => 'nullable|string',
            'reality_settings.private_key' => 'nullable|string',
            'reality_settings.short_id' => 'nullable|string',
        ],
        'socks' => [
            'tls' => 'nullable|integer',
            'tls_settings' => 'nullable|array',
        ],
        'naive' => [
            'tls' => 'required|integer',
            'tls_settings' => 'nullable|array',
        ],
        'http' => [
            'tls' => 'required|integer',
            'tls_settings' => 'nullable|array',
        ],
        'mieru' => [
            'transport' => 'required|string',
            'multiplexing' => 'required|string',
        ],
        'anytls' => [
            'tls_mode' => 'nullable|integer|in:1,2',
            'network' => 'nullable|string',
            'network_settings' => 'nullable|array',
            'tls' => 'nullable|array',
            'alpn' => 'nullable|string',
            'padding_scheme' => 'nullable|array',
            'reality_settings.allow_insecure' => 'nullable|boolean',
            'reality_settings.server_name' => 'nullable|string',
            'reality_settings.server_port' => 'nullable|integer',
            'reality_settings.public_key' => 'nullable|string',
            'reality_settings.private_key' => 'nullable|string',
            'reality_settings.short_id' => 'nullable|string',
            'reality_settings.dest' => 'nullable|string',
            'reality_settings.mldsa65Seed' => 'nullable|string',
            'reality_settings.xver' => 'nullable|string',
            ...self::TLS_CERT_RULES,
        ],
    ];

    private function getBaseRules(): array
    {
        return [
            'type' => 'required|in:' . implode(',', Server::VALID_TYPES),
            'runtime' => 'nullable|in:' . implode(',', Server::VALID_RUNTIMES),
            'spectific_key' => 'nullable|string',
            'code' => 'nullable|string',
            'show' => '',
            'enabled' => 'nullable|boolean',
            'name' => 'required|string',
            'group_ids' => 'nullable|array',
            'route_ids' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'excludes' => 'nullable|array',
            'ips' => 'nullable|array',
            'rate' => 'required|numeric',
            'rate_time_enable' => 'nullable|boolean',
            'rate_time_ranges' => 'nullable|array',
            'rate_time_ranges.*.start' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.end' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.rate' => 'required_with:rate_time_ranges|numeric|min:0',
            'protocol_settings' => 'array',
        ];
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $rules = $this->getBaseRules();

        foreach (self::PROTOCOL_RULES[$type] ?? [] as $field => $rule) {
            $rules['protocol_settings.' . $field] = $rule;
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $runtime = strtolower(trim((string) $this->input('runtime', Server::RUNTIME_GENERIC)));
        if ($runtime === '') {
            $runtime = Server::RUNTIME_GENERIC;
        }

        $this->merge([
            'runtime' => $runtime,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('runtime') !== Server::RUNTIME_V2NODE) {
                return;
            }

            $type = Server::normalizeType($this->input('type'));
            if (!in_array($type, self::V2NODE_SUPPORTED_TYPES, true)) {
                $validator->errors()->add('runtime', '当前节点类型不支持 v2node 运行时');
                return;
            }

            $settings = (array) $this->input('protocol_settings', []);

            if ($type === Server::TYPE_HYSTERIA && (int) data_get($settings, 'version', 2) !== 2) {
                $validator->errors()->add('protocol_settings.version', 'v2node 仅支持 Hysteria2');
            }

            $network = strtolower(trim((string) data_get($settings, 'network', '')));
            $isReality = ($type === Server::TYPE_VLESS && (int) data_get($settings, 'tls', 0) === 2)
                || ($type === Server::TYPE_ANYTLS && (int) data_get($settings, 'tls_mode', 1) === 2);

            if ($isReality && in_array($network, self::V2NODE_REALITY_UNSUPPORTED_NETWORKS, true)) {
                $validator->errors()->add('protocol_settings.network', 'v2node 当前不支持 Reality 与该传输协议组合');
            }
        });
    }

    public function messages()
    {
        return [
            'name.required' => '节点名称不能为空',
            'runtime.in' => '运行时类型不正确',
            'group_ids.required' => '权限组不能为空',
            'group_ids.array' => '权限组格式不正确',
            'route_ids.array' => '路由组格式不正确',
            'parent_id.integer' => '父ID格式不正确',
            'host.required' => '节点地址不能为空',
            'port.required' => '连接端口不能为空',
            'server_port.required' => '后端服务端口不能为空',
            'tls.required' => 'TLS不能为空',
            'tags.array' => '标签格式不正确',
            'rate.required' => '倍率不能为空',
            'rate.numeric' => '倍率格式不正确',
            'network.required' => '传输协议不能为空',
            'network.in' => '传输协议格式不正确',
            'networkSettings.array' => '传输协议配置有误',
            'ruleSettings.array' => '规则配置有误',
            'tlsSettings.array' => 'tls配置有误',
            'dnsSettings.array' => 'dns配置有误'
        ];
    }
}

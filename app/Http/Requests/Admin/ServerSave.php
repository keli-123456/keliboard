<?php


namespace App\Http\Requests\Admin;

use App\Models\Server;
use App\Support\Hysteria2Settings;
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
        Server::TYPE_NAIVE,
        Server::TYPE_HTTP,
        Server::TYPE_MIERU,
        Server::TYPE_TRUSTTUNNEL,
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
            'version' => 'required|integer|in:1,2',
            'congestion_control' => 'nullable|string|in:cubic,bbr,new_reno,brutal',
            'ech' => 'nullable|array:enabled,key_file,config,mode,request_id,state',
            'ech.enabled' => 'required_with:protocol_settings.ech|boolean',
            'ech.mode' => 'sometimes|in:automatic,manual',
            'ech.request_id' => 'sometimes|string|regex:/^[a-f0-9]{32}$/D',
            'ech.state' => 'sometimes|in:pending,prepared,ready',
            'ech.key_file' => 'sometimes|string|max:4096',
            'ech.config' => 'sometimes|string|max:16384',
            'network_settings' => 'nullable|array:masquerade,gecko_min_packet_size,gecko_max_packet_size,brutal_disable_loss_compensation',
            'network_settings.brutal_disable_loss_compensation' => 'sometimes|boolean',
            'network_settings.masquerade' => 'sometimes|array',
            'network_settings.gecko_min_packet_size' => 'sometimes|integer|min:0|max:2048',
            'network_settings.gecko_max_packet_size' => 'sometimes|integer|min:0|max:2048',
            'alpn' => 'nullable|string',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'string|nullable|in:salamander,gecko',
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
            'tls' => 'nullable|integer|in:0,1',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
            'tls_settings.alpn' => 'nullable|array',
            ...self::TLS_CERT_RULES,
        ],
        'trusttunnel' => [
            'network' => 'required|in:tcp',
            'tls' => 'required|integer|in:1',
            'tls_settings.server_name' => 'required|string|max:253',
            'tls_settings.allow_insecure' => 'nullable|boolean|declined',
            'tls_settings.alpn' => 'required|array|min:1|max:2',
            'tls_settings.alpn.*' => 'required|distinct|in:h2,h3',
            'ech' => 'nullable|array',
            ...self::TLS_CERT_RULES,
            'tls_settings.cert_mode' => 'required|in:file,dns,http',
            'tls_settings.cert_file' => 'nullable|string|required_if:protocol_settings.tls_settings.cert_mode,file',
            'tls_settings.key_file' => 'nullable|string|required_if:protocol_settings.tls_settings.cert_mode,file',
        ],
        'naive' => [
            'network' => 'nullable|string|in:tcp,quic',
            'tls' => 'required|integer|in:0,1',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
            'tls_settings.alpn' => 'nullable|array',
            ...self::TLS_CERT_RULES,
        ],
        'http' => [
            'tls' => 'required|integer|in:0,1',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
            'tls_settings.alpn' => 'nullable|array',
            ...self::TLS_CERT_RULES,
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
            'tls.server_name' => 'nullable|string',
            'tls.allow_insecure' => 'nullable|boolean',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
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
        $rules['expected_config_hash'] = 'nullable|string|regex:/^[a-f0-9]{64}$/D';
        $rules['expected_draft_hash'] = 'nullable|string|regex:/^[a-f0-9]{64}$/D';

        foreach (self::PROTOCOL_RULES[$type] ?? [] as $field => $rule) {
            $rules['protocol_settings.' . $field] = $rule;
        }

        return $rules;
    }

    public static function validateSnapshot(array $payload): array
    {
        $request = static::create('/', 'POST', $payload);
        $request->prepareForValidation();
        $validator = app('validator')->make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);
        return $validator->validate();
    }

    protected function prepareForValidation(): void
    {
        // Empty response bodies are meaningful, even after ConvertEmptyStringsToNull.
        $settings = $this->input('protocol_settings');
        if ($this->input('type') === Server::TYPE_HYSTERIA && is_array($settings)
            && data_get($settings, 'network_settings.masquerade.type') === 'string'
            && \Illuminate\Support\Arr::has($settings, 'network_settings.masquerade.body')
            && data_get($settings, 'network_settings.masquerade.body') === null) {
            data_set($settings, 'network_settings.masquerade.body', '');
            $this->merge(['protocol_settings' => $settings]);
        }
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
            if ($this->input('type') === Server::TYPE_TRUSTTUNNEL) {
                if ($this->input('runtime') !== Server::RUNTIME_V2NODE) {
                    $validator->errors()->add('runtime', 'TrustTunnel 需要支持此协议的 kelinode-rs 版本');
                }
                $settings = (array) $this->input('protocol_settings', []);
                foreach (array_diff(array_keys($settings), ['network', 'tls', 'tls_settings', 'ech']) as $field) {
                    $validator->errors()->add('protocol_settings.' . $field, 'TrustTunnel 不支持此配置项');
                }
                $tlsSettings = (array) data_get($settings, 'tls_settings', []);
                \App\Support\EchSettings::validate($settings, $validator, 'tls_settings');
                foreach (array_diff(array_keys($tlsSettings), ['server_name', 'alpn', 'allow_insecure', 'cert_mode', 'cert_file', 'key_file', 'provider', 'dns_env', 'reject_unknown_sni']) as $field) {
                    $validator->errors()->add('protocol_settings.tls_settings.' . $field, 'TrustTunnel 不支持此 TLS 配置项');
                }
                if (filter_var($this->input('port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false
                    || filter_var($this->input('server_port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
                    $validator->errors()->add('port', 'TrustTunnel 仅支持单个有效端口');
                }
            }
            if ($this->input('type') === Server::TYPE_HYSTERIA) {
                Hysteria2Settings::validate((array) $this->input('protocol_settings', []), $validator);
            }
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

            if ($type === Server::TYPE_NAIVE && (int) data_get($settings, 'tls', 1) !== 1) {
                $validator->errors()->add('protocol_settings.tls', 'v2node 的 Naive 需要启用 TLS');
            }

            if ($type === Server::TYPE_MIERU) {
                $transport = strtolower(trim((string) data_get($settings, 'transport', 'tcp')));
                if ($transport !== 'tcp') {
                    $validator->errors()->add('protocol_settings.transport', 'v2node 当前仅支持 Mieru TCP');
                }
            }

            $network = strtolower(trim((string) data_get($settings, 'network', '')));
            if ($type === Server::TYPE_ANYTLS && $network !== '' && $network !== 'tcp') {
                $validator->errors()->add('protocol_settings.network', 'v2node 当前仅支持 AnyTLS TCP');
            }

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

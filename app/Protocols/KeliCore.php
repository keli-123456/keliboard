<?php

namespace App\Protocols;

use App\Support\KeliCoreCapabilities;
use Symfony\Component\Yaml\Yaml;

// Both authenticated app config and URL subscriptions use this native profile schema.
class KeliCore extends SingBox
{
    public $flags = ['kelicore', 'keli-native-client'];
    public $allowedProtocols = [];
    protected $protocolRequirements = [];

    public function handle()
    {
        $user = $this->user;
        return response()->json($this->generateConfig())
            ->header('profile-title', 'base64:' . base64_encode($this->subscriptionAppName()))
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}")
            ->header('profile-update-interval', '24');
    }

    public function generateConfig(?string $defaultOutboundTag = null, ?string $platform = null): array
    {
        $outbounds = [];
        $skipped = [];
        foreach ($this->servers as $server) {
            $support = KeliCoreCapabilities::supports((array) $server, $this->clientVersion);
            if (!$support->supported) {
                $skipped[] = [
                    'name' => (string) ($server['name'] ?? '未命名节点'),
                    'protocol' => (string) ($server['type'] ?? ''),
                    'reason' => $support->reason,
                ];
                continue;
            }
            $password = (string) data_get($server, 'password', data_get($this->user, 'uuid'));
            $outbound = match ($server['type']) {
                'shadowsocks' => $this->buildShadowsocks($password, $server),
                'vmess' => $this->buildVmess($password, $server),
                'vless' => $this->buildVless($password, $server),
                'trojan' => $this->buildTrojan($password, $server),
                'hysteria' => $this->buildHysteria($password, $server),
                'tuic' => $this->buildTuic($password, $server),
                'anytls' => $this->buildAnyTLS($password, $server),
                'naive' => $this->buildNaive($password, $server),
                'socks' => $this->buildSocks($password, $server),
                'http' => $this->buildHttp($password, $server),
                'mieru' => [
                    'type' => 'mieru', 'tag' => $server['name'], 'server' => $server['host'],
                    'server_port' => self::firstPortFromPortRange($server['ports'] ?? $server['port']) ?? (int) $server['port'],
                    'username' => $password, 'password' => $password,
                ],
            };
            if (in_array($server['type'], ['vless', 'vmess', 'trojan'], true)) {
                $outbound['transport'] = $this->nativeTransport($server, $outbound['transport'] ?? null);
            }
            if ($server['type'] === 'naive') {
                $outbound['tls']['insecure'] = (bool) data_get($server, 'protocol_settings.tls_settings.allow_insecure', false);
                if (data_get($server, 'protocol_settings.network') === 'quic') {
                    $outbound['transport'] = ['type' => 'quic'];
                }
            }
            $outbounds[] = $outbound;
        }
        $tags = array_column($outbounds, 'tag');
        return [
            'outbounds' => $outbounds,
            'rules' => $this->routingRules(),
            'keli' => [
                'schema_version' => 1,
                'core' => 'kelicore',
                'platform' => $platform,
                'default_outbound' => in_array($defaultOutboundTag, $tags, true) ? $defaultOutboundTag : ($tags[0] ?? null),
                'skipped' => $skipped,
            ],
        ];
    }

    protected function routingRules(): array
    {
        $root = dirname(__DIR__, 2);
        foreach ([ClashMeta::CUSTOM_TEMPLATE_FILE, ClashMeta::CUSTOM_CLASH_TEMPLATE_FILE, ClashMeta::DEFAULT_TEMPLATE_FILE] as $template) {
            if (!is_file($root . '/' . $template)) {
                continue;
            }
            $config = Yaml::parseFile($root . '/' . $template);
            $name = $this->subscriptionAppName();
            return array_values(array_map(
                fn (string $rule) => str_replace('$app_name', $name, $rule),
                array_filter((array) ($config['rules'] ?? []), 'is_string')
            ));
        }
        return [];
    }

    private function nativeTransport(array $server, ?array $existing): ?array
    {
        $settings = (array) ($server['protocol_settings'] ?? []);
        $network = strtolower((string) ($settings['network'] ?? 'tcp')) ?: 'tcp';
        $host = data_get($settings, 'network_settings.host') ?? data_get($settings, 'network_settings.headers.Host');
        $host = is_array($host) ? reset($host) : $host;
        return match ($network) {
            'tcp' => null,
            'ws', 'grpc' => $existing,
            'httpupgrade', 'http', 'h2' => array_filter([
                'type' => $network === 'h2' ? 'http' : $network,
                'host' => $host,
                'path' => data_get($settings, 'network_settings.path', '/'),
            ], fn ($value) => $value !== null && $value !== ''),
            'quic' => ['type' => 'quic'],
        };
    }
}

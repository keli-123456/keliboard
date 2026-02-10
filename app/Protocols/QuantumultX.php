<?php

namespace App\Protocols;

use App\Support\AbstractProtocol;
use App\Models\Server;

class QuantumultX extends AbstractProtocol
{
    public $flags = ['quantumult%20x', 'quantumult-x'];
    public $allowedProtocols = [
        Server::TYPE_SHADOWSOCKS,
        Server::TYPE_VMESS,
        Server::TYPE_VLESS,
        Server::TYPE_TROJAN,
    ];

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $uri = '';

        foreach ($servers as $item) {
            $type = $item['type'] ?? null;
            if (!$type) {
                continue;
            }

            if (in_array($type, [Server::TYPE_VMESS, Server::TYPE_VLESS, Server::TYPE_TROJAN], true)) {
                $network = (string) data_get($item, 'protocol_settings.network', 'tcp');
                if (!in_array($network, ['tcp', 'ws'], true)) {
                    continue;
                }
            }

            switch ($type) {
                case Server::TYPE_SHADOWSOCKS:
                    $uri .= self::buildShadowsocks($item['password'], $item);
                    break;
                case Server::TYPE_VMESS:
                    $uri .= self::buildVmess($item['password'], $item);
                    break;
                case Server::TYPE_VLESS:
                    $uri .= self::buildVless($item['password'], $item);
                    break;
                case Server::TYPE_TROJAN:
                    $uri .= self::buildTrojan($item['password'], $item);
                    break;
            }
        }

        return response(base64_encode($uri))
            ->header('content-type', 'text/plain')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
    }

    public static function buildShadowsocks($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $password = data_get($server, 'password', $password);
        $config = [
            "shadowsocks={$server['host']}:{$server['port']}",
            "method={$protocol_settings['cipher']}",
            "password={$password}",
            'fast-open=true',
            'udp-relay=true',
            "tag={$server['name']}"
        ];
        if (data_get($protocol_settings, 'plugin') && data_get($protocol_settings, 'plugin_opts')) {
            $plugin = data_get($protocol_settings, 'plugin');
            $pluginOpts = data_get($protocol_settings, 'plugin_opts', '');
            // 解析插件选项
            $parsedOpts = collect(explode(';', $pluginOpts))
                ->filter()
                ->mapWithKeys(function ($pair) {
                    if (!str_contains($pair, '=')) {
                        return [];
                    }
                    [$key, $value] = explode('=', $pair, 2);
                    return [trim($key) => trim($value)];
                })
                ->all();
            switch ($plugin) {
                case 'obfs':
                    $config[] = "obfs={$parsedOpts['obfs']}";
                    if (isset($parsedOpts['obfs-host'])) {
                        $config[] = "obfs-host={$parsedOpts['obfs-host']}";
                    }
                    if (isset($parsedOpts['path'])) {
                        $config[] = "obfs-uri={$parsedOpts['path']}";
                    }
                    break;
            }
        }
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildVmess($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $config = [
            "vmess={$server['host']}:{$server['port']}",
            'method=chacha20-poly1305',
            "password={$uuid}",
            'fast-open=true',
            'udp-relay=true',
            "tag={$server['name']}"
        ];

        if (data_get($protocol_settings, 'tls')) {
            if (data_get($protocol_settings, 'network') === 'tcp')
                array_push($config, 'obfs=over-tls');
            if (data_get($protocol_settings, 'tls_settings')) {
                if (data_get($protocol_settings, 'tls_settings.allow_insecure'))
                    array_push($config, 'tls-verification=' . ($protocol_settings['tls_settings']['allow_insecure'] ? 'false' : 'true'));
                if (data_get($protocol_settings, 'tls_settings.server_name'))
                    $host = data_get($protocol_settings, 'tls_settings.server_name');
            }
        }
        if (data_get($protocol_settings, 'network') === 'ws') {
            if (data_get($protocol_settings, 'tls'))
                array_push($config, 'obfs=wss');
            else
                array_push($config, 'obfs=ws');
            if (data_get($protocol_settings, 'network_settings')) {
                if (data_get($protocol_settings, 'network_settings.path'))
                    array_push($config, "obfs-uri={$protocol_settings['network_settings']['path']}");
                if (data_get($protocol_settings, 'network_settings.headers.Host') && !isset($host))
                    $host = data_get($protocol_settings, 'network_settings.headers.Host');
            }
        }
        if (isset($host)) {
            array_push($config, "obfs-host={$host}");
        }

        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildVless($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'] ?? [];
        $tls = (int) data_get($protocol_settings, 'tls', 0);
        $network = (string) data_get($protocol_settings, 'network', 'tcp');

        if (!in_array($network, ['tcp', 'ws'], true)) {
            return '';
        }

        if (!empty(data_get($protocol_settings, 'encryption')) && !empty(data_get($protocol_settings, 'encryption_settings'))) {
            // QX does not support VLESS encryption
            return '';
        }

        $config = [
            "vless={$server['host']}:{$server['port']}",
            'method=none',
            "password={$uuid}",
            $tls === 2 ? 'fast-open=false' : 'fast-open=true',
            'udp-relay=true',
            "tag={$server['name']}"
        ];

        if ($tls > 0) {
            $config[] = 'tls13=true';

            if ($tls === 2) {
                if ((bool) data_get($protocol_settings, 'reality_settings.allow_insecure', false)) {
                    $config[] = 'tls-verification=false';
                }
                if ($flow = data_get($protocol_settings, 'flow')) {
                    $flow = trim((string) $flow);
                    if ($flow !== '' && strtolower($flow) !== 'none') {
                        $config[] = "vless-flow={$flow}";
                    }
                }
                if ($publicKey = data_get($protocol_settings, 'reality_settings.public_key')) {
                    $config[] = "reality-base64-pubkey={$publicKey}";
                }
                if ($shortId = data_get($protocol_settings, 'reality_settings.short_id')) {
                    $config[] = "reality-hex-shortid={$shortId}";
                }
            } else {
                if ((bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false)) {
                    $config[] = 'tls-verification=false';
                }
                if ($flow = data_get($protocol_settings, 'flow')) {
                    $flow = trim((string) $flow);
                    if ($flow !== '' && strtolower($flow) !== 'none') {
                        $config[] = "vless-flow={$flow}";
                    }
                }
            }
        }

        if ($network === 'ws') {
            $config[] = $tls > 0 ? 'obfs=wss' : 'obfs=ws';
        } else {
            if ($tls > 0) {
                $config[] = 'obfs=over-tls';
            } else {
                $header = data_get($protocol_settings, 'network_settings.header', []);
                if (is_array($header) && ($header['type'] ?? '') === 'http') {
                    $config[] = 'obfs=http';
                }
            }
        }

        $host = null;
        $path = null;

        if ($network === 'tcp') {
            $headerType = data_get($protocol_settings, 'network_settings.header.type');
            if ($headerType === 'http') {
                $host = data_get($protocol_settings, 'network_settings.header.request.headers.Host.0');
                $path = data_get($protocol_settings, 'network_settings.header.request.path.0');
            }
        } elseif ($network === 'ws') {
            $host = data_get($protocol_settings, 'network_settings.headers.Host');
            $path = data_get($protocol_settings, 'network_settings.path');
        }

        if (!$host) {
            $host = $tls === 2
                ? data_get($protocol_settings, 'reality_settings.server_name')
                : data_get($protocol_settings, 'tls_settings.server_name');
        }

        if ($host) {
            $config[] = "obfs-host={$host}";
        }
        if ($path) {
            $config[] = "obfs-uri={$path}";
        }

        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildTrojan($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $config = [
            "trojan={$server['host']}:{$server['port']}",
            "password={$password}",
            'fast-open=true',
            'udp-relay=true',
            "tag={$server['name']}"
        ];

        if (data_get($protocol_settings, 'network') === 'ws') {
            // When using websocket over tls you should not set over-tls and tls-host options anymore
            $config[] = 'obfs=wss';
            if ($host = data_get($protocol_settings, 'network_settings.headers.Host') ?: data_get($protocol_settings, 'server_name')) {
                $config[] = "obfs-host={$host}";
            }
            if ($path = data_get($protocol_settings, 'network_settings.path')) {
                $config[] = "obfs-uri={$path}";
            }
            if ((bool) data_get($protocol_settings, 'allow_insecure', false)) {
                $config[] = 'tls-verification=false';
            }
        } else {
            $config[] = 'over-tls=true';
            if ($serverName = data_get($protocol_settings, 'server_name')) {
                $config[] = "tls-host={$serverName}";
            }
            // Tips: allowInsecure=false = tls-verification=true
            $config[] = (bool) data_get($protocol_settings, 'allow_insecure', false)
                ? 'tls-verification=false'
                : 'tls-verification=true';
        }

        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }
}

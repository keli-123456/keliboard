<?php

// Synthetic cross-repository contract: no application bootstrap, .env or database.
require_once dirname(__DIR__) . '/vendor/autoload.php';

function keliCoreAcceptanceEchConfig(): string
{
    $name = 'public.example.test';
    $content = pack('Cnn', 41, 32, 32) . str_repeat("\x07", 32)
        . pack('nnnC', 4, 1, 1, 0) . chr(strlen($name)) . $name . pack('n', 0);
    return base64_encode(pack('nnn', strlen($content) + 4, 0xfe0d, strlen($content)) . $content);
}

function keliCoreAcceptanceProfile(bool $extended = false): array
{
    $uuid = '123e4567-e89b-12d3-a456-426614174000';
    $node = fn (string $type, array $settings = []) => [
        'name' => "fixture-$type", 'type' => $type, 'host' => 'node.example.test', 'port' => 443,
        'password' => $uuid, 'protocol_settings' => $settings,
    ];
    $nodes = [
        $node('hysteria', ['version' => 2, 'tls' => ['server_name' => 'node.example.test'],
            'ech' => ['enabled' => true, 'config' => keliCoreAcceptanceEchConfig()],
            'obfs' => ['open' => true, 'type' => 'gecko', 'password' => 'fixture-obfs-password'],
            'hop_interval' => 15, 'network_settings' => ['gecko_min_packet_size' => 64, 'gecko_max_packet_size' => 1200]]) + ['ports' => '20000-20002'],
        $node('mieru', ['transport' => 'TCP']) + ['ports' => '12000-12002'],
        $node('naive', ['network' => 'quic', 'tls_settings' => ['server_name' => 'node.example.test']]),
        $node('anytls', ['tls_mode' => 1, 'tls' => ['server_name' => 'node.example.test']]),
        $node('shadowsocks', ['cipher' => 'aes-256-gcm']),
        $node('vmess', ['network' => 'tcp', 'tls' => 1, 'tls_settings' => ['server_name' => 'node.example.test']]),
        $node('vless', ['network' => 'tcp', 'tls' => 1, 'tls_settings' => ['server_name' => 'node.example.test']]),
        $node('trojan', ['network' => 'tcp', 'server_name' => 'node.example.test']),
        $node('tuic', ['version' => 5, 'tls' => ['server_name' => 'node.example.test']]),
        $node('socks'),
        $node('http'),
        $node('trusttunnel'),
        array_replace($node('shadowsocks', ['cipher' => '2022-blake3-aes-128-gcm']), ['name' => 'fixture-ss2022']),
        array_replace($node('tuic', ['version' => 5, 'zero_rtt_handshake' => true]), ['name' => 'fixture-tuic-0rtt']),
    ];
    if ($extended) {
        foreach (['2022-blake3-aes-128-gcm' => 16, '2022-blake3-aes-256-gcm' => 32, '2022-blake3-chacha20-poly1305' => 32] as $cipher => $size) {
            $nodes[] = array_replace($node('shadowsocks', ['cipher' => $cipher]),
                ['name' => "fixture-$cipher", 'password' => base64_encode(str_repeat("\x07", $size))]);
        }
        foreach (['h2', 'h3'] as $alpn) {
            $nodes[] = array_replace($node('trusttunnel', ['tls_settings' => ['server_name' => 'node.example.test', 'alpn' => [$alpn]]]),
                ['name' => "fixture-trusttunnel-$alpn"]);
        }
    }
    $exporter = new class($uuid, $nodes, $extended) extends \App\Protocols\KeliCore {
        public function __construct(string $uuid, array $nodes, bool $extended)
        {
            $this->user = ['uuid' => $uuid];
            $this->servers = $nodes;
            $this->clientName = 'kelicore';
            $this->clientVersion = $extended ? \App\Support\KeliCoreCapabilities::EXTENDED_VERSION : \App\Support\KeliCoreCapabilities::MIN_VERSION;
        }

        protected function routingRules(): array
        {
            return ['DOMAIN,direct.example.test,DIRECT', 'DOMAIN,blocked.example.test,REJECT', 'MATCH,Native'];
        }
    };
    return $exporter->generateConfig(platform: 'android');
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', ['output:', 'extended']);
    $json = json_encode(keliCoreAcceptanceProfile(isset($options['extended'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (isset($options['output'])) {
        if (file_put_contents($options['output'], $json) === false) {
            throw new RuntimeException('Unable to write synthetic kelicore fixture');
        }
    } else {
        echo $json;
    }
}

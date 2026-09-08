<?php

// CLI-only synthetic fixtures. Do not bootstrap Laravel, load .env or query users.
require_once dirname(__DIR__) . '/vendor/autoload.php';

function protocolAcceptanceFixtures(): array
{
    $uuid = '123e4567-e89b-12d3-a456-426614174000';
    $naive = [
        'name' => 'Naive acceptance',
        'host' => '127.0.0.1',
        'port' => 10443,
        'protocol_settings' => [
            'network' => 'tcp',
            'tls' => 1,
            'tls_settings' => ['server_name' => 'localhost', 'allow_insecure' => false],
        ],
    ];
    $exporter = new class extends \App\Protocols\SingBox {
        public function __construct()
        {
        }

        public function exportNaive(string $uuid, array $server): array
        {
            return $this->buildNaive($uuid, $server);
        }
    };
    $naiveH3 = $naive;
    $naiveH3['protocol_settings']['network'] = 'quic';
    $result = [
        'schema_version' => 1,
        'synthetic' => true,
        'user_uuid' => $uuid,
        'naive_sing_box' => $exporter->exportNaive($uuid, $naive),
        'naive_h3_sing_box' => $exporter->exportNaive($uuid, $naiveH3),
        'naive_shadowrocket' => \App\Protocols\Shadowrocket::buildNaive($uuid, $naive),
        'mieru_mihomo' => [],
        'mieru_share' => [],
        'source_sha256' => [],
    ];
    foreach (['MULTIPLEXING_LOW', 'MULTIPLEXING_MIDDLE', 'MULTIPLEXING_HIGH'] as $level) {
        $server = [
            'name' => 'Mieru acceptance',
            'host' => '127.0.0.1',
            'port' => 10444,
            'protocol_settings' => ['transport' => 'TCP', 'multiplexing' => $level],
        ];
        $result['mieru_mihomo'][$level] = \App\Protocols\ClashMeta::buildMieru($uuid, $server);
        $result['mieru_share'][$level] = \App\Protocols\Shadowrocket::buildMieru($uuid, $server);
    }
    foreach (['SingBox', 'ClashMeta', 'Shadowrocket'] as $name) {
        $file = 'app/Protocols/' . $name . '.php';
        $result['source_sha256'][$file] = hash_file('sha256', dirname(__DIR__) . '/' . $file);
    }

    return $result;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $json = json_encode(protocolAcceptanceFixtures(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $options = getopt('', ['output:']);
    if (isset($options['output'])) {
        if (file_put_contents($options['output'], $json) === false) {
            fwrite(STDERR, "Unable to write synthetic protocol fixtures.\n");
            exit(1);
        }
    } else {
        echo $json;
    }
}

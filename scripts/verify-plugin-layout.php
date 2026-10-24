<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

try {
    $root = dirname(__DIR__);
    $loader = require $root . '/vendor/autoload.php';
    $manifest = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['autoload']['psr-4']['Plugin\\'] ?? null) !== ['plugins-core/', 'plugins/']) {
        throw new RuntimeException('Bundled plugin autoload priority differs from the release contract.');
    }
    $codes = ['alipay_f2f', 'bepusdt', 'btcpay', 'coin_payments', 'coinbase', 'epay', 'mgate',
        'node_auto_rename', 'paytaro', 'remote_check_proxy', 'subscription_control', 'telegram'];
    foreach ($codes as $code) {
        $name = \Illuminate\Support\Str::studly($code);
        $path = $root . '/plugins-core/' . $name;
        $metadata = json_decode(file_get_contents($path . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
        $class = 'Plugin\\' . $name . '\\Plugin';
        if (($metadata['code'] ?? null) !== $code
            || realpath($loader->findFile($class) ?: '') !== realpath($path . '/Plugin.php')
            || !class_exists($class)
            || realpath((new ReflectionClass($class))->getFileName()) !== realpath($path . '/Plugin.php')) {
            throw new RuntimeException('Bundled plugin source mismatch: ' . $code);
        }
    }
    echo json_encode(['status' => 'pass', 'bundled_plugins' => count($codes), 'source' => 'plugins-core',
        'uploaded_plugins_preserved' => is_dir($root . '/plugins')], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['status' => 'fail', 'error' => $error instanceof RuntimeException
        ? $error->getMessage() : get_class($error)], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}

<?php

declare(strict_types=1);

// Included only after the HTTP fixture has validated its marked temporary root.
if (!isset($root, $temp, $action) || PHP_OS_FAMILY !== 'Linux'
    || !function_exists('posix_geteuid') || posix_geteuid() === 0
    || !is_string(getenv('KELI_ACCEPTANCE_HOST_NETNS'))
    || !preg_match('/^net:\[\d+\]$/D', getenv('KELI_ACCEPTANCE_HOST_NETNS'))
    || readlink('/proc/self/ns/net') === getenv('KELI_ACCEPTANCE_HOST_NETNS')) {
    throw new RuntimeException('PostgreSQL acceptance requires non-root private network isolation.');
}
$configPath = realpath((string) getenv('KELI_TRAFFIC_POSTGRES_CONFIG'));
if ($configPath === false || dirname($configPath) !== dirname($temp)
    || basename($configPath) !== 'postgres-fixture.json' || is_link($configPath)
    || fileowner($configPath) !== posix_geteuid()) {
    throw new RuntimeException('Expected local owned PostgreSQL fixture manifest.');
}
$pg = json_decode(file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
$data = dirname($temp) . '/pgdata';
if (($pg['host'] ?? null) !== '127.0.0.1' || ($pg['username'] ?? null) !== 'keli_acceptance'
    || !is_int($pg['port'] ?? null) || $pg['port'] < 1024 || $pg['port'] > 65535
    || !preg_match('/^[a-f0-9]{32}$/D', $pg['acceptance_id'] ?? '')
    || realpath($data) !== $data || is_link($data)
    || ($pg['data_directory'] ?? null) !== $data
    || !in_array($pg['isolation_level'] ?? null, ['READ COMMITTED', 'SERIALIZABLE'], true)) {
    throw new RuntimeException('Invalid isolated PostgreSQL manifest.');
}
$admin = new PDO("pgsql:host=127.0.0.1;port={$pg['port']};dbname=postgres;sslmode=disable;connect_timeout=3",
    'keli_acceptance', null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity = $admin->query("SELECT current_setting('keli.acceptance_id', true) AS nonce,
    current_setting('data_directory') AS directory,
    current_setting('server_version_num') AS version")->fetch(PDO::FETCH_ASSOC);
if ($identity['nonce'] !== $pg['acceptance_id'] || $identity['directory'] !== $data
    || (int) $identity['version'] !== 150019) {
    throw new RuntimeException('PostgreSQL server does not match the private acceptance cluster.');
}
$name = 'keli_traffic_' . substr(basename($root), strlen('keli-traffic-http-'));
$marker = $root . '/postgres-database.json';
if ($action === 'init') {
    $handle = fopen($marker, 'x');
    if ($handle === false) { throw new RuntimeException('Fixture must be new.'); }
    fclose($handle);
    // Identifier consists solely of the fixed prefix and validated random hex nonce.
    $admin->exec('CREATE DATABASE "' . $name . '" TEMPLATE template0 ENCODING \'UTF8\'');
    file_put_contents($marker, json_encode(['database' => $name, 'acceptance_id' => $pg['acceptance_id']], JSON_THROW_ON_ERROR));
} elseif (!is_file($marker) || is_link($marker)
    || json_decode(file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR)
        !== ['database' => $name, 'acceptance_id' => $pg['acceptance_id']]) {
    throw new RuntimeException('PostgreSQL fixture marker does not match.');
}
$admin = null;
return [
    'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $pg['port'],
    'database' => $name, 'username' => 'keli_acceptance', 'password' => '',
    'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable',
    'application_name' => 'keli_isolated_traffic_acceptance',
    'isolation_level' => $pg['isolation_level'], 'synchronous_commit' => 'on',
];

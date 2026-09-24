<?php

declare(strict_types=1);

// Included only after the HTTP fixture validates its marked temporary root.
if (!isset($root, $temp, $action) || PHP_OS_FAMILY !== 'Linux'
    || !function_exists('posix_geteuid') || posix_geteuid() === 0
    || !is_string(getenv('KELI_ACCEPTANCE_HOST_NETNS'))
    || !preg_match('/^net:\[\d+\]$/D', getenv('KELI_ACCEPTANCE_HOST_NETNS'))
    || readlink('/proc/self/ns/net') === getenv('KELI_ACCEPTANCE_HOST_NETNS')) {
    throw new RuntimeException('MySQL acceptance requires non-root private network isolation.');
}
$configPath = realpath((string) getenv('KELI_TRAFFIC_MYSQL_CONFIG'));
if ($configPath === false || dirname($configPath) !== dirname($temp)
    || basename($configPath) !== 'mysql-fixture.json' || is_link($configPath)
    || fileowner($configPath) !== posix_geteuid()) {
    throw new RuntimeException('Expected local owned MySQL fixture manifest.');
}
$mysqlConfig = json_decode(file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
$data = dirname($temp) . '/mysqldata';
if (($mysqlConfig['host'] ?? null) !== '127.0.0.1' || ($mysqlConfig['username'] ?? null) !== 'keli_acceptance'
    || !is_int($mysqlConfig['port'] ?? null) || $mysqlConfig['port'] < 1024 || $mysqlConfig['port'] > 65535
    || !preg_match('/^[a-f0-9]{32}$/D', $mysqlConfig['acceptance_id'] ?? '')
    || !preg_match('/^[a-f0-9-]{36}$/D', $mysqlConfig['server_uuid'] ?? '')
    || realpath($data) !== $data || is_link($data) || fileowner($data) !== posix_geteuid()
    || ($mysqlConfig['data_directory'] ?? null) !== $data
    || !in_array($mysqlConfig['isolation_level'] ?? null, ['REPEATABLE READ', 'READ COMMITTED', 'SERIALIZABLE'], true)) {
    throw new RuntimeException('Invalid isolated MySQL manifest.');
}
$admin = new PDO("mysql:host=127.0.0.1;port={$mysqlConfig['port']};dbname=mysql;charset=utf8mb4",
    'keli_acceptance', $mysqlConfig['acceptance_id'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
$identity = $admin->query('SELECT @@datadir AS directory, @@server_uuid AS uuid, @@version AS version,
    @@version_comment AS vendor, @@innodb_flush_log_at_trx_commit AS flush_mode, @@sync_binlog AS sync_binlog')->fetch(PDO::FETCH_ASSOC);
if (rtrim($identity['directory'], '/') !== $data || $identity['uuid'] !== $mysqlConfig['server_uuid']
    || $identity['version'] !== '8.4.11' || !str_contains($identity['vendor'], 'MySQL Community Server')
    || (int) $identity['flush_mode'] !== 1 || (int) $identity['sync_binlog'] !== 1) {
    throw new RuntimeException('MySQL server does not match the private durable acceptance instance.');
}
$name = 'keli_traffic_' . substr(basename($root), strlen('keli-traffic-http-'));
$marker = $root . '/mysql-database.json';
$expected = ['database' => $name, 'acceptance_id' => $mysqlConfig['acceptance_id'], 'server_uuid' => $mysqlConfig['server_uuid']];
if ($action === 'init') {
    $handle = fopen($marker, 'x');
    if ($handle === false) { throw new RuntimeException('Fixture must be new.'); }
    fclose($handle);
    $admin->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    file_put_contents($marker, json_encode($expected, JSON_THROW_ON_ERROR));
} elseif (!is_file($marker) || is_link($marker)
    || json_decode(file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR) !== $expected) {
    throw new RuntimeException('MySQL fixture marker does not match.');
}
$admin = null;
return [
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $mysqlConfig['port'],
    'database' => $name, 'username' => 'keli_acceptance', 'password' => $mysqlConfig['acceptance_id'],
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin', 'prefix' => '', 'strict' => true,
    'engine' => 'InnoDB', 'isolation_level' => $mysqlConfig['isolation_level'],
    'options' => [PDO::ATTR_TIMEOUT => 3],
];

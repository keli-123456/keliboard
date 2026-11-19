<?php

declare(strict_types=1);

// Test-only supervisor payload. Never installed into the release tree.
if (PHP_SAPI !== 'cli' || getenv('FAILOVER_EXAMPLE_STATE_DIR') !== '/state' || !is_dir('/state')) exit(2);
if (($argv[1] ?? '') === 'stubborn') {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
    file_put_contents('/state/stubborn-ready', "ready\n");
    sleep(90);
    exit(3);
}
if (($argv[1] ?? '') !== 'isolation') exit(2);

function blockedIp(int $family): bool
{
    $opened = @socket_create($family, SOCK_STREAM, SOL_TCP);
    if ($opened !== false) { socket_close($opened); return false; }
    return in_array(socket_last_error(), [SOCKET_EAFNOSUPPORT, SOCKET_EPERM], true);
}

$stray = ['/run/docker.sock', '/var/run/docker.sock', '/run/redis/redis.sock', '/run/mysqld/mysqld.sock',
    '/run/dbus/system_bus_socket', '/run/keli-failover-example/worker.sock', '/app/.env', '/state/production.fixture'];
$rootTrap = @fopen('/app/.fixture-write', 'xb');
if (is_resource($rootTrap)) { fclose($rootTrap); unlink('/app/.fixture-write'); }
$sentinel = json_decode(file_get_contents('/state/fixture-paths.json'), true, flags: JSON_THROW_ON_ERROR);
$unix = @socket_create(AF_UNIX, SOCK_STREAM, 0);
$checks = ['dedicated_user' => posix_geteuid() === $sentinel['uid'] && posix_geteuid() > 0,
    'unix_socket_available' => $unix !== false,
    'ipv4_socket_denied' => blockedIp(AF_INET), 'ipv6_socket_denied' => blockedIp(AF_INET6),
    'private_network_namespace' => readlink('/proc/self/ns/net') !== $sentinel['host_netns'],
    'production_paths_absent' => !array_filter($stray, fn ($path) => file_exists($path) || is_link($path)),
    'host_fixture_paths_absent' => !array_filter($sentinel['paths'], fn ($path) => file_exists($path) || is_link($path)),
    'only_expected_environment' => !array_diff(array_keys(getenv()), ['PATH','LANG','FAILOVER_EXAMPLE_STATE_DIR',
        'FAILOVER_EXAMPLE_GRANT_ID','FAILOVER_EXAMPLE_ADMIN_ID','FAILOVER_EXAMPLE_OPENS_AT','FAILOVER_EXAMPLE_EXPIRES_AT']),
    'code_write_rejected' => $rootTrap === false,
    'private_state_writable' => file_put_contents('/state/probe-created', 'fixture') === 7,
];
if ($unix !== false) socket_close($unix);
file_put_contents('/state/sandbox-check.json', json_encode($checks, JSON_THROW_ON_ERROR));
exit(in_array(false, $checks, true) ? 1 : 0);

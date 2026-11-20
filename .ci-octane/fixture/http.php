<?php

declare(strict_types=1);

$allowed = ['/_ci_octane_identity', '/_ci_octane_identity?local_test=1', '/_ci_octane_identity?write_fixture_log=1',
    '/api/v2/fixture-admin/server/machine/cloud/failover/example', '/assets/admin-xboard/index.html'];
$path = $argv[1] ?? '';
if (!in_array($path, $allowed, true) && !preg_match('#^/assets/admin-xboard/assets/index\.(js|css)\?v=[0-9a-f]{12}$#D', $path)) exit(3);
$ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "Connection: close\r\nAccept: application/json\r\nAccept-Encoding: identity\r\n",
    'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
$raw = @file_get_contents('http://127.0.0.1:7001' . $path, false, $ctx, 0, 6 * 1024 * 1024);
if ($raw === false || !preg_match('#^HTTP/\S+ (\d{3})\b#', $http_response_header[0] ?? '', $m)) exit(4);
echo json_encode(['code' => (int) $m[1], 'sha256' => hash('sha256', $raw), 'size' => strlen($raw),
    'json' => str_starts_with($path, '/_ci_octane_identity') ? json_decode($raw, true) : null], JSON_THROW_ON_ERROR);

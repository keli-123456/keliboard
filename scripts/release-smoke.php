<?php

declare(strict_types=1);

/**
 * Read-only HTTP and static asset checks used by canary and post-deploy gates.
 *
 * Usage:
 *   php scripts/release-smoke.php --base-url=http://127.0.0.1:7001 --host=panel.example.com
 */

function keliSmokeFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

/** @return array{ok: bool, message: string} */
function keliValidateSmokeResponse(string $path, int $status, string $body): array
{
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'message' => "{$path} returned HTTP {$status}"];
    }

    if ($path === '/') {
        return trim($body) !== ''
            ? ['ok' => true, 'message' => 'landing page is available']
            : ['ok' => false, 'message' => 'landing page returned an empty body'];
    }

    try {
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return ['ok' => false, 'message' => "{$path} returned invalid JSON: {$e->getMessage()}"];
    }

    if (!is_array($payload)) {
        return ['ok' => false, 'message' => "{$path} did not return a JSON object"];
    }

    $data = $payload['data'] ?? null;
    if (!is_array($data)) {
        return ['ok' => false, 'message' => "{$path} response is missing data"];
    }

    if ($path === '/api/v1/guest/comm/config') {
        $appName = trim((string) ($data['app_name'] ?? ''));
        if ($appName === '') {
            return ['ok' => false, 'message' => 'guest config is missing app_name'];
        }
    }

    return ['ok' => true, 'message' => "{$path} contract is valid"];
}

/** @return array<int, array{name: string, ok: bool, message: string}> */
function keliVerifyAdminAssets(string $root): array
{
    $assetRoot = rtrim($root, "\\/") . '/public/assets/admin-xboard';
    $required = [
        'index.html',
        'assets/index.js',
        'assets/index.css',
        'build-manifest.json',
    ];
    $checks = [];

    foreach ($required as $relativePath) {
        $path = $assetRoot . '/' . $relativePath;
        $checks[] = [
            'name' => 'admin_asset_' . str_replace(['/', '.'], '_', $relativePath),
            'ok' => is_file($path) && filesize($path) > 0,
            'message' => is_file($path) ? "{$relativePath} is present" : "{$relativePath} is missing",
        ];
    }

    $manifestPath = $assetRoot . '/build-manifest.json';
    if (!is_file($manifestPath)) {
        return $checks;
    }

    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $checks[] = ['name' => 'admin_asset_manifest_json', 'ok' => false, 'message' => $e->getMessage()];
        return $checks;
    }

    $component = (string) ($manifest['component'] ?? '');
    $sourceGitSha = strtolower((string) ($manifest['source_git_sha'] ?? ''));
    $checks[] = [
        'name' => 'admin_asset_manifest_component',
        'ok' => $component === 'keli-admin',
        'message' => $component === 'keli-admin'
            ? 'build manifest component matches'
            : 'build manifest component must be keli-admin',
    ];
    $checks[] = [
        'name' => 'admin_asset_manifest_source',
        'ok' => preg_match('/^[a-f0-9]{40}$/', $sourceGitSha) === 1,
        'message' => preg_match('/^[a-f0-9]{40}$/', $sourceGitSha) === 1
            ? 'build manifest source Git SHA is valid'
            : 'build manifest source Git SHA is invalid',
    ];

    $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
    foreach (['index.html', 'assets/index.js', 'assets/index.css'] as $relativePath) {
        $evidence = $files[$relativePath] ?? null;
        $expected = strtolower((string) ($evidence['sha256'] ?? ''));
        $expectedBytes = (int) ($evidence['bytes'] ?? -1);
        $path = $assetRoot . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        $actual = is_file($path) ? strtolower((string) hash_file('sha256', $path)) : '';
        $actualBytes = is_file($path) ? (int) filesize($path) : -1;
        $hashMatches = preg_match('/^[a-f0-9]{64}$/', $expected) === 1
            && hash_equals($expected, $actual);
        $sizeMatches = $expectedBytes >= 0 && $expectedBytes === $actualBytes;
        $checks[] = [
            'name' => 'admin_asset_hash_' . str_replace(['/', '.'], '_', $relativePath),
            'ok' => is_array($evidence) && $hashMatches && $sizeMatches,
            'message' => "{$relativePath} evidence "
                . (is_array($evidence) && $hashMatches && $sizeMatches ? 'matches' : 'does not match'),
        ];
    }

    return $checks;
}

/** @return array{status: int, body: string, error: string} */
function keliSmokeRequest(string $url, string $host, int $timeout): array
{
    $headers = ['Accept: application/json', 'User-Agent: Keli-Release-Gate/1.0'];
    if ($host !== '') {
        $headers[] = 'Host: ' . $host;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})(?:\s|$)/', $responseHeaders[0], $matches)) {
        $status = (int) $matches[1];
    }

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $body === false ? (error_get_last()['message'] ?? 'request failed') : '',
    ];
}

if (defined('KELI_RELEASE_SMOKE_LIBRARY')) {
    return;
}

$options = getopt('', [
    'base-url:',
    'host::',
    'root::',
    'attempts::',
    'interval-ms::',
    'timeout::',
    'output::',
]);

$baseUrl = rtrim((string) ($options['base-url'] ?? ''), '/');
if ($baseUrl === '') {
    keliSmokeFail('--base-url is required');
}
$host = trim((string) ($options['host'] ?? ''));
$root = (string) ($options['root'] ?? dirname(__DIR__));
$attempts = max(1, (int) ($options['attempts'] ?? 12));
$intervalMs = max(0, (int) ($options['interval-ms'] ?? 1000));
$timeout = max(1, (int) ($options['timeout'] ?? 5));
$outputPath = trim((string) ($options['output'] ?? ''));
$paths = ['/', '/api/v1/guest/comm/config', '/api/v1/guest/plan/fetch'];
$checks = [];
$passed = false;

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    $checks = [];
    foreach ($paths as $path) {
        $response = keliSmokeRequest($baseUrl . $path, $host, $timeout);
        $result = keliValidateSmokeResponse($path, $response['status'], $response['body']);
        $checks[] = [
            'name' => 'http_' . ($path === '/' ? 'landing' : trim(str_replace('/', '_', $path), '_')),
            'ok' => $result['ok'],
            'status' => $response['status'],
            'message' => $result['ok'] ? $result['message'] : ($response['error'] ?: $result['message']),
        ];
    }
    $checks = array_merge($checks, keliVerifyAdminAssets($root));
    $passed = !in_array(false, array_column($checks, 'ok'), true);
    if ($passed) {
        break;
    }
    if ($attempt < $attempts && $intervalMs > 0) {
        usleep($intervalMs * 1000);
    }
}

$report = [
    'schema_version' => 1,
    'status' => $passed ? 'passed' : 'failed',
    'base_url' => $baseUrl,
    'host' => $host !== '' ? $host : null,
    'checked_at' => gmdate('c'),
    'checks' => $checks,
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if ($outputPath !== '') {
    if (file_put_contents($outputPath, $json . PHP_EOL) === false) {
        keliSmokeFail("failed to write smoke report: {$outputPath}");
    }
}

fwrite($passed ? STDOUT : STDERR, $json . PHP_EOL);
exit($passed ? 0 : 1);

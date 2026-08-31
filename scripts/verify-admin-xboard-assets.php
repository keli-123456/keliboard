<?php

declare(strict_types=1);

/**
 * Guardrails for the private keli-admin build artifacts committed to keliboard.
 *
 * keliboard intentionally ships only the compiled admin assets. This script
 * verifies that the committed entry files exist and that index.html cache-busts
 * them with the current file-content hash produced by the private build sync.
 */

$root = dirname(__DIR__);
$adminRoot = $root . '/public/assets/admin-xboard';
$indexHtmlPath = $adminRoot . '/index.html';

$fail = static function (string $message): void {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
};

if (!is_file($indexHtmlPath)) {
    $fail("admin index.html not found at {$indexHtmlPath}");
}

$indexHtml = file_get_contents($indexHtmlPath);
if ($indexHtml === false) {
    $fail('failed to read admin index.html');
}

$expectedAssets = [
    'index' => [
        'file' => $indexHtmlPath,
        'relative_path' => 'index.html',
    ],
    'js' => [
        'file' => $adminRoot . '/assets/index.js',
        'public_path' => '/assets/admin-xboard/assets/index.js',
        'relative_path' => 'assets/index.js',
    ],
    'css' => [
        'file' => $adminRoot . '/assets/index.css',
        'public_path' => '/assets/admin-xboard/assets/index.css',
        'relative_path' => 'assets/index.css',
    ],
];

foreach ($expectedAssets as $type => $asset) {
    if (!is_file($asset['file'])) {
        $fail("admin {$type} asset not found at {$asset['file']}");
    }

    $content = file_get_contents($asset['file']);
    if ($content === false) {
        $fail("failed to read admin {$type} asset");
    }

    if ($type === 'index') {
        continue;
    }

    $expectedVersion = substr(hash('sha256', $content), 0, 12);
    $quotedPath = preg_quote($asset['public_path'], '/');
    $pattern = '/(?:src|href)=["\']' . $quotedPath . '\?v=([a-f0-9]{12})["\']/';

    if (!preg_match($pattern, $indexHtml, $matches)) {
        $fail("admin {$type} asset is not referenced with a 12-char content hash");
    }

    if ($matches[1] !== $expectedVersion) {
        $fail(
            "admin {$type} asset hash mismatch; index.html has {$matches[1]}, "
            . "expected {$expectedVersion}"
        );
    }
}

$buildManifestPath = $adminRoot . '/build-manifest.json';
if (!is_file($buildManifestPath)) {
    $fail("admin build manifest not found at {$buildManifestPath}");
}

try {
    $buildManifest = json_decode(
        (string) file_get_contents($buildManifestPath),
        true,
        flags: JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    $fail('admin build manifest is invalid JSON: ' . $e->getMessage());
}

if (($buildManifest['component'] ?? null) !== 'keli-admin') {
    $fail('admin build manifest component must be keli-admin');
}
if (preg_match('/^[a-f0-9]{40}$/', (string) ($buildManifest['source_git_sha'] ?? '')) !== 1) {
    $fail('admin build manifest is missing a valid source_git_sha');
}

$manifestFiles = is_array($buildManifest['files'] ?? null) ? $buildManifest['files'] : [];
foreach ($expectedAssets as $type => $asset) {
    $relativePath = $asset['relative_path'];
    $expectedHash = strtolower((string) ($manifestFiles[$relativePath]['sha256'] ?? ''));
    $actualHash = strtolower((string) hash_file('sha256', $asset['file']));
    if (preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1 || !hash_equals($expectedHash, $actualHash)) {
        $fail("admin {$type} asset does not match build-manifest.json");
    }
}

fwrite(STDOUT, "OK: admin-xboard asset versions and build manifest match committed files\n");

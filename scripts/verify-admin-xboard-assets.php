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
    'js' => [
        'file' => $adminRoot . '/assets/index.js',
        'public_path' => '/assets/admin-xboard/assets/index.js',
    ],
    'css' => [
        'file' => $adminRoot . '/assets/index.css',
        'public_path' => '/assets/admin-xboard/assets/index.css',
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

fwrite(STDOUT, "OK: admin-xboard asset versions match committed files\n");

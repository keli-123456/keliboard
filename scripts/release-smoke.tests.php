<?php

declare(strict_types=1);

define('KELI_RELEASE_SMOKE_LIBRARY', true);
require __DIR__ . '/release-smoke.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$validConfig = keliValidateSmokeResponse(
    '/api/v1/guest/comm/config',
    200,
    json_encode(['data' => ['app_name' => 'Keli']], JSON_THROW_ON_ERROR)
);
$assert($validConfig['ok'], 'valid guest config should pass');

$missingName = keliValidateSmokeResponse('/api/v1/guest/comm/config', 200, '{"data":{}}');
$assert(!$missingName['ok'] && str_contains($missingName['message'], 'app_name'), 'missing app_name should fail');

$invalidPlan = keliValidateSmokeResponse('/api/v1/guest/plan/fetch', 200, '<html>broken</html>');
$assert(!$invalidPlan['ok'] && str_contains($invalidPlan['message'], 'invalid JSON'), 'invalid plan JSON should fail');

$httpFailure = keliValidateSmokeResponse('/', 503, 'maintenance');
$assert(!$httpFailure['ok'] && str_contains($httpFailure['message'], 'HTTP 503'), 'HTTP failure should fail');

$root = sys_get_temp_dir() . '/keli-release-smoke-' . bin2hex(random_bytes(6));
$assetRoot = $root . '/public/assets/admin-xboard';
mkdir($assetRoot . '/assets', 0777, true);
file_put_contents($assetRoot . '/index.html', '<!doctype html>');
file_put_contents($assetRoot . '/assets/index.js', 'console.log("ok")');
file_put_contents($assetRoot . '/assets/index.css', 'body{}');
$files = [
    'index.html' => [
        'bytes' => filesize($assetRoot . '/index.html'),
        'sha256' => hash_file('sha256', $assetRoot . '/index.html'),
    ],
    'assets/index.js' => [
        'bytes' => filesize($assetRoot . '/assets/index.js'),
        'sha256' => hash_file('sha256', $assetRoot . '/assets/index.js'),
    ],
    'assets/index.css' => [
        'bytes' => filesize($assetRoot . '/assets/index.css'),
        'sha256' => hash_file('sha256', $assetRoot . '/assets/index.css'),
    ],
];
$writeManifest = static function (array $manifestFiles) use ($assetRoot): void {
    file_put_contents(
        $assetRoot . '/build-manifest.json',
        json_encode([
            'component' => 'keli-admin',
            'source_git_sha' => str_repeat('a', 40),
            'files' => $manifestFiles,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
    );
};
$writeManifest($files);

$assetChecks = keliVerifyAdminAssets($root);
$assert(!in_array(false, array_column($assetChecks, 'ok'), true), 'valid admin assets should pass');
file_put_contents($assetRoot . '/assets/index.js', 'changed');
$changedChecks = keliVerifyAdminAssets($root);
$assert(in_array(false, array_column($changedChecks, 'ok'), true), 'changed admin asset should fail');
file_put_contents($assetRoot . '/assets/index.js', 'console.log("ok")');
$missingEvidence = $files;
unset($missingEvidence['assets/index.css']);
$writeManifest($missingEvidence);
$missingEvidenceChecks = keliVerifyAdminAssets($root);
$assert(in_array(false, array_column($missingEvidenceChecks, 'ok'), true), 'missing asset evidence should fail');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($root);

fwrite(STDOUT, "release smoke tests passed\n");

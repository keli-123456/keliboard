<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$verifier = $root . '/scripts/verify-deployment-receipt.php';
$fixturePath = $root . '/tests/Fixtures/release/deployment-receipt.valid.jsonl';
$fixture = (string) file_get_contents($fixturePath);

$run = static function (string $contents, array $arguments = []) use ($verifier): array {
    $path = tempnam(sys_get_temp_dir(), 'keli-deployment-receipt-');
    if ($path === false) {
        throw new RuntimeException('failed to create temporary receipt');
    }
    file_put_contents($path, $contents);
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $verifier, ...$arguments, $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        unlink($path);
        throw new RuntimeException('failed to start receipt verifier');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    unlink($path);
    return [$exit, (string) $stdout . (string) $stderr];
};

$assert = static function (array $result, int $exit, string $text): void {
    if ($result[0] !== $exit || !str_contains($result[1], $text)) {
        throw new RuntimeException("unexpected verifier result: " . json_encode($result));
    }
};

$assert($run($fixture, ['--strict', '--expect=succeeded']), 0, 'receipt is succeeded');

$missingGate = implode("\n", array_filter(
    explode("\n", trim($fixture)),
    static fn(string $line): bool => !str_contains($line, '"event":"canary_http"')
)) . "\n";
$assert($run($missingGate, ['--strict']), 1, 'requires passed gate: canary_http');

$mutableImage = str_replace(
    'ghcr.io/keli-123456/keliboard@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    'ghcr.io/keli-123456/keliboard:main',
    $fixture
);
$assert($run($mutableImage, ['--strict']), 1, 'immutable target image digest');

$rollbackLines = explode("\n", trim($fixture));
$rollbackLines = array_values(array_filter($rollbackLines, static function (string $line): bool {
    return !str_contains($line, '"event":"cutover"')
        && !str_contains($line, '"event":"post_deploy_http"')
        && !str_contains($line, '"event":"deployment_finished"');
}));
$rollbackLines[] = '{"deployment_id":"20260830-120000-2222222","event":"rollback","status":"passed","evidence":"previous_release_restored","at":"2026-08-30T04:00:04Z"}';
$rollbackLines[] = '{"deployment_id":"20260830-120000-2222222","event":"deployment_finished","status":"rolled_back","reason":"cutover_failed","at":"2026-08-30T04:00:05Z"}';
$assert($run(implode("\n", $rollbackLines) . "\n", ['--strict', '--expect=rolled_back']), 0, 'receipt is rolled_back');

fwrite(STDOUT, "deployment receipt verifier tests passed\n");

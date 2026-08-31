<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fixturePath = $root . '/tests/Fixtures/release/release-manifest.valid.json';
$verifierPath = $root . '/scripts/verify-release-manifest.php';

$fixture = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);

$run = static function (array $manifest, bool $bom = false) use ($verifierPath): array {
    $path = tempnam(sys_get_temp_dir(), 'keli-release-manifest-');
    if ($path === false) {
        throw new RuntimeException('failed to create temporary manifest');
    }
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($path, ($bom ? "\xEF\xBB\xBF" : '') . $json);

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $verifierPath, '--strict', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        @unlink($path);
        throw new RuntimeException('failed to start release manifest verifier');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($path);

    return [$exitCode, (string) $stdout, (string) $stderr];
};

$assertResult = static function (array $result, int $expectedExit, string $expectedText): void {
    [$exitCode, $stdout, $stderr] = $result;
    $combined = $stdout . $stderr;
    if ($exitCode !== $expectedExit || !str_contains($combined, $expectedText)) {
        throw new RuntimeException(
            "unexpected verifier result: exit={$exitCode}, expected={$expectedExit}, output={$combined}"
        );
    }
};

$assertResult($run($fixture), 0, 'passed strict validation');
$assertResult($run($fixture, true), 0, 'passed strict validation');

$sourceMismatch = $fixture;
$sourceMismatch['artifacts']['admin_bundle']['source_git_sha'] = str_repeat('9', 40);
$assertResult($run($sourceMismatch), 1, 'source SHA does not match');

$dirtyArtifact = $fixture;
$dirtyArtifact['artifacts']['user_theme']['metadata']['source_dirty'] = true;
$assertResult($run($dirtyArtifact), 1, 'metadata.source_dirty to be false');

$contractMismatch = $fixture;
$contractMismatch['compatibility']['node_api']['kelinode_rs'] = 'different';
$assertResult($run($contractMismatch), 1, 'node API contract mismatch');

$missingLock = $fixture;
$missingLock['repositories']['keli_native_client']['dependency_locks'] = [];
$assertResult($run($missingLock), 1, 'dependency lock evidence');

fwrite(STDOUT, "release manifest verifier tests passed\n");

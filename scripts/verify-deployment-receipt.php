<?php

declare(strict_types=1);

/**
 * Validate a deployment JSONL receipt and optionally bind it to a release manifest.
 *
 * Usage:
 *   php scripts/verify-deployment-receipt.php --strict --expect=succeeded receipt.jsonl
 *   php scripts/verify-deployment-receipt.php --release-manifest=release-manifest.json receipt.jsonl
 */

$arguments = array_slice($argv, 1);
$strict = false;
$expect = 'any';
$manifestPath = null;
$receiptPath = null;

foreach ($arguments as $argument) {
    if ($argument === '--strict') {
        $strict = true;
        continue;
    }
    if (str_starts_with($argument, '--expect=')) {
        $expect = substr($argument, strlen('--expect='));
        continue;
    }
    if (str_starts_with($argument, '--release-manifest=')) {
        $manifestPath = substr($argument, strlen('--release-manifest='));
        continue;
    }
    if ($receiptPath !== null) {
        fwrite(STDERR, "ERROR: only one receipt path may be provided\n");
        exit(2);
    }
    $receiptPath = $argument;
}

$fail = static function (string $message): never {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
};

if (!in_array($expect, ['any', 'succeeded', 'rolled_back', 'rollback_failed'], true)) {
    $fail("unsupported expected terminal status: {$expect}");
}
if ($receiptPath === null || $receiptPath === '' || !is_file($receiptPath)) {
    $fail('deployment receipt path is required and must exist');
}

$lines = file($receiptPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($lines) || count($lines) < 3) {
    $fail('deployment receipt must contain at least three JSONL events');
}

$events = [];
foreach ($lines as $index => $line) {
    try {
        $event = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $fail('invalid JSON on receipt line ' . ($index + 1) . ': ' . $e->getMessage());
    }
    if (!is_array($event)) {
        $fail('receipt line ' . ($index + 1) . ' must be a JSON object');
    }
    $events[] = $event;
}

$first = $events[0];
$last = $events[count($events) - 1];
if (($first['schema_version'] ?? null) !== 1 || ($first['event'] ?? null) !== 'deployment_started') {
    $fail('first receipt event must be schema_version 1 deployment_started');
}
if (($last['event'] ?? null) !== 'deployment_finished') {
    $fail('last receipt event must be deployment_finished');
}

$deploymentId = trim((string) ($first['deployment_id'] ?? ''));
if ($deploymentId === '') {
    $fail('deployment_id is required');
}
$shaPattern = '/^[a-f0-9]{40}$/';
foreach (['previous_git_sha', 'target_git_sha'] as $key) {
    if (preg_match($shaPattern, (string) ($first[$key] ?? '')) !== 1) {
        $fail("{$key} must be a lowercase 40-character Git SHA");
    }
}
foreach (['previous_image', 'target_image'] as $key) {
    if (trim((string) ($first[$key] ?? '')) === '') {
        $fail("{$key} is required");
    }
}

$allowedStatuses = ['running', 'passed', 'failed', 'skipped', 'succeeded', 'rolled_back', 'rollback_failed'];
$byName = [];
foreach ($events as $index => $event) {
    if (($event['deployment_id'] ?? null) !== $deploymentId) {
        $fail('deployment_id changed on receipt line ' . ($index + 1));
    }
    $name = trim((string) ($event['event'] ?? ''));
    $status = trim((string) ($event['status'] ?? ''));
    if ($name === '' || !in_array($status, $allowedStatuses, true)) {
        $fail('invalid event or status on receipt line ' . ($index + 1));
    }
    if (!isset($event['at']) || strtotime((string) $event['at']) === false) {
        $fail('invalid timestamp on receipt line ' . ($index + 1));
    }
    $byName[$name][] = $event;
}

$terminalStatus = (string) ($last['status'] ?? '');
if (!in_array($terminalStatus, ['succeeded', 'rolled_back', 'rollback_failed'], true)) {
    $fail("invalid terminal deployment status: {$terminalStatus}");
}
if ($expect !== 'any' && $terminalStatus !== $expect) {
    $fail("expected terminal status {$expect}, got {$terminalStatus}");
}

$latestStatus = static function (string $name) use ($byName): ?string {
    $items = $byName[$name] ?? [];
    if ($items === []) {
        return null;
    }
    return (string) ($items[count($items) - 1]['status'] ?? '');
};

if ($terminalStatus === 'succeeded') {
    foreach (['preflight', 'backup_verified', 'canary_http', 'cutover', 'post_deploy_http'] as $gate) {
        if ($latestStatus($gate) !== 'passed') {
            $fail("successful deployment requires passed gate: {$gate}");
        }
    }
    if ($latestStatus('rollback') !== null) {
        $fail('successful deployment cannot contain a rollback event');
    }
}

if (in_array($terminalStatus, ['rolled_back', 'rollback_failed'], true)) {
    $expectedRollback = $terminalStatus === 'rolled_back' ? 'passed' : 'failed';
    if ($latestStatus('rollback') !== $expectedRollback) {
        $fail("{$terminalStatus} receipt requires rollback status {$expectedRollback}");
    }
}

$manifestSha = strtolower(trim((string) ($first['release_manifest_sha256'] ?? '')));
if ($strict) {
    if (preg_match('/^[a-f0-9]{64}$/', $manifestSha) !== 1) {
        $fail('strict receipt requires release_manifest_sha256');
    }
    $targetImage = (string) $first['target_image'];
    if (!str_starts_with($targetImage, 'sha256:') && !str_contains($targetImage, '@sha256:')) {
        $fail('strict receipt requires an immutable target image digest');
    }
}

if ($manifestPath !== null && $manifestPath !== '') {
    if (!is_file($manifestPath)) {
        $fail("release manifest not found: {$manifestPath}");
    }
    $actualManifestSha = strtolower((string) hash_file('sha256', $manifestPath));
    if ($manifestSha === '' || !hash_equals($manifestSha, $actualManifestSha)) {
        $fail('release manifest SHA256 does not match deployment receipt');
    }
    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $fail('invalid release manifest JSON: ' . $e->getMessage());
    }
    $manifestBoardSha = (string) ($manifest['repositories']['keliboard']['git_sha'] ?? '');
    if ($manifestBoardSha !== (string) $first['target_git_sha']) {
        $fail('deployment target SHA does not match release manifest keliboard SHA');
    }
}

fwrite(STDOUT, "OK: deployment {$deploymentId} receipt is {$terminalStatus}\n");

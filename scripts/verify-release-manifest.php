<?php

declare(strict_types=1);

/**
 * Validate the invariants of a Keli full-stack release manifest.
 *
 * Usage:
 *   php scripts/verify-release-manifest.php path/to/release.json
 *   php scripts/verify-release-manifest.php --strict --workspace-root=.. path/to/release.json
 */

$arguments = array_slice($argv, 1);
$strict = false;
$workspaceRoot = null;
$manifestPath = null;

foreach ($arguments as $argument) {
    if ($argument === '--strict') {
        $strict = true;
        continue;
    }
    if (str_starts_with($argument, '--workspace-root=')) {
        $workspaceRoot = substr($argument, strlen('--workspace-root='));
        continue;
    }
    if ($manifestPath !== null) {
        fwrite(STDERR, "ERROR: only one manifest path may be provided\n");
        exit(2);
    }
    $manifestPath = $argument;
}

$fail = static function (string $message): never {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
};

if ($manifestPath === null || $manifestPath === '') {
    $fail('release manifest path is required');
}
if (!is_file($manifestPath)) {
    $fail("release manifest not found: {$manifestPath}");
}

$raw = file_get_contents($manifestPath);
if ($raw === false) {
    $fail("failed to read release manifest: {$manifestPath}");
}
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

try {
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    $fail("invalid release manifest JSON: {$e->getMessage()}");
}

$requireArray = static function (mixed $value, string $path) use ($fail): array {
    if (!is_array($value)) {
        $fail("{$path} must be an object");
    }
    return $value;
};
$requireString = static function (mixed $value, string $path) use ($fail): string {
    if (!is_string($value) || trim($value) === '') {
        $fail("{$path} must be a non-empty string");
    }
    return $value;
};
$requireSha = static function (mixed $value, string $path, int $length) use ($fail): string {
    if (!is_string($value) || !preg_match('/^[a-f0-9]{' . $length . '}$/', $value)) {
        $fail("{$path} must be a lowercase {$length}-character hexadecimal value");
    }
    return $value;
};

if (($manifest['schema_version'] ?? null) !== 1) {
    $fail('schema_version must be 1');
}
$releaseVersion = $requireString($manifest['release_version'] ?? null, 'release_version');
if (!preg_match('/^v\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $releaseVersion)) {
    $fail("release_version is not a supported semantic version: {$releaseVersion}");
}

$repositories = $requireArray($manifest['repositories'] ?? null, 'repositories');
$requiredRepositories = [
    'keliboard',
    'keli_admin',
    'keli_user',
    'kelinode_rs',
    'keli_core_rs',
    'keli_native_client',
];

foreach ($requiredRepositories as $repositoryKey) {
    $repository = $requireArray($repositories[$repositoryKey] ?? null, "repositories.{$repositoryKey}");
    $requireString($repository['name'] ?? null, "repositories.{$repositoryKey}.name");
    $requireSha($repository['git_sha'] ?? null, "repositories.{$repositoryKey}.git_sha", 40);
    if (!is_bool($repository['dirty'] ?? null)) {
        $fail("repositories.{$repositoryKey}.dirty must be a boolean");
    }
    if ($strict && $repository['dirty']) {
        $fail("strict release cannot use a dirty repository: {$repositoryKey}");
    }

    $locks = $repository['dependency_locks'] ?? null;
    if (!is_array($locks)) {
        $fail("repositories.{$repositoryKey}.dependency_locks must be an array");
    }
    if ($strict && $locks === []) {
        $fail("strict release requires dependency lock evidence for repository: {$repositoryKey}");
    }
    foreach ($locks as $index => $lock) {
        $lock = $requireArray($lock, "repositories.{$repositoryKey}.dependency_locks.{$index}");
        $requireString($lock['path'] ?? null, "repositories.{$repositoryKey}.dependency_locks.{$index}.path");
        $requireSha($lock['sha256'] ?? null, "repositories.{$repositoryKey}.dependency_locks.{$index}.sha256", 64);
    }
}

$compatibility = $requireArray($manifest['compatibility'] ?? null, 'compatibility');
$nodeApi = $requireArray($compatibility['node_api'] ?? null, 'compatibility.node_api');
$panelContract = $requireString($nodeApi['panel'] ?? null, 'compatibility.node_api.panel');
$nodeContract = $requireString($nodeApi['kelinode_rs'] ?? null, 'compatibility.node_api.kelinode_rs');
if (($nodeApi['match'] ?? null) !== true || $panelContract !== $nodeContract) {
    $fail("node API contract mismatch: panel={$panelContract}, kelinode-rs={$nodeContract}");
}

$embeddedCore = $requireArray($compatibility['embedded_core'] ?? null, 'compatibility.embedded_core');
$nodeSha = $requireSha($embeddedCore['kelinode_rs_git_sha'] ?? null, 'compatibility.embedded_core.kelinode_rs_git_sha', 40);
$coreSha = $requireSha($embeddedCore['keli_core_rs_git_sha'] ?? null, 'compatibility.embedded_core.keli_core_rs_git_sha', 40);
if ($nodeSha !== ($repositories['kelinode_rs']['git_sha'] ?? null)) {
    $fail('embedded core kelinode-rs SHA does not match repositories.kelinode_rs.git_sha');
}
if ($coreSha !== ($repositories['keli_core_rs']['git_sha'] ?? null)) {
    $fail('embedded core keli-core-rs SHA does not match repositories.keli_core_rs.git_sha');
}

$artifacts = $requireArray($manifest['artifacts'] ?? null, 'artifacts');
$artifactKeys = ['admin_bundle', 'user_theme', 'kelinode_rs', 'native_client'];
$artifactRepositoryKeys = [
    'admin_bundle' => 'keli_admin',
    'user_theme' => 'keli_user',
    'kelinode_rs' => 'kelinode_rs',
    'native_client' => 'keli_native_client',
];
$artifactDirtyKeys = [
    'admin_bundle' => ['source_dirty'],
    'user_theme' => ['source_dirty'],
    'kelinode_rs' => ['kelinode_rs_dirty', 'keli_core_rs_dirty'],
    'native_client' => ['source_dirty'],
];
$allowedStatuses = ['passed', 'failed', 'skipped'];

foreach ($artifactKeys as $artifactKey) {
    $artifact = $requireArray($artifacts[$artifactKey] ?? null, "artifacts.{$artifactKey}");
    $status = $requireString($artifact['status'] ?? null, "artifacts.{$artifactKey}.status");
    if (!in_array($status, $allowedStatuses, true)) {
        $fail("artifacts.{$artifactKey}.status is invalid: {$status}");
    }
    if ($strict && $status !== 'passed') {
        $fail("strict release requires artifact {$artifactKey} to pass");
    }
    $artifactSourceSha = $requireSha($artifact['source_git_sha'] ?? null, "artifacts.{$artifactKey}.source_git_sha", 40);
    $repositoryKey = $artifactRepositoryKeys[$artifactKey];
    if ($artifactSourceSha !== ($repositories[$repositoryKey]['git_sha'] ?? null)) {
        $fail("artifact {$artifactKey} source SHA does not match repositories.{$repositoryKey}.git_sha");
    }

    $metadata = $requireArray($artifact['metadata'] ?? [], "artifacts.{$artifactKey}.metadata");
    if ($strict) {
        foreach ($artifactDirtyKeys[$artifactKey] as $dirtyKey) {
            if (!array_key_exists($dirtyKey, $metadata) || $metadata[$dirtyKey] !== false) {
                $fail("strict release requires artifacts.{$artifactKey}.metadata.{$dirtyKey} to be false");
            }
        }
    }
    if ($artifactKey === 'kelinode_rs' && $status === 'passed') {
        if (($metadata['kelinode_rs_git_sha'] ?? null) !== ($repositories['kelinode_rs']['git_sha'] ?? null)) {
            $fail('kelinode-rs artifact metadata does not match repositories.kelinode_rs.git_sha');
        }
        if (($metadata['keli_core_rs_git_sha'] ?? null) !== ($repositories['keli_core_rs']['git_sha'] ?? null)) {
            $fail('kelinode-rs artifact metadata does not match repositories.keli_core_rs.git_sha');
        }
    }

    $files = $artifact['files'] ?? null;
    if (!is_array($files)) {
        $fail("artifacts.{$artifactKey}.files must be an array");
    }
    if ($strict && $files === []) {
        $fail("strict release requires files for artifact {$artifactKey}");
    }

    foreach ($files as $index => $file) {
        $file = $requireArray($file, "artifacts.{$artifactKey}.files.{$index}");
        $relativePath = $requireString($file['path'] ?? null, "artifacts.{$artifactKey}.files.{$index}.path");
        $expectedSha = $requireSha($file['sha256'] ?? null, "artifacts.{$artifactKey}.files.{$index}.sha256", 64);
        if (!is_int($file['bytes'] ?? null) || $file['bytes'] < 0) {
            $fail("artifacts.{$artifactKey}.files.{$index}.bytes must be a non-negative integer");
        }

        if ($workspaceRoot !== null && $workspaceRoot !== '') {
            $resolvedPath = rtrim($workspaceRoot, "\\/") . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($resolvedPath)) {
                $fail("artifact file not found: {$relativePath}");
            }
            $actualSha = hash_file('sha256', $resolvedPath);
            if (!is_string($actualSha) || !hash_equals($expectedSha, strtolower($actualSha))) {
                $fail("artifact SHA256 mismatch: {$relativePath}");
            }
            if (filesize($resolvedPath) !== $file['bytes']) {
                $fail("artifact byte count mismatch: {$relativePath}");
            }
        }
    }
}

$gates = $requireArray($manifest['gates'] ?? null, 'gates');
if ($strict && ($gates['strict'] ?? null) !== true) {
    $fail('strict validation requires gates.strict to be true');
}
foreach (['source_clean', 'contracts', 'artifacts'] as $gate) {
    $status = $requireString($gates[$gate] ?? null, "gates.{$gate}");
    if (!in_array($status, $allowedStatuses, true)) {
        $fail("gates.{$gate} is invalid: {$status}");
    }
    if ($strict && $status !== 'passed') {
        $fail("strict release gate did not pass: {$gate}");
    }
}

fwrite(STDOUT, "OK: release manifest {$releaseVersion} passed" . ($strict ? ' strict' : '') . " validation\n");

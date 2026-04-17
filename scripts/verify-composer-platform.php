<?php

declare(strict_types=1);

/**
 * Guardrails for composer dependency resolution.
 *
 * This script ensures composer.json keeps an explicit 8.2 platform pin so
 * lock files remain reproducible and compatible with CI/runtime PHP version.
 */

$root = dirname(__DIR__);
$composerJsonPath = $root . '/composer.json';

if (!is_file($composerJsonPath)) {
    fwrite(STDERR, "ERROR: composer.json not found at {$composerJsonPath}\n");
    exit(1);
}

$raw = file_get_contents($composerJsonPath);
if ($raw === false) {
    fwrite(STDERR, "ERROR: failed to read composer.json\n");
    exit(1);
}

try {
    /** @var array<string, mixed> $composerJson */
    $composerJson = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "ERROR: invalid composer.json: {$e->getMessage()}\n");
    exit(1);
}

$requiredPhp = $composerJson['require']['php'] ?? null;
$platformPhp = $composerJson['config']['platform']['php'] ?? null;
$expectedPlatformPhp = '8.2.30';

if (!is_string($requiredPhp) || !str_contains($requiredPhp, '8.2')) {
    fwrite(STDERR, "ERROR: require.php must target PHP 8.2; got: " . var_export($requiredPhp, true) . "\n");
    exit(1);
}

if (!is_string($platformPhp) || $platformPhp !== $expectedPlatformPhp) {
    fwrite(
        STDERR,
        "ERROR: composer.json config.platform.php must be {$expectedPlatformPhp}; got: "
        . var_export($platformPhp, true) . "\n"
    );
    exit(1);
}

fwrite(STDOUT, "OK: composer platform is pinned to PHP {$platformPhp}\n");


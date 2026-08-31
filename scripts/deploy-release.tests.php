<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$deploy = (string) file_get_contents($root . '/scripts/deploy-release.sh');
$rollback = (string) file_get_contents($root . '/scripts/rollback-release.sh');
$update = (string) file_get_contents($root . '/update.sh');
$compose = (string) file_get_contents($root . '/compose.sample.yaml');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'git worktree add --detach' => 'candidate worktree',
    'backup:database --sync --trigger=manual --json' => 'synchronous machine-readable database backup',
    'backup:restore-drill' => 'backup restore drill',
    '--id="$BACKUP_ID"' => 'restore drill bound to the exact new backup',
    'canary_http' => 'canary HTTP gate',
    'verify_services_running' => 'service process gate',
    'post_deploy_http' => 'post-deploy HTTP gate',
    'perform_rollback' => 'automatic rollback',
    'composer install' => 'locked dependency install',
    'deployment-receipt.jsonl' => 'deployment receipt',
    'network_mode: host' => 'isolated host-network canary',
    '$ROOT/storage/theme:/www/storage/theme:ro' => 'persisted custom themes in the canary',
    '$ROOT/storage/backup:/www/storage/backup' => 'persistent production backup storage in the canary',
    'http://127.0.0.1:$CANARY_PORT/api/v1/guest/comm/config' => 'canary-specific container health check',
] as $needle => $label) {
    $assert(str_contains($deploy, $needle), "deploy script is missing {$label}");
}

$assert(!str_contains($deploy, 'composer update'), 'deploy script must not run composer update');
$assert(!str_contains($deploy, 'rm -rf composer.lock'), 'deploy script must not delete composer.lock');
$assert(!str_contains($deploy, 'git reset --hard origin/'), 'deploy script must resolve an exact target SHA');
$webRoutes = (string) file_get_contents($root . '/routes/web.php');
$assert(
    !str_contains($webRoutes, "admin_setting(['frontend_theme' => \$theme])"),
    'an HTTP request must not permanently replace a temporarily unavailable custom theme'
);
$assert(str_contains($update, 'scripts/deploy-release.sh'), 'legacy update.sh must delegate to safe deployment');
$assert(!str_contains($update, 'composer update'), 'legacy update.sh must not update dependencies');
$assert(str_contains($deploy, 'resolve_base_web_image'), 'legacy update must resolve the mutable web image from the base compose file');
$assert(str_contains($deploy, 'TARGET_IMAGE="$(resolve_base_web_image || true)"'), 'legacy update must pull the configured web image instead of reusing the pinned image id');
$assert(str_contains($deploy, 'ERROR: deployment failed (exit $code). Logs:'), 'deployment failures must show the operator where to find logs');
$assert(str_contains($deploy, 'compose_canary exec -T web php artisan backup:database'), 'the verified candidate must create the pre-cutover backup');
$assert(str_contains($deploy, '> "$DEPLOYMENT_DIR/backup.json" 2>&1'), 'backup failures must be preserved in the deployment evidence');
$assert(str_contains($rollback, '--no-fetch --ref="$previous_sha" --image="$previous_image"'), 'rollback must deploy the recorded immutable release without a network dependency');
$assert(substr_count($compose, '${KELIBOARD_IMAGE:-ghcr.io/keli-123456/keliboard:main}') === 3, 'all application services must share the pinned image variable');
$assert(str_contains($compose, 'healthcheck:'), 'compose sample must expose container health');

$shell = PHP_OS_FAMILY === 'Windows' && is_file('C:/Program Files/Git/bin/bash.exe')
    ? 'C:/Program Files/Git/bin/bash.exe'
    : 'sh';
$pipes = [];
$process = proc_open(
    [$shell, $root . '/scripts/deploy-release.sh', '--plan', '--no-fetch', '--ref=HEAD'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
if (!is_resource($process)) {
    throw new RuntimeException('failed to start deployment plan');
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
$output = (string) $stdout . (string) $stderr;
$assert($exit === 0, "deployment plan failed: {$output}");
$assert(str_contains($output, 'Keli safe deployment plan'), 'plan output is missing its heading');
$assert(str_contains($output, 'automatic code/image rollback'), 'plan output is missing rollback gate');

fwrite(STDOUT, "safe deployment tests passed\n");

<?php

declare(strict_types=1);

use App\Services\ServerMachine\ExampleRehearsal\Grant;
use App\Services\ServerMachine\ExampleRehearsal\ScratchRound;
use App\Services\ServerMachine\ExampleRehearsal\Wire;

if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$root = dirname(__DIR__, 2);
try {
    // Run from an isolated copy of the code without a panel environment.
    if (file_exists($root . '/.env')) throw new RuntimeException('panel_environment_not_allowed');
    require $root . '/vendor/autoload.php';
    $grant = Grant::parse(['id' => getenv('FAILOVER_EXAMPLE_GRANT_ID'), 'admin' => getenv('FAILOVER_EXAMPLE_ADMIN_ID'),
        'opens' => getenv('FAILOVER_EXAMPLE_OPENS_AT'), 'expires' => getenv('FAILOVER_EXAMPLE_EXPIRES_AT')]);
    $line = fgets(STDIN, Wire::MAX_REQUEST + 1);
    $action = $grant && is_string($line) ? Wire::decode($line, $grant) : null;
    $reply = $action === null ? ['error' => 'closed']
        : (new ScratchRound($root, (string) getenv('FAILOVER_EXAMPLE_STATE_DIR'), $grant))->handle($action);
} catch (Throwable) {
    $reply = ['error' => 'unavailable'];
}
echo json_encode($reply, JSON_THROW_ON_ERROR) . "\n";

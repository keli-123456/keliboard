<?php

declare(strict_types=1);

use App\Services\ServerMachine\Cloud\MachineUsdo1DataClient;
use App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope;
use App\Services\ServerMachine\Cloud\MachineUsdo1Runtime;

// Only mount this file as SCRIPT_FILENAME on the dedicated HTTPS virtual host.
ini_set('display_errors', '0');
$status = 404;
if (PHP_SAPI === 'fpm-fcgi' && ($_SERVER['HTTPS'] ?? '') === 'on') {
    $root = dirname(__DIR__, 2);
    try {
        require $root . '/vendor/autoload.php';
        MachineUsdo1LaunchScope::assertProcess('fpm-fcgi');
        $scope = MachineUsdo1LaunchScope::fromEnvironment();
        $body = file_get_contents('php://input', false, null, 0, 2049);
        $status = $body === false ? 400 : MachineUsdo1Runtime::receive($scope, $_SERVER, $body,
            static function (MachineUsdo1LaunchScope $scope, string $task, string $installation, string $secret, string $state): bool {
                return MachineUsdo1DataClient::fromEnvironment('receipt')->receipt($task, $installation, $secret, $state);
            });
    } catch (\Throwable) {
        $status = 503;
    }
}
http_response_code($status);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo $status === 200 ? '{"accepted":true}' : '{"accepted":false}';

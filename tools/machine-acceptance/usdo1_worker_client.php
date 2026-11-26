<?php

declare(strict_types=1);

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineUsdo1DataClient;
use App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
try {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    MachineUsdo1LaunchScope::assertProcess('cli');
    if ($argc !== 1) throw new CloudException('usdo1_data_operation_denied');
    echo json_encode(MachineUsdo1DataClient::fromEnvironment('worker')->tick(), JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (\Throwable $error) {
    $reason = $error instanceof CloudException ? $error->getMessage() : '';
    fwrite(STDERR, (preg_match('/\A[a-z_]{1,64}\z/', $reason) ? $reason : 'usdo1_worker_unconfirmed') . PHP_EOL);
    exit(1);
}

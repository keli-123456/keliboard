<?php
declare(strict_types=1);

// Test-only loopback router. No application environment file or production kernel boots.
require dirname(__DIR__) . '/bootstrap.php';

use Tests\Support\NativeFailoverConcurrentFixture as Fixture;
use App\Http\Controllers\V2\Server\MachineProvisionController;
use App\Services\ServerMachine\Cloud\MachineProvisionService;

ini_set('display_errors', '0');
try {
    if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
        || getenv('KELI_MEMBER_HTTP_FIXTURE') !== '1') throw new RuntimeException('fixture_only');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || ($_SERVER['REQUEST_URI'] ?? '') !== '/api/v2/server/machine/provision/result') {
        http_response_code(404); echo '{}'; return;
    }
    Fixture::attach((string) getenv('KELI_MEMBER_OWNER'), 'http');
    // Callbacks may record a receipt but must never start a queue worker or cloud job.
    app()->instance(Illuminate\Contracts\Bus\Dispatcher::class,
        new Illuminate\Bus\Dispatcher(app(), static fn () => new Illuminate\Queue\NullQueue()));
    config(['machine_failover.runner_enabled' => false, 'machine_failover.allow_replacement' => false,
        'machine_failover.allow_rotation' => false, 'machine_acceptance.allow_dns' => false,
        'machine_provision.allow_live' => getenv('KELI_MEMBER_CALLBACK_DISABLED') !== '1']);
    $response = (new MachineProvisionController())->result(Illuminate\Http\Request::capture(), app(MachineProvisionService::class));
    $response->send();
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['message' => 'fixture_rejected', 'diagnostic' => Fixture::errorCode($error)]);
}

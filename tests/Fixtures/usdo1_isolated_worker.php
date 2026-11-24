<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/tools/machine-acceptance/usdo1_isolated_execution.php';

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Keli\Acceptance\Usdo1IsolatedExecution;
use Tests\Support\NativeFailoverConcurrentFixture as Fixture;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$http = PHP_SAPI === 'cli-server';

try {
    if (getenv('KELI_USDO1_ACCEPTANCE') !== '1' || PHP_OS_FAMILY !== 'Linux'
        || !in_array(PHP_SAPI, ['cli','cli-server'], true)) throw new RuntimeException('fixture_only');
    if ($http && (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || ($_SERVER['REQUEST_URI'] ?? '') !== '/fixture/usdo1/callback')) {
        http_response_code(404);
        echo '{}';
        return;
    }
    Fixture::attach((string) getenv('KELI_USDO1_FIXTURE_OWNER'), $http ? 'usdo1-http' : 'usdo1-worker');
    app()->instance('env', 'testing');
    app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
    foreach (['machine_failover','machine_provision','machine_acceptance'] as $key)
        config([$key => require base_path('config/' . $key . '.php')]);
    $scope = Fixture::control('usdo1-test-scope');
    if (!is_array($scope) || array_keys($scope) !== ['admin','release','source','baseline','grant'])
        throw new RuntimeException('fixture_scope_missing');
    $denyDns = static fn () => throw new RuntimeException('DNS unavailable in the create/restart/callback scenario.');
    $package = new Usdo1IsolatedExecution($scope['admin'], $scope['release'], $scope['source'], $scope['baseline'],
        $denyDns, $denyDns, $denyDns, fn ($key) => Fixture::control('usdo1:' . $key),
        fn ($key, array $state) => Fixture::control('usdo1:' . $key, $state, true),
        static fn () => false, static fn () => false, fn () => now()->timestamp);
    if (!$http) {
        try {
            $state = $package->pulse($scope['grant']);
            Fixture::event('usdo1_pulse_done', $state['status']);
        } catch (LockTimeoutException) {
            Fixture::event('usdo1_lock_busy');
        }
        exit(0);
    }
    $body = file_get_contents('php://input', false, null, 0, 2049);
    $secret = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($body) || strlen($body) > 2048 || !preg_match('/^Bearer ([a-f0-9]{64})$/D', $secret, $m))
        throw new CloudException('invalid_callback');
    $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($data) || count($data) !== 3 || !is_string($data['task_id'] ?? null)
        || !is_string($data['installation_id'] ?? null) || !is_string($data['state'] ?? null))
        throw new CloudException('invalid_callback');
    $ok = $package->callback($scope['grant'], $data['task_id'], $data['installation_id'], $m[1], $data['state']);
    http_response_code($ok ? 200 : 401);
    echo json_encode(['accepted' => $ok]);
} catch (Throwable $error) {
    if ($http) {
        http_response_code(match (true) {
            $error instanceof CloudException, $error instanceof JsonException => 401,
            $error instanceof LockTimeoutException => 503,
            default => 500,
        });
        echo '{"accepted":false}';
    } else {
        if (isset(Fixture::$owner)) {
            try { Fixture::assertOwner(); Fixture::event('usdo1_worker_error', Fixture::errorCode($error)); }
            catch (Throwable) {}
        }
        fwrite(STDERR, Fixture::errorCode($error) . PHP_EOL);
        exit(1);
    }
}

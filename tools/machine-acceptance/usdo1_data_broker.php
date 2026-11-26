<?php

declare(strict_types=1);

use App\Services\ServerMachine\Cloud\MachineUsdo1DataBroker;
use App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope;
use App\Services\ServerMachine\Cloud\MachineUsdo1Runtime;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Bootstrap\{LoadConfiguration, LoadEnvironmentVariables};
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Support\Facades\Facade;

// Separate private FastCGI entry. Set the lane via a fixed FastCGI parameter per Unix listener.
ini_set('display_errors', '0');
$status = 404; $body = ['ok' => false];
$lane = $_SERVER['KELI_USDO1_DATA_LANE'] ?? '';
if (PHP_SAPI === 'fpm-fcgi' && in_array($lane, ['receipt', 'worker'], true)) {
    try {
        $root = dirname(__DIR__, 2);
        require $root . '/vendor/autoload.php';
        MachineUsdo1LaunchScope::assertProcess('fpm-fcgi');
        $scope = MachineUsdo1LaunchScope::fromEnvironment($lane === 'worker');
        $runtime = static function () use ($root): MachineUsdo1Runtime {
            $app = require $root . '/bootstrap/app.php';
            $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($app);
            foreach ([DatabaseServiceProvider::class, RedisServiceProvider::class, CacheServiceProvider::class,
                FilesystemServiceProvider::class, EncryptionServiceProvider::class] as $provider) $app->register($provider);
            Model::setConnectionResolver($app['db']);
            Model::unsetEventDispatcher();
            return $app->make(MachineUsdo1Runtime::class);
        };
        $broker = new MachineUsdo1DataBroker($scope, (string) getenv('KELI_USDO1_BROKER_RECEIPT_TOKEN'),
            (string) getenv('KELI_USDO1_BROKER_WORKER_TOKEN'),
            static fn (...$args) => $runtime()->callback(...$args),
            static fn (MachineUsdo1LaunchScope $scope) => $runtime()->pulse($scope));
        [$status, $body] = $broker->respond($lane, $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '',
            $_SERVER['HTTP_AUTHORIZATION'] ?? '', file_get_contents('php://input', false, null, 0, 2049) ?: '');
    } catch (\Throwable) {
        $status = 503;
    }
}
http_response_code($status);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode($body, JSON_THROW_ON_ERROR);

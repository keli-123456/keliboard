<?php

declare(strict_types=1);

use App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope;
use App\Services\ServerMachine\Cloud\MachineUsdo1Runtime;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Foundation\Bootstrap\{LoadConfiguration, LoadEnvironmentVariables, RegisterFacades};
use Illuminate\Redis\RedisServiceProvider;

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
            static function (...$receipt) use ($root): bool {
                $app = require $root . '/bootstrap/app.php';
                $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class, RegisterFacades::class]);
                foreach ([DatabaseServiceProvider::class, RedisServiceProvider::class,
                    CacheServiceProvider::class, EncryptionServiceProvider::class] as $provider) $app->register($provider);
                Model::setConnectionResolver($app['db']);
                Model::unsetEventDispatcher();
                return $app->make(MachineUsdo1Runtime::class)->callback(...$receipt);
            });
    } catch (\Throwable) {
        $status = 503;
    }
}
http_response_code($status);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo $status === 200 ? '{"accepted":true}' : '{"accepted":false}';

<?php

declare(strict_types=1);

use App\Services\ServerMachine\Cloud\{MachineFailoverExecutionGate, CloudException};
use App\Support\Setting;
use Illuminate\Foundation\Bootstrap\RegisterFacades;

// Installed only in the disposable checkout read by FPM. Not part of the release archive.
$app->afterBootstrapping(RegisterFacades::class, static function ($app): void {
    if (PHP_SAPI !== 'fpm-fcgi' || PHP_OS_FAMILY !== 'Linux' || posix_geteuid() === 0
        || getenv('KELI_CI_FPM_FIXTURE') !== '1') throw new RuntimeException('fixture_boundary');
    foreach ([AF_INET, AF_INET6] as $family) {
        socket_clear_error();
        if (@socket_create($family, SOCK_STREAM, SOL_TCP) !== false || !in_array(socket_last_error(), [1,97], true))
            throw new RuntimeException('fpm_inet_family_not_blocked');
    }
    $cfg = $app['config'];
    $owner = (string) getenv('KELI_CI_OWNER');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $owner)) throw new RuntimeException('fixture_identity');
    $r = '/run/keli-usdo1-launch-ci/data/';
    $cfg->set([
        'app.key' => str_repeat('n', 32),
        'database.default' => 'mysql',
        'database.connections.mysql' => [
            'driver'=>'mysql','host'=>'localhost','port'=>3306,'unix_socket'=>$r.'mysql.sock',
            'database'=>'keli_acceptance_native_failover','username'=>'keli_native_fixture',
            'password'=>(string)getenv('KELI_CI_MYSQL_PASSWORD'), 'charset'=>'utf8mb4',
            'collation'=>'utf8mb4_bin','strict'=>true,'isolation_level'=>'READ COMMITTED',
        ],
        'database.redis' => ['client'=>'phpredis','options'=>['prefix'=>'native-fixture:'.$owner.':'],
            'default'=>['host'=>$r.'redis.sock','port'=>0,'database'=>0,'timeout'=>2,'read_timeout'=>2]],
        'cache.default'=>'redis','cache.prefix'=>'cache:',
        'cache.stores.redis'=>['driver'=>'redis','connection'=>'default'],
        'machine_provision.allow_live'=>true,
    ]);
    $app->instance(Setting::class, new class extends Setting {
        public function __construct() {}
        public function get(string $key, mixed $default = null): mixed
        {
            return ['app_url' => 'https://panel.example.test','server_entry_probe_enable'=>true,'server_entry_probe_interval'=>5,
                'server_entry_probe_targets'=>['1.1.1.1','9.9.9.9']][$key] ?? $default;
        }
    });
    $app->instance(MachineFailoverExecutionGate::class, new class($owner) extends MachineFailoverExecutionGate {
        public function __construct(private string $owner) {}
        public function assertAllowed(string $operation): void
        {
            if (!in_array($operation, ['usdo1_execution_callback','native_task','context'], true)) parent::assertAllowed($operation);
            if (app('db')->table('native_fixture_owner')->value('owner') !== $this->owner
                || app('redis')->connection()->get('owner') !== $this->owner) throw new CloudException('fixture_identity');
        }
    });
});

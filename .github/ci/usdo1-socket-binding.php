<?php

declare(strict_types=1);

use App\Services\ServerMachine\Cloud\{CloudException, MachineFailoverExecutionGate};
use App\Support\Setting;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;

// This hook exists only in the disposable copy. Neither root release has it.
$app->afterBootstrapping(LoadConfiguration::class, static function ($app): void {
    $db = __DIR__.'/rehearsal.sqlite';
    $owner = (string) getenv('KELI_RECEIPT_FIXTURE_OWNER');
    $key = (string) getenv('KELI_RECEIPT_FIXTURE_KEY');
    if (PHP_OS_FAMILY !== 'Linux' || PHP_VERSION !== '8.4.28' || posix_geteuid() === 0
        || !in_array(PHP_SAPI, ['cli', 'fpm-fcgi'], true)
        || $app->basePath() !== __DIR__.'/release' || !str_starts_with(basename(__DIR__), 'keli-123-receipts-')
        || realpath($db) !== $db || fileowner($db) !== posix_geteuid()
        || !preg_match('/\A[a-f0-9]{32}\z/', $owner) || !preg_match('/\A[a-f0-9]{64}\z/', $key)) {
        throw new RuntimeException('fixture_scope_invalid');
    }
    foreach ([AF_INET, AF_INET6] as $family) {
        socket_clear_error();
        if (@socket_create($family, SOCK_STREAM, SOL_TCP) !== false || socket_last_error() !== 1) {
            throw new RuntimeException('fixture_network_not_blocked');
        }
    }
    $s = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (!$s || @socket_connect($s, __DIR__.'/blocked.sock') !== false || socket_last_error($s) !== 1) {
        throw new RuntimeException('fixture_connect_not_blocked');
    }
    socket_close($s);
    $config = $app['config'];
    $app->register(FilesystemServiceProvider::class);
    $config->set('database', [
        'default'=>'sqlite',
        'connections'=>['sqlite'=>['driver'=>'sqlite', 'database'=>$db, 'prefix'=>'',
            'foreign_key_constraints'=>true, 'busy_timeout'=>2000, 'journal_mode'=>'delete']],
        'redis'=>[],
    ]);
    $config->set([
        'app.key'=>'base64:'.base64_encode(hex2bin($key)), 'app.cipher'=>'AES-256-CBC',
        'cache.default'=>'file', 'cache.prefix'=>'receipt-fixture:',
        'cache.stores.file'=>['driver'=>'file','path'=>__DIR__.'/cache','lock_path'=>__DIR__.'/cache'],
        'queue.default'=>'unavailable_fixture', 'queue.connections'=>[],
        'machine_provision.allow_live'=>true, // Only the isolated in-process receipt path is bound.
        'machine_failover.runner_enabled'=>false, 'machine_failover.allow_rotation'=>false,
        'machine_failover.allow_candidate_retry'=>false, 'machine_failover.workflow'=>'agent_readiness_v1',
    ]);
    $app->instance(Setting::class, new class extends Setting {
        public function __construct() {}
        public function get(string $key, mixed $default = null): mixed
        {
            return $key === 'app_url' ? 'https://panel.example.test' : $default;
        }
    });
    $app->instance(MachineFailoverExecutionGate::class, new class($owner, $db) extends MachineFailoverExecutionGate {
        public function __construct(private string $owner, private string $file) {}
        public function assertAllowed(string $operation): void
        {
            if (PHP_SAPI !== 'fpm-fcgi') {
                parent::assertAllowed($operation);
            }
            $conn = app('db')->connection();
            if ($conn->getDriverName() !== 'sqlite' || $conn->getDatabaseName() !== $this->file
                || $conn->table('receipt_fixture_owner')->value('owner') !== $this->owner) {
                throw new CloudException('fixture_database_wrong');
            }
            if ($operation === 'usdo1_execution_tick') {
                if ($conn->table('v2_machine_failover_handover_grant')->whereNotNull('revoked_at')->count() === 1
                    && $conn->table('v2_machine_failover_execution')->where('status', 'stopped')->count() === 1
                    && $conn->table('v2_machine_failover_runtime')->where('state', 'stopped')->count() === 1) return;
                parent::assertAllowed($operation);
            }
            if (!in_array($operation, ['usdo1_execution_callback','native_task','context'], true)) parent::assertAllowed($operation);
        }
    });
});

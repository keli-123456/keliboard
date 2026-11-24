<?php

declare(strict_types=1);

use App\Console\Commands\RunUsdo1Worker;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate {
    public function assertAllowed(string $operation): void
    {
        if ($operation !== 'usdo1_execution_tick' || PHP_OS_FAMILY !== 'Linux' || posix_geteuid() === 0
            || getenv('KELI_CI_TIMEOUT_FIXTURE') !== '1') parent::assertAllowed($operation);
        foreach ([AF_INET, AF_INET6] as $family) {
            socket_clear_error();
            if (@socket_create($family, SOCK_STREAM, SOL_TCP) !== false || !in_array(socket_last_error(), [1,97], true))
                throw new RuntimeException('worker_inet_family_not_blocked');
        }
        pcntl_signal(SIGTERM, SIG_IGN);
        file_put_contents('/run/keli-usdo1-launch-ci/worker/started', 'entered_worker', LOCK_EX);
        sleep(30);
        parent::assertAllowed($operation);
    }
});
(new RunUsdo1Worker())->handle();
throw new RuntimeException('worker_returned_before_timeout');

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Tests\Support\NativeFailoverConcurrentFixture as Fixture;

try {
    if ($argc !== 4 || !in_array($argv[3], ['once', 'loop', 'recover', 'stops', 'stop', 'legacy'], true)
        || !preg_match('/^w[a-f0-9]{12}$/D', $argv[2])) throw new RuntimeException('Invalid fixture worker arguments.');
    Fixture::attach($argv[1], $argv[2]);
    if ($argv[3] === 'stop') {
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'v2_machine_failover_runtime_event') && str_contains($query->sql, 'request_key'))
                Fixture::control('stop-prior:' . Fixture::$worker, true, true);
        });
        $input = Fixture::control('stop');
        Fixture::barrier('stop_before');
        app(\App\Services\ServerMachine\Cloud\MachineFailoverRunner::class)->stop($input['id'], $input['revision'], $input['request'], $input['admin']);
        Fixture::event('stop_accepted'); exit(0);
    }
    exit(Fixture::work($argv[3]));
} catch (Throwable $error) {
    try { Fixture::event('worker_error', Fixture::errorCode($error)); } catch (Throwable) {}
    fwrite(STDERR, 'Fixture worker failed: ' . Fixture::errorCode($error) . PHP_EOL); exit(60);
}

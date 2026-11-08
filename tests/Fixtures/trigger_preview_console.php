<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Tests\Support\NativeFailoverConcurrentFixture as Native;
use Tests\Support\TriggerPreviewFixture as Fixture;

define('ARTISAN_BINARY', __FILE__);
try {
    if (isset($argv[1], $argv[2], $argv[3]) && preg_match('/^[a-f0-9]{32}$/D', $argv[1]) && preg_match('/^w[a-f0-9]{12}$/D', $argv[2])) {
        [$owner, $worker, $mode] = array_slice($argv, 1, 3);
        if (!in_array($mode, ['once', 'schedule', 'watch'], true)) throw new RuntimeException('Invalid fixture mode.');
        putenv('KELI_TRIGGER_OWNER=' . $owner);
    } else {
        $owner = (string) getenv('KELI_TRIGGER_OWNER'); $worker = 'w' . bin2hex(random_bytes(6)); $mode = $argv[1] ?? '';
        if (!in_array($mode, ['machines:preview-failover', 'schedule:finish'], true)) throw new RuntimeException('Invalid background fixture command.');
    }
    Fixture::attach($owner, $worker); Native::event('process_started', (string) getmypid());
    Native::barrier('before_collect');
    if ($mode === 'watch') {
        $until = microtime(true) + 125; $minute = '';
        while (microtime(true) < $until) {
            if (gmdate('YmdHi') !== $minute) { $minute = gmdate('YmdHi'); Fixture::run('schedule:run'); }
            usleep(100000);
        }
        exit(0);
    }
    $command = match ($mode) { 'once' => 'machines:preview-failover', 'schedule' => 'schedule:run', default => $mode };
    $parameters = $mode === 'schedule:finish' ? ['id' => $argv[2] ?? '', 'code' => $argv[3] ?? 0] : [];
    exit(Fixture::run($command, $parameters));
} catch (Throwable $error) {
    try { Native::event('process_error', Native::errorCode($error)); } catch (Throwable) {}
    fwrite(STDERR, 'Trigger fixture failed: ' . Native::errorCode($error) . PHP_EOL); exit(60);
}

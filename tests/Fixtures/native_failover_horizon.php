<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Tests\Support\NativeFailoverConcurrentFixture as Fixture;
use Tests\Support\NativeFailoverHorizonFixture as HorizonFixture;

try {
    if ($argc < 3 || !in_array($argv[2], ['horizon', 'horizon:supervisor', 'horizon:work'], true)) throw new RuntimeException('Unsupported isolated Horizon command.');
    HorizonFixture::configuration(getenv(), $argv[1]);
    Fixture::attach($argv[1], 'horizon-' . getmypid()); HorizonFixture::configure();
    Fixture::event($argv[2] === 'horizon:work' ? 'horizon_worker_boot' : ($argv[2] === 'horizon' ? 'horizon_master_boot' : 'horizon_supervisor_boot'), (string) getmypid());
    $classes = ['horizon' => \Laravel\Horizon\Console\HorizonCommand::class,
        'horizon:supervisor' => \Laravel\Horizon\Console\SupervisorCommand::class, 'horizon:work' => \Laravel\Horizon\Console\WorkCommand::class];
    $command = app($classes[$argv[2]]); $command->setLaravel(app());
    exit($command->run(new \Symfony\Component\Console\Input\ArgvInput([__FILE__, ...array_slice($argv, 3)]), new \Symfony\Component\Console\Output\ConsoleOutput()));
} catch (Throwable $error) {
    try { Fixture::event('horizon_error', Fixture::errorCode($error)); } catch (Throwable) {}
    fwrite(STDERR, 'Isolated Horizon failed: ' . Fixture::errorCode($error) . PHP_EOL); exit(60);
}

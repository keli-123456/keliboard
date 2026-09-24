<?php

declare(strict_types=1);

// This dedicated entry is inherited by Horizon's real supervisor and workers.
$horizonArguments = array_slice($argv, 1);
if (PHP_SAPI !== 'cli' || !in_array($horizonArguments[0] ?? '',
    ['horizon', 'horizon:supervisor', 'horizon:work', 'horizon:terminate', 'horizon:status'], true)) {
    throw new RuntimeException('Bounded fixture Horizon command required.');
}
$argv = [__FILE__, 'queue-horizon'];
require __DIR__ . '/traffic_batch_http.php';

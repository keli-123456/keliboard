<?php

declare(strict_types=1);

if (getenv('KELI_OCTANE_FIXTURE') !== 'isolated-ci' || is_file('/www/.env')) exit(3);
require __DIR__ . '/helpers.php';
for ($i = 1; $i <= 480; ++$i) {
    file_put_contents('/state/read-next.json', json_encode(['tick' => $i, 'files' => mountedFiles(),
        'socket_absent' => !is_dir('/run/keli-failover-example'),
        'old_cache_visible' => file_get_contents('/www/bootstrap/cache/shared-anchor') === "old synthetic cache\n"], JSON_THROW_ON_ERROR));
    rename('/state/read-next.json', '/state/read.json');
    sleep(1);
}

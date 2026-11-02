<?php
// This fixture echoes protocol frames only. It never launches a shell.
set_time_limit(3);
$first = json_decode(fgets(STDIN), true);
fwrite(STDOUT, json_encode(['type' => 'ready', 'expires_at' => time() + 60]) . "\n");
fflush(STDOUT);
while (($line = fgets(STDIN)) !== false) {
    $frame = json_decode($line, true);
    if (($frame['type'] ?? '') === 'input') {
        fwrite(STDOUT, json_encode(['type' => 'output', 'data' => $frame['data']]) . "\n");
        fflush(STDOUT);
        break;
    }
}

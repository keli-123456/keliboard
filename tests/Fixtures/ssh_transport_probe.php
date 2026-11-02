<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\Services\ServerMachine\SshTransport;
use App\Services\ServerMachine\SshTerminalException;

$input = json_decode(stream_get_contents(STDIN, 65536), true, 16, JSON_THROW_ON_ERROR);
$ssh = new SshTransport();
try {
    $ssh->open($input['target'], $input['credentials'], 80, 24);
    $ssh->write("echo \xe5\x8f\xb0\xe6\xb9\xbe\r");
    $ssh->resize(120, 40);
    $output = ''; $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline && strlen($output) < 16384) {
        $chunk = $ssh->read();
        if ($chunk === false) break;
        $output .= $chunk;
        if (str_contains($output, 'fixture-ok')) break;
    }
    echo json_encode(['status' => 'connected', 'echo_verified' => str_contains($output, 'fixture-ok'),
        'utf8_verified' => str_contains($output, "\xe5\x8f\xb0\xe6\xb9\xbe"), 'output_bytes' => strlen($output)]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'rejected', 'reason' => $e instanceof SshTerminalException ? $e->getMessage() : 'unexpected_error']);
} finally {
    $ssh->close();
}

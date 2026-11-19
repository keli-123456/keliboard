<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\ExampleRehearsal;

class WorkerSocket
{
    public function send(Grant $grant, string $action): array
    {
        $unavailable = ['error' => 'unavailable'];
        $deadline = hrtime(true) + 16_000_000_000;
        $socket = $this->connect();
        if (!is_resource($socket)) return $unavailable;
        try {
            if (!stream_set_blocking($socket, false)) return $unavailable;
            $pending = Wire::request($grant, $action);
            $reply = '';
            while (true) {
                $remaining = (int) ceil(($deadline - hrtime(true)) / 1000);
                if ($remaining <= 0) return $unavailable;
                $read = $pending === '' ? [$socket] : [];
                $write = $pending === '' ? [] : [$socket];
                $except = [];
                if (@stream_select($read, $write, $except, intdiv($remaining, 1_000_000), $remaining % 1_000_000) !== 1) return $unavailable;
                if ($write) {
                    $sent = @fwrite($socket, $pending);
                    if ($sent === false || $sent === 0) return $unavailable;
                    $pending = substr($pending, $sent);
                    continue;
                }
                $chunk = @fread($socket, Wire::MAX_REPLY + 1 - strlen($reply));
                if ($chunk === false || ($chunk === '' && feof($socket))) return $unavailable;
                $reply .= $chunk;
                if (strlen($reply) > Wire::MAX_REPLY) return $unavailable;
                if (str_contains($reply, "\n")) return Wire::checked(json_decode($reply, true, 8)) ?? $unavailable;
            }
        } finally {
            fclose($socket);
        }
    }

    protected function connect()
    {
        // This endpoint is fixed; never fall back to TCP or an address from the request.
        return @stream_socket_client('unix://' . Wire::SOCKET, $errno, $error, 0.5);
    }
}

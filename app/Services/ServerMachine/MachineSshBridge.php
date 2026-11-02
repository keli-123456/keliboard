<?php

namespace App\Services\ServerMachine;

use App\Console\Commands\MachineSshSession;
use Workerman\Timer;

class MachineSshBridge
{
    private array $sessions = [];
    private ?int $timer = null;

    public function __construct(private MachineSshService $service) {}

    public function start(): void
    {
        if ($this->timer !== null) return;
        $this->timer = Timer::add(0.05, fn () => $this->tick());
    }

    protected function spawn(array &$pipes)
    {
        return proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'display_errors=0', '-d', 'log_errors=0',
            base_path('artisan'), 'machine:ssh-session'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
    }

    public function connect(object $connection, object $request): bool
    {
        if ($request->get('channel') !== 'admin-ssh') return false;
        $connection->machine_ssh = true;
        $origin = (string) $request->header('origin', '');
        if (!$this->service->available() || count($this->sessions) >= 64 || strlen($origin) > 512 || !str_starts_with($origin, 'https://')) {
            $connection->close(); return true;
        }
        $connection->maxPackageSize = 16384;
        $connection->maxSendBufferSize = 1048576;
        $this->sessions[$connection->id] = ['connection' => $connection, 'origin' => $origin, 'created' => microtime(true),
            'process' => null, 'pipes' => [], 'read' => '', 'write' => '', 'frames' => 0, 'window' => time(), 'lease' => null];
        return true;
    }

    public function message(object $connection, string $message): void
    {
        $id = $connection->id;
        if (!isset($this->sessions[$id])) { $connection->close(); return; }
        try {
            $state = &$this->sessions[$id];
            if ($state['window'] !== time()) { $state['window'] = time(); $state['frames'] = 0; }
            if (strlen($message) > 16384 || ++$state['frames'] > 60) throw new SshTerminalException('input_limit');
            $frame = json_decode($message, true, 8, JSON_THROW_ON_ERROR);
            if (!$state['process']) {
                if (($frame['type'] ?? '') !== 'connect') throw new SshTerminalException('ticket_expired');
                [$cols, $rows] = MachineSshSession::dimensions($frame);
                // Authenticate before spawning. Node credentials cannot admit a shell.
                $record = $this->service->admit((string) ($frame['ticket'] ?? ''), $state['origin']);
                if (count(array_filter($this->sessions, fn ($s) => $s['process'] !== null)) >= 4) throw new SshTerminalException('session_busy');
                $state['lease'] = array_intersect_key($record, array_flip(['admin_id', 'lease_owner']));
                unset($record);
                $pipes = [];
                $state['process'] = $this->spawn($pipes);
                if (!is_resource($state['process'])) { $state['process'] = null; throw new SshTerminalException('unavailable'); }
                foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
                $state['pipes'] = $pipes;
                $state['write'] = json_encode(['ticket' => $frame['ticket'], 'origin' => $state['origin'], 'cols' => $cols, 'rows' => $rows], JSON_THROW_ON_ERROR) . "\n";
                $state['created'] = microtime(true);
                return;
            }
            if (!in_array($frame['type'] ?? '', ['input', 'resize', 'ping', 'disconnect'], true)) throw new SshTerminalException('invalid_frame');
            if ($frame['type'] === 'disconnect') { $this->close($connection); $connection->close(); return; }
            if (strlen($state['write']) + strlen($message) > 65536) throw new SshTerminalException('input_limit');
            $state['write'] .= $message . "\n";
        } catch (\Throwable $e) {
            $this->fail($connection, $e instanceof SshTerminalException ? $e->getMessage() : 'connection_failed');
        }
    }

    private function fail(object $connection, string $code): void
    {
        $connection->send(json_encode(['type' => 'error', 'code' => $code]));
        $this->close($connection);
        $connection->close();
    }

    public function tick(): void
    {
        foreach (array_keys($this->sessions) as $id) {
            if (!isset($this->sessions[$id])) continue;
            $state = &$this->sessions[$id]; $connection = $state['connection'];
            try {
                if (!$state['process']) {
                    if (microtime(true) - $state['created'] > 5) $this->fail($connection, 'ticket_expired');
                    continue;
                }
                if (microtime(true) - $state['created'] > 1220) throw new SshTerminalException('session_timeout');
                if ($state['write'] !== '') {
                    $sent = @fwrite($state['pipes'][0], $state['write']);
                    if ($sent === false) throw new SshTerminalException('disconnected');
                    $state['write'] = substr($state['write'], $sent);
                }
                // stderr is deliberately discarded; only the worker's bounded,
                // structured stdout protocol can reach the browser.
                @fread($state['pipes'][2], 16384);
                $output = @fread($state['pipes'][1], 65536);
                if ($output !== false) $state['read'] .= $output;
                if (strlen($state['read']) > 131072 || $connection->getSendBufferQueueSize() > 524288) throw new SshTerminalException('output_limit');
                while (($end = strpos($state['read'], "\n")) !== false) {
                    $line = substr($state['read'], 0, $end); $state['read'] = substr($state['read'], $end + 1);
                    $frame = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                    if (!in_array($frame['type'] ?? '', ['ready', 'output', 'error', 'pong'], true)) throw new SshTerminalException('connection_failed');
                    $connection->send($line);
                }
                if (!proc_get_status($state['process'])['running']) {
                    $this->close($connection); $connection->close();
                }
            } catch (\Throwable $e) {
                $this->fail($connection, $e instanceof SshTerminalException ? $e->getMessage() : 'connection_failed');
            }
            unset($state);
        }
    }

    public function close(object $connection): void
    {
        $state = $this->sessions[$connection->id] ?? null;
        unset($this->sessions[$connection->id]);
        if (!$state) return;
        foreach ($state['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($state['process'])) {
            if (proc_get_status($state['process'])['running']) proc_terminate($state['process'], 9);
            proc_close($state['process']);
        }
        if ($state['lease']) { try { $this->service->release($state['lease']); } catch (\Throwable) {} }
    }

    public function shutdown(): void
    {
        foreach ($this->sessions as $state) $this->close($state['connection']);
        if ($this->timer !== null) Timer::del($this->timer);
        $this->timer = null;
    }
}

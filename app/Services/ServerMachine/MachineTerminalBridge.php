<?php

namespace App\Services\ServerMachine;

use App\Console\Commands\MachineSshSession;
use Illuminate\Support\Facades\Log;
use Workerman\Timer;

class MachineTerminalBridge
{
    private array $peers = [];
    private array $sessions = [];
    private ?int $timer = null;
    private $resolveControl;

    public function __construct(private MachineTerminalService $service) {}
    public function controls(callable $resolver): void { $this->resolveControl = $resolver; }
    protected function ensureTimer(): void { if ($this->timer === null) $this->timer = Timer::add(1, fn () => $this->tick()); }
    protected function now(): float { return microtime(true); }

    public function connect(object $connection, object $request): bool
    {
        $channel = $request->get('channel');
        if (!in_array($channel, ['admin-terminal', 'agent-terminal'], true)) return false;
        $connection->agent_terminal = true;
        $origin = (string) $request->header('origin', '');
        if (!$this->service->enabled() || count($this->peers) >= 32 || ($channel === 'admin-terminal'
            && (strlen($origin) > 512 || !str_starts_with($origin, 'https://')))) {
            $connection->send("HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", true);
            $connection->close(); return true;
        }
        $connection->maxPackageSize = 16384; $connection->maxSendBufferSize = 131072;
        $this->peers[$connection->id] = ['connection' => $connection, 'role' => $channel === 'admin-terminal' ? 'browser' : 'agent',
            'origin' => $origin, 'created' => $this->now(), 'window' => time(), 'frames' => 0, 'bytes' => 0, 'browser' => null];
        $this->ensureTimer();
        return true;
    }

    public function message(object $connection, string $message): void
    {
        $id = $connection->id;
        if (!isset($this->peers[$id])) { $connection->close(); return; }
        try {
            $peer = &$this->peers[$id];
            if ($peer['window'] !== time()) { $peer['window'] = time(); $peer['frames'] = $peer['bytes'] = 0; }
            $peer['bytes'] += strlen($message);
            if (strlen($message) > 16384 || ++$peer['frames'] > 60 || $peer['bytes'] > 98304) throw new SshTerminalException('input_limit');
            $frame = json_decode($message, true, 8, JSON_THROW_ON_ERROR);
            if ($peer['browser'] === null) {
                if ($peer['role'] === 'browser') $this->admitBrowser($id, $frame);
                else $this->admitAgent($id, $frame);
                return;
            }
            $bid = $peer['browser']; $state = &$this->sessions[$bid];
            if (($frame['type'] ?? '') === 'disconnect') { $this->closeSession($bid); return; }
            if ($peer['role'] === 'browser') {
                $state['last_browser'] = $this->now();
                if (($frame['type'] ?? '') === 'ping') { $this->send($id, ['type' => 'pong']); return; }
                if (!$state['ready'] || !in_array($frame['type'] ?? '', ['input', 'resize'], true)) throw new SshTerminalException('invalid_frame');
                if ($frame['type'] === 'resize') MachineSshSession::dimensions($frame);
                else {
                    $input = is_string($frame['data'] ?? null) ? base64_decode($frame['data'], true) : false;
                    if ($input === false || strlen($input) > 8192) throw new SshTerminalException('input_limit');
                    if ($input !== '') $state['last_input'] = $this->now();
                }
                $this->send($state['agent'], array_intersect_key($frame, array_flip(['type','data','cols','rows'])));
            } else {
                if (($frame['type'] ?? '') === 'ready' && !$state['ready']) {
                    $state['ready'] = true;
                    $this->send($bid, ['type' => 'ready', 'expires_at' => $state['expires_at']]);
                    Log::info('Agent terminal opened', ['admin_id' => $state['record']['admin_id'], 'machine_id' => $state['record']['machine_id']]);
                } elseif (($frame['type'] ?? '') === 'output' && $state['ready']) {
                    $data = is_string($frame['data'] ?? null) ? base64_decode($frame['data'], true) : false;
                    if ($data === false || strlen($data) > 8192) throw new SshTerminalException('output_limit');
                    $this->send($bid, ['type' => 'output', 'data' => $frame['data']]);
                } else throw new SshTerminalException('invalid_frame');
            }
        } catch (\Throwable $e) {
            $bid = $this->peers[$id]['browser'] ?? null;
            $code = $e instanceof SshTerminalException ? $e->getMessage() : 'connection_failed';
            if ($bid !== null) $this->closeSession($bid, $code);
            else { $this->close($connection); $connection->close(); }
        }
    }

    private function admitBrowser(int $id, array $frame): void
    {
        if (($frame['type'] ?? '') !== 'connect' || count($this->sessions) >= 4) throw new SshTerminalException('session_busy');
        [$cols, $rows] = MachineSshSession::dimensions($frame);
        $record = $this->service->consume((string) ($frame['ticket'] ?? ''), $this->peers[$id]['origin']);
        $control = $this->resolveControl ? ($this->resolveControl)($record['machine_id']) : null;
        if (!$control) throw new SshTerminalException('unavailable');
        $sid = bin2hex(random_bytes(32)); $capability = bin2hex(random_bytes(32));
        $this->service->acquire($record, $sid);
        $this->sessions[$id] = ['record' => $record, 'sid' => $sid, 'capability' => $capability, 'control' => $control->id,
            'agent' => null, 'ready' => false, 'created' => $this->now(), 'last_input' => $this->now(),
            'last_check' => 0, 'last_browser' => $this->now(), 'expires_at' => time() + 1200, 'cols' => $cols, 'rows' => $rows];
        $this->peers[$id]['browser'] = $id;
        if ($control->send(json_encode(['type' => 'terminal_open', 'machine_id' => $record['machine_id'],
            'terminal' => ['session_id' => $sid, 'capability' => $capability, 'expires_at' => time() + 30]])) === false) {
            throw new SshTerminalException('unavailable');
        }
    }

    private function admitAgent(int $id, array $frame): void
    {
        if (($frame['type'] ?? '') !== 'agent_connect' || !is_string($frame['session_id'] ?? null) || !is_string($frame['capability'] ?? null)) throw new SshTerminalException('ticket_expired');
        foreach ($this->sessions as $bid => &$state) {
            if ($state['sid'] !== $frame['session_id']) continue;
            if ($state['agent'] !== null || $this->now() - $state['created'] > 30 || !hash_equals($state['capability'], $frame['capability'])
                || !$this->service->authenticateAgent($state['record'], $frame)) throw new SshTerminalException('ticket_expired');
            $state['agent'] = $id; $state['capability'] = '';
            $this->peers[$id]['browser'] = $bid;
            $this->send($id, ['type' => 'start', 'cols' => $state['cols'], 'rows' => $state['rows']]);
            $this->send($id, ['type' => 'lease']);
            return;
        }
        throw new SshTerminalException('ticket_expired');
    }

    private function send(int $id, array $frame): void
    {
        $connection = $this->peers[$id]['connection'] ?? null;
        if (!$connection || $connection->getSendBufferQueueSize() > 65536 || $connection->send(json_encode($frame, JSON_THROW_ON_ERROR)) === false) throw new SshTerminalException('output_limit');
    }

    public function tick(): void
    {
        foreach (array_keys($this->peers) as $id) {
            if (isset($this->peers[$id]) && $this->peers[$id]['browser'] === null && $this->now() - $this->peers[$id]['created'] > 5) {
                $connection = $this->peers[$id]['connection']; $this->close($connection); $connection->close();
            }
        }
        foreach (array_keys($this->sessions) as $bid) {
            try {
                $state = &$this->sessions[$bid];
                if ((!$state['ready'] && $this->now() - $state['created'] > 30) || time() >= $state['expires_at']
                    || $this->now() - $state['last_input'] > 600 || $this->now() - $state['last_browser'] > 45) throw new SshTerminalException('session_timeout');
                if ($this->now() - $state['last_check'] >= 5) {
                    $this->service->authorize($state['record']);
                    if (!$this->resolveControl || !($control = ($this->resolveControl)($state['record']['machine_id'])) || $control->id !== $state['control']) throw new SshTerminalException('session_revoked');
                    if ($state['agent'] !== null) $this->send($state['agent'], ['type' => 'lease']);
                    $state['last_check'] = $this->now();
                }
            } catch (\Throwable $e) { $this->closeSession($bid, $e instanceof SshTerminalException ? $e->getMessage() : 'connection_failed'); }
            unset($state);
        }
        if ($this->peers === [] && $this->timer !== null) { Timer::del($this->timer); $this->timer = null; }
    }

    public function controlClosed(object $control): void
    {
        foreach ($this->sessions as $bid => $state) if ($state['control'] === $control->id) $this->closeSession($bid, 'disconnected');
    }

    private function closeSession(int $bid, ?string $code = null): void
    {
        $state = $this->sessions[$bid] ?? null;
        unset($this->sessions[$bid]);
        if (!$state) return;
        foreach (array_filter([$bid, $state['agent']], fn ($id) => $id !== null) as $id) {
            $connection = $this->peers[$id]['connection'] ?? null; unset($this->peers[$id]);
            if (!$connection) continue;
            try { if ($id === $bid && $code) $connection->send(json_encode(['type' => 'error', 'code' => $code])); $connection->close(); } catch (\Throwable) {}
        }
        try { $this->service->release($state['record'], $state['sid']); } catch (\Throwable) {}
        try { Log::info('Agent terminal closed', ['admin_id' => $state['record']['admin_id'], 'machine_id' => $state['record']['machine_id'], 'reason' => $code ?? 'disconnected']); } catch (\Throwable) {}
    }

    public function close(object $connection): void
    {
        $bid = $this->peers[$connection->id]['browser'] ?? null;
        unset($this->peers[$connection->id]);
        if ($bid !== null) $this->closeSession($bid);
    }

    public function shutdown(): void
    {
        foreach (array_keys($this->sessions) as $bid) $this->closeSession($bid, 'disconnected');
        foreach ($this->peers as $peer) $peer['connection']->close();
        $this->peers = [];
        if ($this->timer !== null) Timer::del($this->timer);
        $this->timer = null;
    }
}

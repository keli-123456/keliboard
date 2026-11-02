<?php

namespace App\Console\Commands;

use App\Services\ServerMachine\MachineSshService;
use App\Services\ServerMachine\SshTarget;
use App\Services\ServerMachine\SshTerminalException;
use App\Services\ServerMachine\SshTransport;
use App\Models\ServerMachine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MachineSshSession extends Command
{
    protected $signature = 'machine:ssh-session';
    protected $description = 'Private stdio worker for one authorized browser SSH session';

    public static function dimensions(array $frame): array
    {
        $cols = $frame['cols'] ?? 80; $rows = $frame['rows'] ?? 24;
        if (!is_int($cols) || !is_int($rows) || $cols < 10 || $cols > 500 || $rows < 2 || $rows > 200) {
            throw new SshTerminalException('invalid_frame');
        }
        return [$cols, $rows];
    }

    private function emit(array $frame): void
    {
        if (@fwrite(STDOUT, json_encode($frame, JSON_THROW_ON_ERROR) . "\n") === false) throw new SshTerminalException('disconnected');
        fflush(STDOUT);
    }

    public function handle(MachineSshService $service, SshTransport $ssh): int
    {
        $record = null; $acquired = false; $reason = 'disconnected';
        try {
            $first = fgets(STDIN, 8193);
            if (!$first || strlen($first) > 8192 || !str_ends_with($first, "\n")) throw new SshTerminalException('invalid_frame');
            $start = json_decode($first, true, 16, JSON_THROW_ON_ERROR);
            [$cols, $rows] = self::dimensions($start);
            $record = $service->inspect((string) ($start['ticket'] ?? ''), (string) ($start['origin'] ?? ''), true);
            $service->acquire($record); $acquired = true;
            $ssh->open($record['target'], $record['credentials'], $cols, $rows);
            unset($record['credentials'], $start, $first);
            $began = $lastInput = $lastCheck = microtime(true);
            $limit = min(1200, max(60, (int) config('ssh_terminal.session_seconds', 1200)));
            $idle = min(600, max(30, (int) config('ssh_terminal.idle_seconds', 600)));
            $this->emit(['type' => 'ready', 'expires_at' => time() + $limit]);
            Log::info('SSH terminal opened', ['admin_id' => $record['admin_id'], 'machine_id' => $record['machine_id']]);
            stream_set_blocking(STDIN, false);
            $buffer = ''; $outputBytes = 0; $window = time();
            while (!feof(STDIN)) {
                $now = microtime(true);
                if ($now - $began >= $limit || $now - $lastInput >= $idle) throw new SshTerminalException('session_timeout');
                if ($now - $lastCheck >= 5) {
                    $service->authorizeSession($record);
                    $machine = ServerMachine::find($record['machine_id']);
                    if (!$machine || !hash_equals($record['profile_hash'], SshTarget::hash((array) $machine->ssh_profile))) throw new SshTerminalException('profile_changed');
                    $lastCheck = $now;
                }
                $input = @fread(STDIN, 32768);
                if ($input !== false && $input !== '') $buffer .= $input;
                if (strlen($buffer) > 65536) throw new SshTerminalException('input_limit');
                $processed = 0;
                while (($end = strpos($buffer, "\n")) !== false && ++$processed <= 50) {
                    $line = substr($buffer, 0, $end); $buffer = substr($buffer, $end + 1);
                    if (strlen($line) > 16384) throw new SshTerminalException('input_limit');
                    $frame = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                    switch ($frame['type'] ?? '') {
                        case 'input':
                            $data = base64_decode((string) ($frame['data'] ?? ''), true);
                            if ($data === false || strlen($data) > 8192) throw new SshTerminalException('input_limit');
                            if ($data !== '') { $ssh->write($data); $lastInput = microtime(true); }
                            break;
                        case 'resize': [$cols, $rows] = self::dimensions($frame); $ssh->resize($cols, $rows); break;
                        case 'ping': $this->emit(['type' => 'pong']); break;
                        case 'disconnect': break 3;
                        default: throw new SshTerminalException('invalid_frame');
                    }
                }
                $output = $ssh->read();
                if ($output === false) break;
                if ($output !== '') {
                    if (time() !== $window) { $outputBytes = 0; $window = time(); }
                    $outputBytes += strlen($output);
                    if ($outputBytes > 1048576) throw new SshTerminalException('output_limit');
                    foreach (str_split($output, 32768) as $chunk) $this->emit(['type' => 'output', 'data' => base64_encode($chunk)]);
                }
                usleep(5000);
            }
        } catch (\Throwable $e) {
            $reason = $e instanceof SshTerminalException ? $e->getMessage() : 'connection_failed';
            try { $this->emit(['type' => 'error', 'code' => $reason]); } catch (\Throwable) {}
        } finally {
            try { $ssh->close(); } catch (\Throwable) {}
            if ($record && $acquired) {
                try { $service->release($record); } catch (\Throwable) {}
                Log::info('SSH terminal closed', ['admin_id' => $record['admin_id'], 'machine_id' => $record['machine_id'], 'reason' => $reason]);
            }
        }
        return self::SUCCESS;
    }
}

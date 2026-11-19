<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\ExampleRehearsal;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ScratchRound
{
    private string $prefix;

    public function __construct(private string $root, string $directory, private Grant $grant,
        private ?Closure $clock = null, private ?Closure $ticks = null)
    {
        $path = realpath($directory);
        $project = realpath($root);
        if (!$project || !$path || !is_dir($path) || $directory === '' || is_link($directory)
            || str_starts_with(strtolower(str_replace('\\', '/', $path)) . '/', strtolower(str_replace('\\', '/', $project)) . '/'))
            throw new RuntimeException('invalid_state_directory');
        $this->prefix = $path . DIRECTORY_SEPARATOR . 'rehearsal-' . $grant->id;
    }

    public function handle(string $action): array
    {
        if (!in_array($action, ['status', ...Wire::ACTIONS], true) || $this->now() < $this->grant->opens)
            return ['error' => 'closed'];
        if ($action === 'stop') $this->seal('operator_stop');
        $lock = $this->prefix . '.lock';
        if (is_link($lock) || (file_exists($lock) && !is_file($lock))) return ['error' => 'unavailable'];
        $handle = @fopen($lock, 'c+b');
        if (!is_resource($handle)) return ['error' => 'unavailable'];
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) return $action === 'stop'
                ? Wire::report('stopping', 'operator_stop', false, false)
                : ['error' => 'busy'];
            return $this->locked($action);
        } finally {
            fclose($handle);
        }
    }

    private function locked(string $action): array
    {
        $row = null;
        $discard = null;
        try {
            $database = $this->prefix . '.sqlite3';
            $dnsDatabase = $this->prefix . '.dns.sqlite3';
            foreach ([$database, $dnsDatabase] as $file)
                if (is_link($file) || (file_exists($file) && !is_file($file))) throw new RuntimeException('invalid_database_file');
            $existing = is_file($database);
            if ($existing) {
                $fixture = new Fixture($this->root, $database, $this->grant);
                $row = $fixture->state($this->grant);
            }
            if (is_file($dnsDatabase)) {
                if (!$row?->v2_finished_at) throw new RuntimeException('invalid_stage_origin');
                DB::disconnect();
                $dnsState = (new Fixture($this->root, $dnsDatabase, $this->grant))->state($this->grant);
                if (!$dnsState->dns_finished_at || $dnsState->v2_finished_at !== $row->v2_finished_at
                    || !hash_equals($row->control_hash, $dnsState->control_hash)) throw new RuntimeException('incomplete_stage');
                return $this->report($dnsState);
            }
            $state = $this->report($row);
            if (!in_array($action, ['v2', 'dns'], true) || !in_array($state['phase'], ['ready', 'v2_passed'], true)) return $state;
            if ($action === 'v2' && $state['phase'] === 'v2_passed') return $state;
            if ($action === 'dns' && $state['phase'] !== 'v2_passed') return ['error' => 'phase_conflict'];

            $deadline = $this->elapsed() + 12_000_000_000;
            $guard = fn () => $this->check($deadline);
            $guard();
            if (!$existing) {
                $dbFile = @fopen($database, 'x+b');
                if (!is_resource($dbFile)) throw new RuntimeException('cannot_create_database');
                fclose($dbFile);
                $fixture = new Fixture($this->root, $database, $this->grant);
                DB::transaction(fn () => $fixture->seed($this->grant, $guard));
                $row = $fixture->state($this->grant);
            }
            $guard();
            $intent = substr(hash('sha256', $this->grant->fingerprint() . ':' . $action), 0, 32);
            if ($action === 'dns') {
                // Let the existing dispatcher commit its intent before simulated I/O. Publish only a verified copy.
                DB::disconnect();
                $discard = $dnsDatabase;
                if (!copy($database, $dnsDatabase)) throw new RuntimeException('cannot_copy_stage');
                $fixture = new Fixture($this->root, $dnsDatabase, $this->grant);
                $guard();
                $fixture->dns($row, $intent, $guard);
            }
            DB::transaction(function () use ($action, $fixture, $row, $intent, $guard) {
                if ($action === 'v2') $fixture->v2($row, $intent, $guard);
                $guard();
                DB::table('example_round')->where('id', 1)->update([$action . '_finished_at' => $this->now()]);
                $guard();
            });
            $discard = null;
            return $this->report($fixture->state($this->grant));
        } catch (Throwable $error) {
            if ($discard !== null) {
                DB::disconnect();
                if (is_file($discard)) @unlink($discard);
            }
            $reason = $error instanceof StopRequested ? $error->getMessage() : 'verification_failed';
            $this->seal($reason);
            return $this->report($row);
        }
    }

    private function report(?object $row): array
    {
        $v2 = isset($row->v2_finished_at);
        $dns = isset($row->dns_finished_at);
        if ($dns) return Wire::report('passed', null, true, true);
        if ($reason = $this->reason()) return Wire::report($reason === 'verification_failed' ? 'failed' : 'stopped', $reason, $v2, false);
        return Wire::report($v2 ? 'v2_passed' : 'ready', null, $v2, false);
    }

    private function reason(): ?string
    {
        foreach (Wire::REASONS as $reason) {
            $file = $this->prefix . '.' . $reason;
            clearstatcache(true, $file);
            if (is_file($file)) return $reason;
        }
        return $this->now() >= $this->grant->expires ? 'expired' : null;
    }

    private function seal(string $reason): void
    {
        $file = $this->prefix . '.' . $reason;
        $handle = @fopen($file, 'xb');
        if (is_resource($handle)) fclose($handle);
        elseif (!is_file($file) || is_link($file)) throw new RuntimeException('cannot_seal_preview');
    }

    private function check(int $deadline): void
    {
        $reason = $this->reason() ?? ($this->elapsed() >= $deadline ? 'timeout' : null);
        if ($reason) throw new StopRequested($reason);
    }

    private function now(): int { return $this->clock ? ($this->clock)() : time(); }
    private function elapsed(): int { return $this->ticks ? ($this->ticks)() : hrtime(true); }
}

final class StopRequested extends RuntimeException {}

<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\TimeoutExceededException;
use RuntimeException;
use Throwable;

final class MachineFailoverOperatorFailureJournal
{
    private const LIMIT = 128;
    private const MAX_BYTES = 262144;
    private array $state = ['schema' => 1, 'active' => null, 'failures' => [], 'worker_error' => null];
    private $lock = null;

    public function __construct(private readonly string $directory)
    {
        if (realpath(dirname($directory)) !== dirname($directory)) throw new RuntimeException('operator_journal_parent_invalid');
        $this->verifyPath($directory, true);
        $path = $directory . '/worker.lock';
        $this->lock = $this->openPrivate($path, 'c+b');
        try {
            $this->verifyPath($path);
            $stat = fstat($this->lock); $current = lstat($path);
            if (!$stat || !$current || $stat['ino'] !== $current['ino'] || $stat['dev'] !== $current['dev']
                || !flock($this->lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('operator_journal_lock_unavailable');
            $this->load();
            if ($this->state['active'] !== null) $this->archiveActive('worker_interrupted');
            $this->requireCapacity();
            $this->save();
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function close(): void
    {
        if (is_resource($this->lock)) fclose($this->lock);
        $this->lock = null;
    }

    public function begin(Job $job): void
    {
        if ($this->state['active'] !== null) throw new RuntimeException('operator_journal_active_exists');
        $this->requireCapacity();
        $this->state['active'] = ['delivery' => $this->delivery($job), 'started_at' => time()];
        $this->save();
    }

    public function failed(Job $job, ?Throwable $error = null, bool $timedOut = false): void
    {
        if ($this->state['active'] === null) return;
        if ($this->state['active']['delivery'] !== $this->delivery($job)) throw new RuntimeException('operator_journal_delivery_mismatch');
        $this->archiveActive($timedOut || $error instanceof TimeoutExceededException ? 'job_timeout' : 'job_failed', $error);
    }

    public function completed(Job $job): void
    {
        if ($this->state['active'] === null) return;
        if ($this->state['active']['delivery'] !== $this->delivery($job)) throw new RuntimeException('operator_journal_delivery_mismatch');
        $this->state['active'] = null;
        $this->save();
    }

    public function workerError(Throwable $error): void
    {
        if ($this->state['worker_error'] !== null) return;
        $this->state['worker_error'] = ['reason' => 'worker_error', 'at' => time()] + $this->diagnostic($error);
        $this->save();
    }

    private function archiveActive(string $reason, ?Throwable $error = null): void
    {
        $active = $this->state['active'];
        $key = $active['delivery'];
        if (!isset($this->state['failures'][$key])) {
            $this->requireCapacity();
            $this->state['failures'][$key] = $active + ['reason' => $reason, 'at' => time()] + $this->diagnostic($error);
        }
        $this->state['active'] = null;
        $this->save();
    }

    private function delivery(Job $job): string
    {
        $id = $job->uuid();
        if (!is_string($id) || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di', $id))
            throw new RuntimeException('operator_journal_delivery_invalid');
        return hash('sha256', strtolower($id) . ':' . (int) $job->attempts());
    }

    private function diagnostic(?Throwable $error): array
    {
        if ($error === null) return [];
        $class = $error::class;
        $file = str_replace('\\', '/', $error->getFile());
        $root = str_replace('\\', '/', base_path()) . '/';
        $safe = ['exception' => preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,159}\z/', $class) ? $class : 'Throwable'];
        $relative = str_starts_with($file, $root) ? substr($file, strlen($root)) : '';
        if (preg_match('~\Aapp/(?:[A-Za-z0-9_]+/)*[A-Za-z0-9_]+\.php\z~', $relative))
            $safe += ['file' => $relative, 'line' => $error->getLine()];
        return $safe;
    }

    public function requireCapacity(): void
    {
        if (count($this->state['failures']) >= self::LIMIT) throw new RuntimeException('operator_journal_full');
    }

    private function load(): void
    {
        $path = $this->directory . '/failure-state.json';
        if (!file_exists($path) && !is_link($path)) return;
        $this->verifyPath($path);
        $raw = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_BYTES) throw new RuntimeException('operator_journal_unreadable');
        $state = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
        if (!is_array($state) || array_keys($state) !== array_keys($this->state) || $state['schema'] !== 1 || !is_array($state['failures'])
            || count($state['failures']) > self::LIMIT || ($state['worker_error'] !== null && !is_array($state['worker_error'])))
            throw new RuntimeException('operator_journal_format_invalid');
        $active = $state['active'];
        if ($active !== null && (!is_array($active) || array_keys($active) !== ['delivery', 'started_at']
            || !is_string($active['delivery']) || !preg_match('/\A[a-f0-9]{64}\z/', $active['delivery']) || !is_int($active['started_at'])))
            throw new RuntimeException('operator_journal_active_invalid');
        $this->state = $state;
    }

    private function save(): void
    {
        if (!is_resource($this->lock)) throw new RuntimeException('operator_journal_not_locked');
        $dest = $this->directory . '/failure-state.json';
        if (file_exists($dest) || is_link($dest)) $this->verifyPath($dest);
        $temp = $this->directory . '/.failure-' . bin2hex(random_bytes(12));
        $handle = $this->openPrivate($temp, 'xb');
        try {
            $data = json_encode($this->state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (strlen($data) > self::MAX_BYTES || fwrite($handle, $data) !== strlen($data) || !fflush($handle) || !fsync($handle))
                throw new RuntimeException('operator_journal_write_failed');
            fclose($handle);
            if (!@rename($temp, $dest)) throw new RuntimeException('operator_journal_commit_failed');
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temp)) unlink($temp);
        }
    }

    private function openPrivate(string $path, string $mode)
    {
        if (is_link($path)) throw new RuntimeException('operator_journal_unsafe_link');
        $mask = umask(0077);
        try { $handle = @fopen($path, $mode); }
        finally { umask($mask); }
        if (!$handle) throw new RuntimeException('operator_journal_open_failed');
        return $handle;
    }

    private function verifyPath(string $path, bool $directory = false): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!$stat || is_link($path) || ($directory ? !is_dir($path) : (!is_file($path) || $stat['nlink'] !== 1))
            || (PHP_OS_FAMILY !== 'Windows' && (($stat['mode'] & 0077) !== 0
                || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()))))
            throw new RuntimeException('operator_journal_path_permission_invalid');
    }
}

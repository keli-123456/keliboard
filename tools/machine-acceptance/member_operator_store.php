<?php
declare(strict_types=1);

namespace Keli\Acceptance;

/** Private local journals shared by the separate creation and cleanup entries. */
final class MemberOperatorStore
{
    private $lock;

    private static function need(bool $value, string $code): void
    {
        if (!$value) throw new \RuntimeException('member_operator_' . $code);
    }

    public function __construct(private string $directory, private string $mode)
    {
        self::need(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid'), 'linux_cli_required');
        self::need(in_array($mode, ['creation', 'cleanup'], true), 'journal_mode');
        $this->checkDirectory();
        $path = $directory . '/operator.lock';
        clearstatcache(true, $path); $new = !file_exists($path) && !is_link($path);
        $mask = umask(0077);
        try { $lock = @fopen($path, $new ? 'x+b' : 'r+b'); } finally { umask($mask); }
        try {
            self::need($lock !== false, 'lock_invalid');
            $this->checkFile($path, 0);
            $a = fstat($lock); $b = lstat($path);
            self::need($a['ino'] === $b['ino'] && $a['dev'] === $b['dev'] && flock($lock, LOCK_EX | LOCK_NB), 'busy');
            $this->lock = $lock;
        } catch (\Throwable $error) {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            throw $error;
        }
    }

    private function checkDirectory(): void
    {
        clearstatcache(true, $this->directory); $stat = @lstat($this->directory);
        self::need($stat && realpath($this->directory) === $this->directory && is_dir($this->directory)
            && $stat['uid'] === posix_geteuid() && ($stat['mode'] & 0777) === 0700, 'directory_invalid');
    }

    private function checkFile(string $path, int $maximum = 262144): array
    {
        $this->checkDirectory(); clearstatcache(true, $path); $stat = @lstat($path);
        self::need($stat && realpath($path) === $path && is_file($path) && $stat['uid'] === posix_geteuid()
            && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0600 && $stat['size'] <= $maximum, 'file_invalid');
        return $stat;
    }

    public function assertOpen(): void { self::need(is_resource($this->lock), 'closed'); }

    public function read(string $name): string
    {
        $this->assertOpen();
        self::need(preg_match('/^[a-z0-9][a-z0-9-]{0,100}\.json$/D', $name) === 1, 'journal_key');
        $path = $this->directory . '/' . $name; $stat = $this->checkFile($path);
        $stream = fopen($path, 'rb'); self::need(is_resource($stream), 'file_invalid');
        try {
            $opened = fstat($stream);
            self::need($stat['dev'] === $opened['dev'] && $stat['ino'] === $opened['ino'], 'file_changed');
            $raw = stream_get_contents($stream, 262145);
            self::need(is_string($raw) && strlen($raw) === $stat['size'], 'file_changed');
            $after = $this->checkFile($path);
            foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key)
                self::need($after[$key] === $stat[$key], 'file_changed');
            return $raw;
        } finally { fclose($stream); }
    }

    public function load(string $key): ?array
    {
        $this->assertOpen();
        $pattern = $this->mode === 'cleanup' ? '/^(binding|first-error|provider-delete-[a-f0-9]{64})$/D'
            : '/^(binding|first-error|control-creation|control-bound-request)$/D';
        self::need(preg_match($pattern, $key) === 1, 'journal_key');
        $name = 'journal-' . $key . '.json'; $path = $this->directory . '/' . $name;
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) return null;
        $value = json_decode($this->read($name), true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($value), 'journal_invalid');
        return $value;
    }

    public function save(string $key, array $value): void
    {
        $prior = $this->load($key);
        if ($prior !== null) {
            if ($key === 'first-error' || $prior === $value) return;
            self::need($this->mode === 'creation' && $key === 'control-creation', 'journal_changed');
        }
        $this->checkDirectory();
        $path = $this->directory . '/journal-' . $key . '.json';
        $temporary = $path . '.' . bin2hex(random_bytes(8));
        $mask = umask(0077);
        try { $stream = fopen($temporary, 'xb'); } finally { umask($mask); }
        self::need(is_resource($stream), 'journal_unavailable');
        try {
            $raw = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            self::need(strlen($raw) <= 262144 && fwrite($stream, $raw) === strlen($raw)
                && fflush($stream) && fsync($stream), 'journal_unavailable');
        } finally { fclose($stream); }
        self::need(rename($temporary, $path), 'journal_unavailable');
        $directory = fopen($this->directory, 'r');
        try { self::need(is_resource($directory) && fsync($directory), 'journal_unavailable'); }
        finally { if (is_resource($directory)) fclose($directory); }
        self::need($this->load($key) === $value, 'journal_unconfirmed');
    }

    public function close(): void
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
    }

    public function __destruct() { $this->close(); }
}

<?php
declare(strict_types=1);

namespace Keli\Acceptance;

/** Durable native RPC session shared by the fixed CLI and isolated application tests. */
final class MemberNativeController
{
    private const JOURNAL = MemberCliAuthority::ROOT . '/private/member-journal';
    private $lock = null;
    private MemberCliAuthority $authority;

    public function __construct(private array $scope, string $hash, ?array $request = null)
    {
        self::need(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux' && posix_geteuid() === 0, 'isolated_cli_required');
        $stat = @lstat(self::JOURNAL);
        self::need($stat && realpath(self::JOURNAL) === self::JOURNAL && is_dir(self::JOURNAL)
            && $stat['uid'] === 0 && ($stat['mode'] & 0077) === 0, 'isolated_journal_required');
        $path = self::JOURNAL . '/rpc.lock';
        clearstatcache(true, $path); $stat = @lstat($path);
        self::need(!$stat || (realpath($path) === $path && is_file($path) && $stat['nlink'] === 1
            && $stat['uid'] === 0 && ($stat['mode'] & 0077) === 0), 'isolated_rpc_lock_rejected');
        $mask = umask(0077);
        try { $this->lock = fopen($path, $stat ? 'r+b' : 'x+b'); }
        finally { umask($mask); }
        try {
            self::need(is_resource($this->lock), 'isolated_rpc_lock_rejected');
            $opened = fstat($this->lock); clearstatcache(true, $path); $current = @lstat($path);
            self::need($current && realpath($path) === $path, 'isolated_rpc_lock_path_changed');
            self::need($opened['ino'] === $current['ino'] && $opened['dev'] === $current['dev'], 'isolated_rpc_lock_identity_changed');
            self::need($opened['nlink'] === 1 && $opened['uid'] === 0, 'isolated_rpc_lock_owner_changed');
            // Only initialize this process's new, identity-checked file. Existing
            // unsafe locks remain rejected above; umask alone is not a receipt.
            if (!$stat) self::need(chmod($path, 0600), 'isolated_rpc_lock_mode_changed');
            clearstatcache(true, $path); $opened = fstat($this->lock);
            self::need(($opened['mode'] & 0077) === 0, 'isolated_rpc_lock_mode_changed');
            $this->authority = MemberCliAuthority::controller($scope, $hash, fn () => $this->load('round-stop') !== null);
            if ($request !== null) {
                MemberRoundBridge::authorizeRequest($request, $this->authority);
                // Revoke admission before waiting on an in-flight native RPC.
                // A busy lock still prevents a successful stop acknowledgement.
                if ($request['operation'] === 'round-stop') $this->authority->stopCallbacks();
            }
            self::need(flock($this->lock, LOCK_EX | LOCK_NB), 'isolated_rpc_busy');
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    private static function need(bool $ok, string $reason): void
    {
        if (!$ok) throw new \RuntimeException($reason);
    }

    private static function read(string $path, int $max, bool $public = false): array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        self::need($stat && realpath($path) === $path && is_file($path) && $stat['nlink'] === 1
            && $stat['uid'] === 0 && ($public ? ($stat['mode'] & 0777) === 0644 : ($stat['mode'] & 0077) === 0)
            && $stat['size'] <= $max, 'isolated_file_rejected');
        $stream = fopen($path, 'rb'); self::need($stream !== false, 'isolated_file_rejected');
        try {
            $opened = fstat($stream);
            self::need($opened['ino'] === $stat['ino'] && $opened['dev'] === $stat['dev'], 'isolated_file_changed');
            $raw = stream_get_contents($stream, $max + 1);
            self::need(strlen($raw) === $stat['size'], 'isolated_file_changed');
        } finally { fclose($stream); }
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($value), 'isolated_json_required');
        return $value;
    }

    private function load(string $key): ?array
    {
        self::need(preg_match('/^(source-[ab]|delete-(source-[ab]|candidate-a[12])|round-driver|round-stop|evidence-[a-f0-9]{64}|rpc-mutation|rpc-[a-f0-9]{32})$/D', $key) === 1,
            'isolated_journal_key');
        $path = self::JOURNAL . '/' . $key . '.json'; clearstatcache(true, $path);
        return @lstat($path) ? self::read($path, 262144) : null;
    }

    private function save(string $key, array $value): void
    {
        $previous = $this->load($key);
        if ((str_starts_with($key, 'delete-') || str_starts_with($key, 'evidence-') || $key === 'round-stop') && $previous !== null) {
            self::need($previous === $value, 'isolated_intent_changed');
            return;
        }
        $temp = self::JOURNAL . '/.' . bin2hex(random_bytes(12));
        $mask = umask(0077);
        try { $stream = fopen($temp, 'x+b'); } finally { umask($mask); }
        self::need($stream !== false, 'isolated_journal_unavailable');
        try {
            self::need(chmod($temp, 0600), 'isolated_journal_unavailable');
            $data = json_encode($value, JSON_THROW_ON_ERROR);
            self::need(strlen($data) <= 262144 && fwrite($stream, $data) === strlen($data)
                && fflush($stream) && fsync($stream), 'isolated_journal_unavailable');
            fclose($stream); $stream = null;
            self::need(rename($temp, self::JOURNAL . '/' . $key . '.json'), 'isolated_journal_unavailable');
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (file_exists($temp)) unlink($temp);
        }
    }

    public function dispatch(array $request): array
    {
        self::need(is_resource($this->lock), 'isolated_rpc_closed');
        $root = MemberCliAuthority::ROOT;
        if (!in_array($request['operation'] ?? null, ['round-status', 'round-stop', 'round-dns-cleanup', 'round-evidence', 'task-inspect', 'task-delete'], true)
            && (file_exists($root . '/member-setup-grant.json') || is_link($root . '/member-setup-grant.json'))) {
            $setup = self::read($root . '/member-setup-grant.json', 65536, true);
            $cap = self::read($root . '/member-candidate-callbacks.json', 65536, true);
            self::need(($cap['schema'] ?? null) === 1 && ($cap['run_id'] ?? null) === ($this->scope['run_id'] ?? null)
                && ($cap['native_sha256'] ?? null) === hash_file('sha256', $root . '/private/member-runtime-scope.json')
                && ($cap['setup_sha256'] ?? null) === hash_file('sha256', $root . '/member-setup-grant.json')
                && ($cap['driver'] ?? null) === ($this->scope['driver'] ?? null), 'isolated_callback_grant_changed');
            foreach (['started_at', 'deadline_at'] as $key)
                self::need(isset($this->scope[$key]) && ($setup[$key] ?? null) === $this->scope[$key]
                    && ($cap[$key] ?? null) === $this->scope[$key], 'isolated_callback_grant_changed');
        }
        return (new MemberRoundBridge($this->scope, $this->authority, $this->load(...), $this->save(...),
            static fn (): int => time()))->dispatch($request);
    }

    public function close(): void
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
    }

    public function __destruct() { $this->close(); }
}

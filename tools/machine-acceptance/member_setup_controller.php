<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineProvisionTask as Task;

/** Actual source setup command binding. Caller closes the handle after one RPC. */
final class MemberSetupController
{
    private const DIRECTORY = '/opt/keli-isolated/private/member-setup-journal';
    private $lock = null;
    private MemberSourceInitialization $sources;
    private array $binding;

    public function __construct(private MemberSetupAuthority $authority)
    {
        MemberApplicationBootstrap::initialized();
        $this->binding = $authority->sourceBinding();
        $directory = self::DIRECTORY;
        if (!file_exists($directory) && !is_link($directory)) {
            $authority->allow('sources-advance', 'control');
            self::need(mkdir($directory, 0700), 'journal_unavailable');
        }
        $stat = lstat($directory);
        self::need(realpath($directory) === $directory && is_dir($directory) && $stat['uid'] === 0
            && ($stat['mode'] & 0777) === 0700, 'journal_invalid');
        self::need(!is_link($directory . '/lock'), 'lock_invalid');
        $lock = fopen($directory . '/lock', 'c+b');
        self::need($lock !== false, 'lock_invalid');
        try {
            $stat = fstat($lock);
            self::need($stat['uid'] === 0 && $stat['nlink'] === 1 && chmod($directory . '/lock', 0600), 'lock_invalid');
            self::need(flock($lock, LOCK_EX | LOCK_NB), 'busy');
            $this->lock = $lock;
            $pin = $authority->identity();
            $old = $this->load('binding');
            if ($old === null) {
                self::need(array_diff(scandir($directory), ['.', '..', 'lock']) === [], 'unowned_journal');
                $this->save('binding', $pin);
            } else self::need($old === $pin, 'binding_changed');
            $this->sources = new MemberSourceInitialization($this->binding['configuration']['initialization_scope'],
                $authority->within(...), $this->load(...), $this->save(...));
        } catch (\Throwable $error) {
            flock($lock, LOCK_UN); fclose($lock); $this->lock = null; throw $error;
        }
    }

    private static function need(bool $ok, string $reason): void
    {
        if (!$ok) throw new \RuntimeException('member_setup_controller_' . $reason);
    }

    private function path(string $key): string
    {
        self::need(is_resource($this->lock) && preg_match('/^(binding|first-error|source-[ab]|source-queue-source-[ab]|initialized-source-[ab])$/D', $key) === 1,
            'journal_key');
        return self::DIRECTORY . '/' . $key . '.json';
    }

    private function load(string $key): ?array
    {
        $path = $this->path($key); clearstatcache(true, $path); $stat = @lstat($path);
        if (!$stat) return null;
        self::need(realpath($path) === $path && is_file($path) && $stat['uid'] === 0 && $stat['nlink'] === 1
            && ($stat['mode'] & 0777) === 0600 && $stat['size'] <= 65536, 'journal_invalid');
        $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($value), 'journal_invalid');
        return $value;
    }

    private function save(string $key, array $value): void
    {
        $path = $this->path($key); $old = $this->load($key);
        if (($key === 'binding' || str_starts_with($key, 'source-queue-') || str_starts_with($key, 'initialized-')) && $old !== null) {
            self::need(MemberProviderBindings::digest($old) === MemberProviderBindings::digest($value), 'intent_changed');
            return;
        }
        if ($key === 'first-error' && $old !== null) return;
        $temp = self::DIRECTORY . '/.' . bin2hex(random_bytes(12));
        $mask = umask(0077);
        try { $stream = fopen($temp, 'x+b'); } finally { umask($mask); }
        self::need($stream !== false, 'journal_unavailable');
        try {
            $raw = json_encode($value, JSON_THROW_ON_ERROR);
            self::need(strlen($raw) <= 65536 && fwrite($stream, $raw) === strlen($raw)
                && fflush($stream) && fsync($stream), 'journal_unavailable');
        } finally { fclose($stream); }
        self::need(rename($temp, $path), 'journal_unavailable');
        self::need(MemberProviderBindings::digest($this->load($key)) === MemberProviderBindings::digest($value), 'journal_unconfirmed');
    }

    public function dispatch(array $request): array
    {
        self::need(is_resource($this->lock) && array_keys($request) === ['operation']
            && in_array($request['operation'], ['advance', 'status'], true), 'request_invalid');
        try {
            if ($request['operation'] === 'advance') {
                self::need($this->load('first-error') === null, 'prior_failure');
                return $this->sources->advance($this->binding['scope']);
            }
            return $this->authority->within('source-readback', 'source-a', function (): array {
                $sources = [];
                foreach ($this->binding['configuration']['sources'] as $role => $pin) {
                    $task = Task::query()->where('machine_id', $pin['machine_id'])->first();
                    $sources[$role] = ['queued' => $task !== null, 'status' => $task?->status,
                        'registered' => $this->load('initialized-' . $role) !== null,
                        'fresh_reports' => count($this->load($role)['samples'] ?? [])];
                }
                return ['sources' => $sources, 'first_error' => $this->load('first-error'), 'health_or_forwarding_claimed' => false];
            });
        } catch (\Throwable $error) {
            if ($request['operation'] === 'advance') {
                try { $this->save('first-error', ['class' => (new \ReflectionClass($error))->getShortName(),
                    'file' => basename($error->getFile()), 'line' => $error->getLine()]); }
                catch (\Throwable) { /* Preserve the initiating error. */ }
            }
            throw $error;
        }
    }

    public function close(): void
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
    }

    public function __destruct() { $this->close(); }
}

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

    public function __construct(private MemberSetupAuthority $authority, bool $recovery = false)
    {
        MemberApplicationBootstrap::initialized();
        $this->binding = $authority->sourceBinding();
        $directory = self::DIRECTORY;
        if (!file_exists($directory) && !is_link($directory)) {
            $authority->allow($recovery ? 'source-cleanup' : 'sources-advance', $recovery ? 'source-a' : 'control');
            self::need(mkdir($directory, 0700), 'journal_unavailable');
        }
        clearstatcache(true, $directory);
        $stat = lstat($directory);
        self::need(realpath($directory) === $directory && is_dir($directory), 'journal_directory_path');
        self::need($stat['uid'] === 0, 'journal_directory_owner');
        self::need(($stat['mode'] & 0777) === 0700, 'journal_directory_mode');
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
        self::need(is_resource($this->lock) && preg_match('/^(binding|first-error|rpc-mutation|rpc-cleanup|rpc-[a-f0-9]{32}|source-[ab]|source-queue-source-[ab]|initialized-source-[ab]|cleanup-source-[ab]|dns-scope-(control|edges)|(dns-initialize|dns-cleanup|provider-delete)-[a-f0-9]{64})$/D', $key) === 1,
            'journal_key');
        return self::DIRECTORY . '/' . $key . '.json';
    }

    private function load(string $key): ?array
    {
        $path = $this->path($key); clearstatcache(true, $path); $stat = @lstat($path);
        if (!$stat) return null;
        self::need(realpath($path) === $path && is_file($path), 'journal_file_path');
        self::need($stat['uid'] === 0 && $stat['nlink'] === 1, 'journal_file_owner');
        self::need(($stat['mode'] & 0777) === 0600, 'journal_file_mode');
        self::need($stat['size'] <= 65536, 'journal_file_size');
        $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::need(is_array($value), 'journal_invalid');
        return $value;
    }

    private function save(string $key, array $value): void
    {
        $path = $this->path($key); $old = $this->load($key);
        if (($key === 'binding' || str_starts_with($key, 'source-queue-') || str_starts_with($key, 'initialized-') || str_starts_with($key, 'dns-scope-')) && $old !== null) {
            self::need(MemberProviderBindings::digest($old) === MemberProviderBindings::digest($value), 'intent_changed');
            return;
        }
        if ($key === 'first-error' && $old !== null) return;
        $temp = self::DIRECTORY . '/.' . bin2hex(random_bytes(12));
        $mask = umask(0077);
        try { $stream = fopen($temp, 'x+b'); } finally { umask($mask); }
        self::need($stream !== false, 'journal_unavailable');
        try {
            // Default ACLs can override the creation umask. Narrow only this new file.
            self::need(chmod($temp, 0600) && (fstat($stream)['mode'] & 0777) === 0600, 'journal_file_mode');
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
            && in_array($request['operation'], ['advance', 'status', 'cleanup', 'dns-control', 'dns-edges', 'dns-readback'], true), 'request_invalid');
        try {
            if (str_starts_with($request['operation'], 'dns-')) {
                if ($request['operation'] === 'dns-readback') return $this->dns()->readback();
                self::need($this->load('first-error') === null, 'prior_failure');
                return $this->dns()->advance($request['operation'] === 'dns-edges');
            }
            if ($request['operation'] === 'cleanup') {
                $this->authority->stop();
                $result = ['complete' => true, 'sources' => []];
                foreach (['source-a', 'source-b'] as $role) {
                    try { $reply = $this->sources->cleanup($role); }
                    catch (\Throwable $error) {
                        $diagnostic = ['class' => (new \ReflectionClass($error))->getShortName(),
                            'file' => basename($error->getFile()), 'line' => $error->getLine()];
                        try { $this->save('first-error', $diagnostic); } catch (\Throwable) { }
                        $reply = ['complete' => false, 'classification' => 'cleanup_unconfirmed', 'diagnostic' => $diagnostic];
                    }
                    $result['sources'][$role] = $reply;
                    $result['complete'] = $result['complete'] && $reply['complete'];
                }
                try { $result['dns'] = $this->dns()->cleanup(); }
                catch (\Throwable $error) {
                    $result['dns'] = ['complete' => false, 'diagnostic' => ['class' => (new \ReflectionClass($error))->getShortName(),
                        'file' => basename($error->getFile()), 'line' => $error->getLine()]];
                }
                foreach ([$result['dns'], ...array_values($result['dns']['records'] ?? [])] as $item) {
                    if (isset($item['diagnostic'])) {
                        try { $this->save('first-error', $item['diagnostic']); } catch (\Throwable) { }
                    }
                }
                $result['complete'] = $result['complete'] && $result['dns']['complete'];
                return $result;
            }
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
                        'fresh_reports' => count($this->load($role)['samples'] ?? []),
                        'next_check_at' => $task?->next_check_at];
                }
                return ['sources' => $sources, 'first_error' => $this->load('first-error'), 'health_or_forwarding_claimed' => false];
            });
        } catch (\Throwable $error) {
            if (in_array($request['operation'], ['advance', 'dns-control', 'dns-edges'], true)) {
                try { $this->save('first-error', ['class' => (new \ReflectionClass($error))->getShortName(),
                    'file' => basename($error->getFile()), 'line' => $error->getLine()]); }
                catch (\Throwable) { /* Preserve the initiating error. */ }
            }
            throw $error;
        }
    }

    public static function validateRequest(array $request): void
    {
        $keys = ['schema', 'run_id', 'grant_id', 'grant_sha256', 'deadline_at', 'request_id', 'sequence', 'operation'];
        self::need(count($request) === count($keys) && !array_diff(array_keys($request), $keys)
            && ($request['schema'] ?? null) === 1 && ($request['run_id'] ?? null) === MemberCliAuthority::RUN
            && is_int($request['deadline_at'] ?? null) && $request['deadline_at'] > 0
            && is_int($request['sequence'] ?? null) && $request['sequence'] >= 0
            && in_array($request['operation'] ?? null, ['advance', 'status', 'stop', 'cleanup', 'dns-control', 'dns-edges', 'dns-readback'], true), 'request_invalid');
        foreach (['grant_id' => 32, 'grant_sha256' => 64, 'request_id' => 32] as $key => $length)
            self::need(is_string($request[$key] ?? null)
                && preg_match('/^[a-f0-9]{' . $length . '}$/D', $request[$key]) === 1, 'request_invalid');
    }

    public function rpc(array $request): array
    {
        self::validateRequest($request);
        foreach ($this->authority->identity() as $key => $value)
            self::need($request[$key] === $value, 'request_binding_changed');
        self::need($request['operation'] !== 'stop', 'request_invalid');
        $cleanup = $request['operation'] === 'cleanup';
        $dns = str_starts_with($request['operation'], 'dns-');
        $mutating = in_array($request['operation'], ['advance', 'cleanup', 'dns-control', 'dns-edges'], true);
        if ($cleanup) $this->authority->stop();
        $this->authority->allow($dns ? $request['operation'] : ($cleanup ? 'source-cleanup' : ($mutating ? 'sources-advance' : 'source-readback')),
            $dns || $mutating && !$cleanup ? 'control' : 'source-a');
        $key = 'rpc-' . $request['request_id'];
        $mutationKey = $cleanup ? 'rpc-cleanup' : 'rpc-mutation';
        if ($mutating) {
            $prior = $this->load($key);
            if ($prior !== null) {
                self::need($prior['request'] === $request, 'request_reused');
                self::need($cleanup || isset($prior['reply']), 'uncertain_request_readback_required');
                if (isset($prior['reply'])) return $prior['reply'];
            }
            $latest = $this->load($mutationKey);
            self::need($cleanup || !$latest || ($latest['completed'] ?? false), 'uncertain_request_readback_required');
            foreach (['rpc-mutation', 'rpc-cleanup'] as $ledger) {
                $item = $this->load($ledger);
                self::need(!$item || $request['sequence'] > $item['sequence']
                    || ($cleanup && $prior !== null && $request['sequence'] === $item['sequence']
                        && $request['request_id'] === $item['request_id']), 'request_sequence_reused');
            }
            $this->save($mutationKey, ['sequence' => $request['sequence'], 'request_id' => $request['request_id'], 'completed' => false]);
            $this->save($key, ['request' => $request]);
        }
        $result = $this->dispatch(['operation' => $request['operation']]);
        if (!$mutating) {
            $latest = $this->load('rpc-mutation');
            $result['uncertain_request'] = $latest && !$latest['completed'] ? $latest : null;
            $cleanupLatest = $this->load('rpc-cleanup');
            $result['uncertain_cleanup'] = $cleanupLatest && !$cleanupLatest['completed'] ? $cleanupLatest : null;
        }
        $reply = $request + ['ok' => true, 'result' => $result];
        if ($mutating) {
            $this->save($key, ['request' => $request, 'reply' => $reply]);
            $this->save($mutationKey, ['sequence' => $request['sequence'], 'request_id' => $request['request_id'], 'completed' => true]);
        }
        return $reply;
    }

    public function close(): void
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
    }

    private function dns(): MemberSetupDns
    {
        return new MemberSetupDns($this->authority, function (): array {
            foreach (['source-a', 'source-b'] as $role) self::need($this->load('initialized-' . $role) !== null, 'sources_not_registered');
            return $this->sources->registeredScope($this->binding['scope'])['sources'];
        }, $this->load(...), $this->save(...));
    }

    public function __destruct() { $this->close(); }
}

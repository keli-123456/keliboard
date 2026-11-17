<?php
declare(strict_types=1);

namespace Keli\Acceptance;

/** Fixed isolated bootstrap journal; credentials and deadlines are never RPC inputs. */
final class MemberBootstrapController
{
    private const DIRECTORY = '/opt/keli-isolated/private/member-import';
    private const STOP = '/opt/keli-isolated/private/import-stopped';
    private const KEYS = ['intent', 'result', 'first-error', 'source-configuration-intent',
        'source-configuration-result', 'source-configuration-first-error',
        'setup-issuance-intent', 'setup-issuance-result', 'setup-issuance-first-error'];
    private $lock = null;
    private MemberPrerequisiteImport $import;

    private static function need(bool $ok, string $code): void
    {
        if (!$ok) throw new \RuntimeException('member_import_' . $code);
    }

    public static function validateRequest(array $request): void
    {
        $operation = $request['operation'] ?? null;
        self::need(is_string($operation) && in_array($operation, ['import', 'readback', 'configure',
            'configuration-read', 'setup-issue', 'setup-read', 'stop'], true), 'request_invalid');
        $keys = $operation === 'setup-issue' ? ['operation', 'control'] : ['operation'];
        self::need(count($request) === count($keys) && !array_diff(array_keys($request), $keys), 'request_invalid');
        if ($operation !== 'setup-issue') return;
        $p = $request['control'];
        $keys = ['run_id', 'external_id', 'task_id', 'label', 'marker', 'region', 'size', 'image', 'provider_created_at'];
        self::need(is_array($p) && count($p) === count($keys) && !array_diff(array_keys($p), $keys), 'control_invalid');
        foreach ($p as $v) self::need(is_string($v), 'control_invalid');
        self::need($p['run_id'] === MemberCliAuthority::RUN
            && preg_match('/^[1-9][0-9]{0,15}$/D', $p['external_id'])
            && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $p['task_id'])
            && $p['label'] === 'keli-' . str_replace('-', '', $p['task_id'])
            && $p['marker'] === 'keli-create-' . str_replace('-', '', $p['task_id'])
            && $p['region'] === 'sg-sin-2' && $p['size'] === 'g6-standard-2' && $p['image'] === 'linode/debian12'
            && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ?$/D', $p['provider_created_at']), 'control_invalid');
    }

    private static function root(): void
    {
        self::need(PHP_OS_FAMILY === 'Linux' && PHP_SAPI === 'cli' && posix_geteuid() === 0, 'root_cli_required');
        MemberApplicationBootstrap::receipt();
        $private = dirname(self::DIRECTORY); $stat = @lstat($private);
        self::need($stat && realpath($private) === $private && is_dir($private)
            && $stat['uid'] === 0 && ($stat['mode'] & 0777) === 0700, 'private_directory_invalid');
    }

    private static function acquire()
    {
        if (!file_exists(self::DIRECTORY) && !is_link(self::DIRECTORY)) mkdir(self::DIRECTORY, 0700);
        self::need(realpath(self::DIRECTORY) === self::DIRECTORY && is_dir(self::DIRECTORY)
            && fileowner(self::DIRECTORY) === 0 && (fileperms(self::DIRECTORY) & 0777) === 0700, 'journal_invalid');
        $path = self::DIRECTORY . '/lock'; self::need(!is_link($path), 'lock_invalid');
        $new = !file_exists($path); $mask = umask(0077);
        try { $lock = fopen($path, $new ? 'x+b' : 'r+b'); } finally { umask($mask); }
        try {
            if ($new && $lock) self::need(chmod($path, 0600), 'lock_invalid');
            $stat = $lock ? fstat($lock) : false;
            self::need($stat && $stat['uid'] === 0 && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0600
                && flock($lock, LOCK_EX | LOCK_NB), 'busy');
            return $lock;
        } catch (\Throwable $error) { if (is_resource($lock)) fclose($lock); throw $error; }
    }

    public function __construct()
    {
        self::root(); MemberApplicationBootstrap::initialized();
        $this->lock = self::acquire();
        $this->import = new MemberPrerequisiteImport(static function (string $phase): void {
            MemberApplicationBootstrap::initialized();
            if ($phase !== 'readback') self::need(!file_exists(self::STOP) && !is_link(self::STOP), 'expired_or_stopped');
        }, $this->load(...), $this->save(...));
    }

    private function path(string $key): string
    {
        self::need(in_array($key, self::KEYS, true), 'journal_key');
        return self::DIRECTORY . '/' . $key . '.json';
    }

    private function load(string $key): ?array
    {
        self::need(is_resource($this->lock), 'journal_closed');
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
        if (($prior = $this->load($key)) !== null) {
            if (str_ends_with($key, 'first-error') || MemberProviderBindings::digest($prior) === MemberProviderBindings::digest($value)) return;
            throw new \RuntimeException('member_import_journal_changed');
        }
        $temp = self::DIRECTORY . '/.' . bin2hex(random_bytes(12)); $mask = umask(0077);
        try { $stream = fopen($temp, 'x+b'); } finally { umask($mask); }
        self::need($stream !== false, 'journal_unconfirmed');
        try {
            $raw = json_encode($value, JSON_THROW_ON_ERROR);
            self::need(strlen($raw) <= 65536 && chmod($temp, 0600) && fwrite($stream, $raw) === strlen($raw)
                && fflush($stream) && fsync($stream), 'journal_unconfirmed');
        } finally { fclose($stream); }
        self::need(rename($temp, $this->path($key)), 'journal_unconfirmed');
        self::need(MemberProviderBindings::digest($this->load($key) ?? []) === MemberProviderBindings::digest($value), 'journal_unconfirmed');
    }

    public function configuration(): MemberSourceConfiguration
    {
        $scope = $this->import->readback();
        return new MemberSourceConfiguration($scope, function (string $phase): void {
            $this->import->authorization($phase !== 'source-configuration-read');
        }, $this->load(...), $this->save(...));
    }

    private function setupRead(): array
    {
        $bound = $this->import->authorization(false);
        $intent = $this->load('setup-issuance-intent'); self::need($intent !== null, 'setup_intent_missing');
        $configuration = ['scope' => $bound['scope'], 'configuration' => $this->configuration()->readback()];
        // Readback does not extend the window or sign a replacement capability.
        $authority = MemberSetupAuthority::open();
        self::need($intent['authority'] === $bound['identity']
            && $intent['started_at'] === $bound['started_at'] && $intent['deadline_at'] === $bound['deadline_at']
            && $authority->windowBinding() === ['started_at' => $bound['started_at'], 'deadline_at' => $bound['deadline_at']]
            && MemberProviderBindings::digest($authority->sourceBinding()) === MemberProviderBindings::digest($configuration)
            && $intent['configuration_sha256'] === MemberProviderBindings::digest($configuration)
            && $authority->controlBinding()['pin'] === $intent['control'], 'setup_binding_changed');
        $identity = $authority->identity();
        $this->save('setup-issuance-result', $identity);
        return $identity;
    }

    public function rpc(array $request): array
    {
        self::need(is_resource($this->lock), 'journal_closed');
        self::validateRequest($request); $operation = $request['operation'];
        self::need($operation !== 'stop', 'stop_entry_required');
        if ($operation === 'import') return ['scope' => $this->import->import()];
        if ($operation === 'readback') return ['scope' => $this->import->readback()];
        if ($operation === 'configure') return ['configuration' => $this->configuration()->create()];
        if ($operation === 'configuration-read') return ['configuration' => $this->configuration()->readback()];
        if ($operation === 'setup-read') return ['setup' => $this->setupRead()];
        $bound = $this->import->authorization(true);
        $configuration = $this->configuration();
        $intent = ['authority' => $bound['identity'], 'started_at' => $bound['started_at'], 'deadline_at' => $bound['deadline_at'],
            'configuration_sha256' => MemberProviderBindings::digest($configuration->authorizationBinding()), 'control' => $request['control']];
        if (($prior = $this->load('setup-issuance-intent')) !== null) {
            self::need($prior === $intent, 'setup_binding_changed');
            return ['setup' => $this->setupRead()];
        }
        $this->save('setup-issuance-intent', $intent);
        try {
            MemberSetupAuthority::issue($configuration, $bound['started_at'], $bound['deadline_at'], $request['control']);
            try { $this->import->authorization(true); }
            catch (\Throwable $error) { MemberSetupAuthority::revoke(); throw $error; }
            return ['setup' => $this->setupRead()];
        } catch (\Throwable $error) {
            try { $this->save('setup-issuance-first-error', ['class' => (new \ReflectionClass($error))->getShortName(),
                'file' => basename($error->getFile()), 'line' => $error->getLine()]); } catch (\Throwable) { }
            throw $error;
        }
    }

    public static function stop(): array
    {
        self::root();
        if (!file_exists(self::STOP) && !is_link(self::STOP)) {
            $mask = umask(0077);
            try { $stream = fopen(self::STOP, 'x+b'); } finally { umask($mask); }
            self::need($stream !== false, 'stop_unconfirmed');
            try {
                $value = MemberCliAuthority::RUN . "\n";
                self::need(chmod(self::STOP, 0600) && fwrite($stream, $value) === strlen($value)
                    && fflush($stream) && fsync($stream), 'stop_unconfirmed');
            } finally { fclose($stream); }
        }
        $stat = @lstat(self::STOP);
        self::need($stat && realpath(self::STOP) === self::STOP && is_file(self::STOP) && $stat['uid'] === 0
            && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0600
            && file_get_contents(self::STOP) === MemberCliAuthority::RUN . "\n", 'stop_unconfirmed');
        $lock = self::acquire();
        try {
            $grant = MemberApplicationBootstrap::ROOT . '/member-setup-grant.json';
            if (file_exists($grant) || is_link($grant)) MemberSetupAuthority::revoke();
            return ['stopped' => true];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function close(): void
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
    }

    public function __destruct() { $this->close(); }
}

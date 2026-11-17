<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$lock = null;
try {
    if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || posix_geteuid() !== 0)
        throw new RuntimeException('member_import_root_cli_required');
    require __DIR__ . '/member_cli_authority.php';
    require __DIR__ . '/member_application_bootstrap.php';
    require __DIR__ . '/member_provider_bindings.php';
    require __DIR__ . '/member_prerequisite_import.php';
    $raw = stream_get_contents(STDIN, 4097);
    $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (strlen($raw) > 4096 || !is_array($request) || array_keys($request) !== ['operation']
        || !in_array($request['operation'], ['import', 'readback', 'stop'], true))
        throw new RuntimeException('member_import_request_invalid');
    Keli\Acceptance\MemberApplicationBootstrap::receipt();
    $private = '/opt/keli-isolated/private'; $stat = @lstat($private);
    if (!$stat || realpath($private) !== $private || !is_dir($private) || $stat['uid'] !== 0 || ($stat['mode'] & 0777) !== 0700)
        throw new RuntimeException('member_import_private_directory_invalid');
    if ($request['operation'] === 'stop') {
        $path = $private . '/import-stopped';
        if (!file_exists($path) && !is_link($path)) {
            $old = umask(0077);
            try { $stream = fopen($path, 'x+b'); } finally { umask($old); }
            if (!$stream) throw new RuntimeException('member_import_stop_unconfirmed');
            try {
                $value = Keli\Acceptance\MemberCliAuthority::RUN . "\n";
                if (!chmod($path, 0600) || fwrite($stream, $value) !== strlen($value) || !fflush($stream) || !fsync($stream))
                    throw new RuntimeException('member_import_stop_unconfirmed');
            } finally { fclose($stream); }
        }
        if (realpath($path) !== $path || !is_file($path) || fileowner($path) !== 0
            || (fileperms($path) & 0777) !== 0600 || file_get_contents($path) !== Keli\Acceptance\MemberCliAuthority::RUN . "\n")
            throw new RuntimeException('member_import_stop_unconfirmed');
        echo json_encode(['ok' => true, 'operation' => 'stop', 'stopped' => true]);
    } else {
        Keli\Acceptance\MemberApplicationBootstrap::boot();
        Keli\Acceptance\MemberApplicationBootstrap::initialized();
        $directory = $private . '/member-import';
        if (!file_exists($directory) && !is_link($directory)) mkdir($directory, 0700);
        if (realpath($directory) !== $directory || !is_dir($directory) || fileowner($directory) !== 0
            || (fileperms($directory) & 0777) !== 0700) throw new RuntimeException('member_import_journal_invalid');
        if (is_link($directory . '/lock')) throw new RuntimeException('member_import_lock_invalid');
        $newLock = !file_exists($directory . '/lock');
        $mask = umask(0077);
        try { $lock = fopen($directory . '/lock', $newLock ? 'x+b' : 'r+b'); } finally { umask($mask); }
        // Default ACLs may override umask. Narrow only a file this process created.
        if ($newLock && $lock && !chmod($directory . '/lock', 0600)) throw new RuntimeException('member_import_lock_invalid');
        $stat = $lock ? fstat($lock) : false;
        if (!$stat || $stat['uid'] !== 0 || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600
            || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('member_import_busy');
        $path = static function (string $key) use ($directory): string {
            if (!in_array($key, ['intent', 'result', 'first-error'], true)) throw new RuntimeException('member_import_journal_key');
            return $directory . '/' . $key . '.json';
        };
        $load = static function (string $key) use ($path): ?array {
            $name = $path($key); clearstatcache(true, $name); $stat = @lstat($name);
            if (!$stat) return null;
            if (realpath($name) !== $name || !is_file($name) || $stat['uid'] !== 0 || $stat['nlink'] !== 1
                || ($stat['mode'] & 0777) !== 0600 || $stat['size'] > 65536) throw new RuntimeException('member_import_journal_invalid');
            $value = json_decode(file_get_contents($name), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($value)) throw new RuntimeException('member_import_journal_invalid');
            return $value;
        };
        $save = static function (string $key, array $value) use ($directory, $path, $load): void {
            if (($prior = $load($key)) !== null) {
                if ($key === 'first-error' || Keli\Acceptance\MemberProviderBindings::digest($prior) === Keli\Acceptance\MemberProviderBindings::digest($value)) return;
                throw new RuntimeException('member_import_journal_changed');
            }
            $temp = $directory . '/.' . bin2hex(random_bytes(12));
            $mask = umask(0077);
            try { $stream = fopen($temp, 'x+b'); } finally { umask($mask); }
            if (!$stream) throw new RuntimeException('member_import_journal_unconfirmed');
            try {
                $raw = json_encode($value, JSON_THROW_ON_ERROR);
                if (strlen($raw) > 65536 || !chmod($temp, 0600) || fwrite($stream, $raw) !== strlen($raw)
                    || !fflush($stream) || !fsync($stream)) throw new RuntimeException('member_import_journal_unconfirmed');
            } finally { fclose($stream); }
            if (!rename($temp, $path($key))) throw new RuntimeException('member_import_journal_unconfirmed');
        };
        $guard = static function (string $phase) use ($private): void {
            Keli\Acceptance\MemberApplicationBootstrap::initialized();
            if ($phase !== 'readback' && (file_exists($private . '/import-stopped') || is_link($private . '/import-stopped')))
                throw new RuntimeException('member_import_expired_or_stopped');
        };
        $import = new Keli\Acceptance\MemberPrerequisiteImport($guard, $load, $save);
        $scope = $request['operation'] === 'import' ? $import->import() : $import->readback();
        echo json_encode(['ok' => true, 'operation' => $request['operation'], 'scope' => $scope]);
    }
} catch (Throwable $error) {
    $code = preg_match('/^member_(import|application)_[a-z_]{1,70}$/D', $error->getMessage()) ? $error->getMessage() : 'member_import_failed';
    echo json_encode(['ok' => false, 'reason' => $code, 'class' => (new ReflectionClass($error))->getShortName(),
        'file' => basename($error->getFile()), 'line' => $error->getLine()]);
    exit(1);
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}

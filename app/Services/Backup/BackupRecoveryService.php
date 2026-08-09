<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use RuntimeException;

class BackupRecoveryService
{
    public function inspect(string $path, array $options = []): array
    {
        $path = $this->normalizePath($path);
        if (!File::isFile($path)) {
            throw new RuntimeException('Backup file does not exist');
        }

        $expectedChecksum = strtolower(trim((string) ($options['expected_sha256'] ?? '')));
        $actualChecksum = strtolower((string) hash_file('sha256', $path));
        $artifact = $this->prepareArtifact($path);

        try {
            $inspection = $this->inspectLegacyGzip($artifact['database_path'], [
                'connection' => (string) ($options['connection'] ?? ''),
            ]);
            $warnings = array_values(array_filter(
                (array) ($inspection['warnings'] ?? []),
                fn(string $warning): bool => !str_starts_with($warning, 'No expected SHA256 was provided')
            ));
            if ($expectedChecksum === '') {
                $warnings[] = 'No expected SHA256 was provided; checksum can only be displayed, not compared.';
            }

            $inspection['path'] = $path;
            $inspection['filename'] = basename($path);
            $inspection['size'] = File::size($path);
            $inspection['sha256'] = $actualChecksum;
            $inspection['expected_sha256'] = $expectedChecksum !== '' ? $expectedChecksum : null;
            $inspection['checksum_ok'] = $expectedChecksum !== ''
                ? hash_equals($expectedChecksum, $actualChecksum)
                : null;
            $inspection['artifact'] = [
                'format' => $artifact['format'],
                'encrypted' => $artifact['encrypted'],
                'cipher' => $artifact['cipher'],
                'key_fingerprint' => $artifact['key_fingerprint'],
                'bundled' => $artifact['bundle'] !== null,
                'entries' => data_get($artifact, 'bundle.entries'),
                'database' => data_get($artifact, 'bundle.manifest.database'),
                'resources' => data_get($artifact, 'bundle.resources', []),
            ];
            $inspection['restore_commands'] = $this->artifactRestoreCommands(
                $path,
                (string) $inspection['database_connection'],
                (bool) data_get($inspection, 'env.present', false),
                $artifact
            );
            $inspection['warnings'] = array_values(array_unique($warnings));

            return $inspection;
        } finally {
            $this->cleanupTemporaryFiles($artifact['temporary_files']);
        }
    }

    private function inspectLegacyGzip(string $path, array $options = []): array
    {
        $path = $this->normalizePath($path);
        if (!File::isFile($path)) {
            throw new RuntimeException('Backup file does not exist');
        }

        $expectedChecksum = strtolower(trim((string) ($options['expected_sha256'] ?? '')));
        $actualChecksum = strtolower((string) hash_file('sha256', $path));
        [$gzipOk, $preview, $gzipError] = $this->readGzipPreview($path);
        $metadata = $gzipOk ? $this->parseRecoveryMetadata($preview) : [];
        $connection = trim((string) ($options['connection'] ?? ''));
        if ($connection === '') {
            $connection = trim((string) ($metadata['database_connection'] ?? ''));
        }
        if ($connection === '') {
            $connection = (string) config('database.default', 'mysql');
        }

        $env = null;
        if (isset($metadata['env_base64'])) {
            $decoded = base64_decode((string) $metadata['env_base64'], true);
            if (is_string($decoded)) {
                $env = [
                    'present' => true,
                    'bytes' => strlen($decoded),
                    'sha256' => hash('sha256', $decoded),
                    'contents' => $decoded,
                ];
            }
        }
        $files = $this->formatRecoveryFiles(is_array($metadata['files'] ?? null) ? $metadata['files'] : []);

        return [
            'path' => $path,
            'filename' => basename($path),
            'size' => File::size($path),
            'sha256' => $actualChecksum,
            'expected_sha256' => $expectedChecksum !== '' ? $expectedChecksum : null,
            'checksum_ok' => $expectedChecksum !== '' ? hash_equals($expectedChecksum, $actualChecksum) : null,
            'gzip_ok' => $gzipOk,
            'gzip_error' => $gzipError,
            'sql_dump' => $gzipOk && $this->looksLikeSqlDump($preview),
            'database_connection' => $connection,
            'metadata' => [
                'format' => $metadata['format'] ?? null,
                'generated_at' => $metadata['generated_at'] ?? null,
                'env_file' => $metadata['env_file'] ?? null,
                'files_count' => isset($metadata['files_count']) ? (int) $metadata['files_count'] : count($files),
            ],
            'env' => $env ?: [
                'present' => false,
                'bytes' => 0,
                'sha256' => null,
                'contents' => null,
            ],
            'files' => $files,
            'restore_commands' => $this->restoreCommands($path, $connection, $env !== null),
            'warnings' => $this->warnings($expectedChecksum, $gzipOk, $preview, $metadata, $env, $files),
        ];
    }

    public function drill(string $path, array $options = []): array
    {
        $inspection = $this->inspect($path, $options);
        $env = is_array($inspection['env'] ?? null) ? $inspection['env'] : [];
        $files = is_array($inspection['files'] ?? null) ? $inspection['files'] : [];
        $requiredEnvKeys = $options['required_env_keys'] ?? ['APP_KEY'];
        if (!is_array($requiredEnvKeys)) {
            $requiredEnvKeys = ['APP_KEY'];
        }

        $parsedEnv = $this->parseEnvironmentContents((string) ($env['contents'] ?? ''));
        $missingEnvKeys = [];
        foreach ($requiredEnvKeys as $key) {
            $key = trim((string) $key);
            if ($key !== '' && trim((string) ($parsedEnv[$key] ?? '')) === '') {
                $missingEnvKeys[] = $key;
            }
        }

        $checks = [
            $this->drillCheck(
                'checksum',
                $inspection['checksum_ok'] !== false,
                match ($inspection['checksum_ok']) {
                    true => 'Backup SHA256 matches expected checksum',
                    false => 'Backup SHA256 does not match expected checksum',
                    default => 'Backup SHA256 was calculated but no expected checksum was provided',
                }
            ),
            $this->drillCheck(
                'gzip',
                (bool) ($inspection['gzip_ok'] ?? false),
                (bool) ($inspection['gzip_ok'] ?? false) ? 'Backup gzip stream is readable' : (string) ($inspection['gzip_error'] ?? 'Backup gzip stream is not readable')
            ),
            $this->drillCheck(
                'sql_dump',
                (bool) ($inspection['sql_dump'] ?? false),
                (bool) ($inspection['sql_dump'] ?? false) ? 'Compressed content looks like a SQL dump' : 'Compressed content does not look like a SQL dump'
            ),
            $this->drillCheck(
                'env_present',
                (bool) ($env['present'] ?? false),
                (bool) ($env['present'] ?? false) ? 'Embedded .env is present' : 'Embedded .env is missing'
            ),
            $this->drillCheck(
                'env_required_keys',
                $missingEnvKeys === [],
                $missingEnvKeys === [] ? 'Required .env keys are present' : 'Missing required .env keys: ' . implode(', ', $missingEnvKeys)
            ),
            $this->drillCheck(
                'recovery_files',
                true,
                count($files) > 0 ? 'Embedded recovery support files are present' : 'No compose or recovery support files are embedded',
                count($files) === 0
            ),
        ];

        foreach ($files as $file) {
            $checks[] = $this->drillCheck(
                'recovery_file:' . (string) ($file['name'] ?? ''),
                ($file['checksum_ok'] ?? null) !== false,
                ($file['checksum_ok'] ?? null) === false
                    ? 'Embedded recovery file checksum does not match metadata'
                    : 'Embedded recovery file checksum is valid'
            );
        }

        $ok = collect($checks)->every(fn(array $check) => (bool) ($check['ok'] ?? false) || (bool) ($check['warning'] ?? false));
        $resultInspection = $inspection;
        unset($resultInspection['env']['contents']);
        foreach ($resultInspection['files'] as &$file) {
            unset($file['contents']);
        }
        unset($file);

        return [
            'ok' => $ok,
            'checked_at' => time(),
            'checks' => $checks,
            'warnings' => array_values(array_unique([
                ...$inspection['warnings'],
                ...array_map(
                    fn(array $check) => (string) $check['message'],
                    array_filter($checks, fn(array $check) => (bool) ($check['warning'] ?? false))
                ),
            ])),
            'inspection' => $resultInspection,
        ];
    }

    public function writeEnvironmentFile(string $contents, string $targetPath, bool $force = false): void
    {
        $targetPath = $this->normalizePath($targetPath);
        if (File::exists($targetPath) && !$force) {
            throw new RuntimeException('Target .env already exists; pass --force to overwrite');
        }

        File::ensureDirectoryExists(dirname($targetPath));
        if (File::put($targetPath, $contents) === false) {
            throw new RuntimeException('Failed to write extracted .env file');
        }
    }

    public function writeEmbeddedFiles(array $files, string $targetDir, bool $force = false): array
    {
        $targetDir = rtrim($this->normalizePath($targetDir), DIRECTORY_SEPARATOR);
        if ($targetDir === '') {
            throw new RuntimeException('Target recovery file directory is empty');
        }

        $written = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $name = $this->normalizeRecoveryFileName((string) ($file['name'] ?? ''));
            $contents = $file['contents'] ?? null;
            if ($name === '' || !is_string($contents)) {
                continue;
            }

            $targetPath = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
            if (File::exists($targetPath) && !$force) {
                throw new RuntimeException("Target recovery file {$name} already exists; pass --force to overwrite");
            }

            File::ensureDirectoryExists(dirname($targetPath));
            if (File::put($targetPath, $contents) === false) {
                throw new RuntimeException("Failed to write extracted recovery file {$name}");
            }

            $written[] = [
                'name' => $name,
                'path' => $targetPath,
                'bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ];
        }

        return $written;
    }

    public function writeDatabaseArtifact(string $path, string $targetPath, bool $force = false): array
    {
        $path = $this->normalizePath($path);
        $targetPath = $this->normalizePath($targetPath);
        if (File::exists($targetPath) && !$force) {
            throw new RuntimeException('Target database backup already exists; pass --force to overwrite');
        }

        $artifact = $this->prepareArtifact($path);

        try {
            File::ensureDirectoryExists(dirname($targetPath));
            if (!File::copy($artifact['database_path'], $targetPath)) {
                throw new RuntimeException('Failed to extract database backup');
            }

            return [
                'path' => $targetPath,
                'size' => File::size($targetPath),
                'sha256' => hash_file('sha256', $targetPath),
            ];
        } finally {
            $this->cleanupTemporaryFiles($artifact['temporary_files']);
        }
    }

    public function writeBundledResources(string $path, string $targetDirectory, bool $force = false): array
    {
        $path = $this->normalizePath($path);
        $targetDirectory = rtrim($this->normalizePath($targetDirectory), DIRECTORY_SEPARATOR);
        if (File::isDirectory($targetDirectory) && !$force) {
            $entries = array_values(array_diff(scandir($targetDirectory) ?: [], ['.', '..']));
            if ($entries !== []) {
                throw new RuntimeException('Target resource directory is not empty; pass --force to continue');
            }
        }

        $artifact = $this->prepareArtifact($path);

        try {
            if (!is_string($artifact['bundle_path']) || $artifact['bundle_path'] === '') {
                throw new RuntimeException('This backup does not contain a recovery resource bundle');
            }

            return app(BackupBundleService::class)->extractResources(
                $artifact['bundle_path'],
                $targetDirectory
            );
        } finally {
            $this->cleanupTemporaryFiles($artifact['temporary_files']);
        }
    }

    private function prepareArtifact(string $path): array
    {
        $encryption = app(BackupEncryptionService::class);
        $bundles = app(BackupBundleService::class);
        $temporaryFiles = [];
        $workingPath = $path;
        $encrypted = false;
        $cipher = null;
        $keyFingerprint = null;

        try {
            if ($encryption->isEncryptedFile($workingPath)) {
                if (!$encryption->available()) {
                    throw new RuntimeException('Backup encryption key or OpenSSL support is unavailable');
                }

                $decryptedPath = $this->temporaryRecoveryPath('.decrypted');
                $details = $encryption->decryptFile($workingPath, $decryptedPath);
                $temporaryFiles[] = $decryptedPath;
                $workingPath = $decryptedPath;
                $encrypted = true;
                $cipher = (string) ($details['cipher'] ?? '');
                $keyFingerprint = $details['key_fingerprint'] ?? null;
            }

            $bundle = null;
            $bundlePath = null;
            $databasePath = $workingPath;
            $format = 'legacy-sql-gzip-v1';

            if ($bundles->isBundleFile($workingPath)) {
                $bundle = $bundles->inspect($workingPath);
                $databasePath = $this->temporaryRecoveryPath('.sql.gz');
                $bundles->extractDatabase($workingPath, $databasePath);
                $temporaryFiles[] = $databasePath;
                $bundlePath = $workingPath;
                $format = (string) ($bundle['format'] ?? BackupBundleService::FORMAT);
            }

            return [
                'format' => $format,
                'encrypted' => $encrypted,
                'cipher' => $cipher,
                'key_fingerprint' => $keyFingerprint,
                'bundle' => $bundle,
                'bundle_path' => $bundlePath,
                'database_path' => $databasePath,
                'temporary_files' => $temporaryFiles,
            ];
        } catch (\Throwable $e) {
            $this->cleanupTemporaryFiles($temporaryFiles);
            throw $e;
        }
    }

    private function artifactRestoreCommands(string $path, string $connection, bool $hasEnv, array $artifact): array
    {
        if (!$artifact['encrypted'] && $artifact['bundle'] === null) {
            return $this->restoreCommands($path, $connection, $hasEnv);
        }

        $quotedPath = $this->shellQuote(str_replace('\\', '/', $path));
        $databasePath = str_replace('\\', '/', $this->recoveryStoragePath('backup/restore/database.sql.gz'));
        $resourcePath = str_replace('\\', '/', $this->recoveryStoragePath('backup/restore/resources'));
        $supportPath = str_replace('\\', '/', $this->recoveryStoragePath('backup/restore/support'));
        $extract = "php artisan backup:restore-plan {$quotedPath}"
            . ' --extract-database=' . $this->shellQuote($databasePath)
            . ' --extract-files=' . $this->shellQuote($supportPath);

        if ($hasEnv) {
            $extract .= ' --extract-env=' . $this->shellQuote('.env.restored');
        }
        if ((array) data_get($artifact, 'bundle.resources', []) !== []) {
            $extract .= ' --extract-resources=' . $this->shellQuote($resourcePath);
        }

        $commands = [$extract, 'php artisan down'];
        $quotedDatabasePath = $this->shellQuote($databasePath);
        if ($connection === 'sqlite') {
            $commands[] = 'gzip -dc ' . $quotedDatabasePath . ' | sqlite3 "database/database.sqlite"';
        } else {
            $commands[] = 'gzip -dc ' . $quotedDatabasePath . ' | mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p "$DB_DATABASE"';
        }
        $commands[] = 'php artisan migrate --force';
        $commands[] = 'php artisan optimize:clear';
        $commands[] = 'php artisan up';

        return $commands;
    }

    private function temporaryRecoveryPath(string $suffix = ''): string
    {
        $directory = $this->recoveryStoragePath('backup/tmp');
        File::ensureDirectoryExists($directory);

        return $directory . DIRECTORY_SEPARATOR . 'restore-' . bin2hex(random_bytes(12)) . $suffix;
    }

    private function cleanupTemporaryFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                File::delete($path);
            }
        }
    }

    private function recoveryStoragePath(string $path = ''): string
    {
        $app = app();
        if (method_exists($app, 'storagePath')) {
            return $app->storagePath($path);
        }

        $base = getcwd() . DIRECTORY_SEPARATOR . 'storage';
        $path = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . $path;
    }

    private function readGzipPreview(string $path): array
    {
        if (!function_exists('gzopen')) {
            return [false, '', 'PHP gzip extension is not available'];
        }

        $handle = @gzopen($path, 'rb');
        if (!$handle) {
            return [false, '', 'Failed to open compressed backup'];
        }

        $preview = '';
        try {
            while (!gzeof($handle) && strlen($preview) < 1024 * 1024) {
                $chunk = gzread($handle, 65536);
                if ($chunk === false) {
                    return [false, $preview, 'Failed to read compressed backup'];
                }
                $preview .= $chunk;
            }
        } finally {
            gzclose($handle);
        }

        return [$preview !== '', $preview, $preview !== '' ? null : 'Compressed backup is empty'];
    }

    private function parseRecoveryMetadata(string $preview): array
    {
        $metadata = [
            'files' => [],
        ];
        $collectingEnv = false;
        $collectingFile = false;
        $envBase64 = '';
        $fileBase64 = '';
        $currentFile = null;

        foreach (preg_split('/\r\n|\r|\n/', $preview) ?: [] as $line) {
            if ($line === '-- KELI_RECOVERY_END') {
                break;
            }

            if ($line === '-- KELI_RECOVERY_ENV_BASE64_BEGIN') {
                $collectingEnv = true;
                continue;
            }

            if ($line === '-- KELI_RECOVERY_ENV_BASE64_END') {
                $collectingEnv = false;
                $metadata['env_base64'] = $envBase64;
                continue;
            }

            if ($line === '-- KELI_RECOVERY_FILE_BASE64_BEGIN' && $currentFile !== null) {
                $collectingFile = true;
                $fileBase64 = '';
                continue;
            }

            if ($line === '-- KELI_RECOVERY_FILE_BASE64_END' && $currentFile !== null) {
                $collectingFile = false;
                $currentFile['base64'] = $fileBase64;
                continue;
            }

            if ($line === '-- KELI_RECOVERY_FILE_END' && $currentFile !== null) {
                $metadata['files'][] = $currentFile;
                $currentFile = null;
                $collectingFile = false;
                $fileBase64 = '';
                continue;
            }

            if ($collectingEnv && str_starts_with($line, '-- ')) {
                $envBase64 .= substr($line, 3);
                continue;
            }

            if ($collectingFile && str_starts_with($line, '-- ')) {
                $fileBase64 .= substr($line, 3);
                continue;
            }

            if (str_starts_with($line, '-- KELI_RECOVERY_FORMAT=')) {
                $metadata['format'] = substr($line, strlen('-- KELI_RECOVERY_FORMAT='));
            } elseif (str_starts_with($line, '-- KELI_RECOVERY_DATABASE_CONNECTION=')) {
                $metadata['database_connection'] = substr($line, strlen('-- KELI_RECOVERY_DATABASE_CONNECTION='));
            } elseif (str_starts_with($line, '-- KELI_RECOVERY_GENERATED_AT=')) {
                $metadata['generated_at'] = substr($line, strlen('-- KELI_RECOVERY_GENERATED_AT='));
            } elseif (str_starts_with($line, '-- KELI_RECOVERY_ENV_FILE=')) {
                $metadata['env_file'] = substr($line, strlen('-- KELI_RECOVERY_ENV_FILE='));
            } elseif (str_starts_with($line, '-- KELI_RECOVERY_FILES=')) {
                $metadata['files_count'] = (int) substr($line, strlen('-- KELI_RECOVERY_FILES='));
            } elseif (str_starts_with($line, '-- KELI_RECOVERY_FILE_BEGIN=')) {
                $currentFile = [
                    'name' => substr($line, strlen('-- KELI_RECOVERY_FILE_BEGIN=')),
                ];
            } elseif (str_starts_with($line, '-- KELI_RECOVERY_FILE_BYTES=') && $currentFile !== null) {
                $currentFile['expected_bytes'] = (int) substr($line, strlen('-- KELI_RECOVERY_FILE_BYTES='));
            } elseif (str_starts_with($line, '-- KELI_RECOVERY_FILE_SHA256=') && $currentFile !== null) {
                $currentFile['expected_sha256'] = strtolower(substr($line, strlen('-- KELI_RECOVERY_FILE_SHA256=')));
            }
        }

        return $metadata;
    }

    private function formatRecoveryFiles(array $files): array
    {
        $result = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $name = $this->normalizeRecoveryFileName((string) ($file['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $decoded = base64_decode((string) ($file['base64'] ?? ''), true);
            $contents = is_string($decoded) ? $decoded : '';
            $sha256 = is_string($decoded) ? hash('sha256', $contents) : null;
            $expectedSha256 = strtolower(trim((string) ($file['expected_sha256'] ?? '')));
            $expectedBytes = (int) ($file['expected_bytes'] ?? 0);

            $result[] = [
                'name' => $name,
                'bytes' => strlen($contents),
                'expected_bytes' => $expectedBytes > 0 ? $expectedBytes : null,
                'sha256' => $sha256,
                'expected_sha256' => $expectedSha256 !== '' ? $expectedSha256 : null,
                'checksum_ok' => $expectedSha256 !== '' && $sha256 !== null ? hash_equals($expectedSha256, $sha256) : null,
                'contents' => $contents,
            ];
        }

        return $result;
    }

    private function looksLikeSqlDump(string $preview): bool
    {
        return (bool) preg_match(
            '/\b(CREATE|INSERT|DROP|ALTER|PRAGMA|BEGIN TRANSACTION|SET SQL_MODE|LOCK TABLES)\b/i',
            $preview
        );
    }

    private function restoreCommands(string $path, string $connection, bool $hasEnv): array
    {
        $quotedPath = $this->shellQuote(str_replace('\\', '/', $path));
        $commands = [];
        if ($hasEnv) {
            $commands[] = "gzip -dc {$quotedPath} | sed -n '/^-- KELI_RECOVERY_ENV_BASE64_BEGIN$/,/^-- KELI_RECOVERY_ENV_BASE64_END$/p' | sed '1d;\$d;s/^-- //' | tr -d '\\n' | base64 -d > .env";
        }
        $commands[] = 'php artisan down';

        if ($connection === 'sqlite') {
            $commands[] = "gzip -dc {$quotedPath} | sqlite3 \"database/database.sqlite\"";
        } else {
            $commands[] = "gzip -dc {$quotedPath} | mysql -h \"\$DB_HOST\" -P \"\${DB_PORT:-3306}\" -u \"\$DB_USERNAME\" -p \"\$DB_DATABASE\"";
        }

        $commands[] = 'php artisan migrate --force';
        $commands[] = 'php artisan optimize:clear';
        $commands[] = 'php artisan up';

        return $commands;
    }

    private function warnings(string $expectedChecksum, bool $gzipOk, string $preview, array $metadata, ?array $env, array $files): array
    {
        $warnings = [];
        if ($expectedChecksum === '') {
            $warnings[] = 'No expected SHA256 was provided; checksum can only be displayed, not compared.';
        }
        if (!$gzipOk) {
            $warnings[] = 'Backup gzip stream could not be read.';
        } elseif (!$this->looksLikeSqlDump($preview)) {
            $warnings[] = 'Compressed content does not look like a SQL dump.';
        }
        if (($metadata['format'] ?? null) !== 'env-base64-v1') {
            $warnings[] = 'Backup does not contain Keli env recovery metadata.';
        }
        if ($env === null) {
            $warnings[] = 'Backup does not contain an embedded .env file.';
        }
        $expectedFiles = (int) ($metadata['files_count'] ?? count($files));
        if ($expectedFiles > count($files)) {
            $warnings[] = 'Some embedded recovery support files could not be decoded.';
        }
        foreach ($files as $file) {
            if (($file['checksum_ok'] ?? null) === false) {
                $warnings[] = 'Embedded recovery file checksum failed: ' . (string) ($file['name'] ?? '');
            }
        }

        return $warnings;
    }

    private function drillCheck(string $key, bool $ok, string $message, bool $warning = false): array
    {
        return [
            'key' => $key,
            'ok' => $ok,
            'warning' => $warning,
            'message' => $message,
        ];
    }

    private function parseEnvironmentContents(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $value = trim($value);
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private function normalizeRecoveryFileName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name));
        $name = preg_replace('#/+#', '/', $name) ?: '';
        $name = trim($name, '/');
        if (
            $name === ''
            || str_contains($name, '..')
            || preg_match('/^[A-Za-z]:/', $name)
        ) {
            return '';
        }

        return $name;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Backup path is empty');
        }

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }
}

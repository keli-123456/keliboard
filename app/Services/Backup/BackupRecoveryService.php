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
            ],
            'env' => $env ?: [
                'present' => false,
                'bytes' => 0,
                'sha256' => null,
                'contents' => null,
            ],
            'restore_commands' => $this->restoreCommands($path, $connection, $env !== null),
            'warnings' => $this->warnings($expectedChecksum, $gzipOk, $preview, $metadata, $env),
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
        $metadata = [];
        $collectingEnv = false;
        $envBase64 = '';

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

            if ($collectingEnv && str_starts_with($line, '-- ')) {
                $envBase64 .= substr($line, 3);
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
            }
        }

        return $metadata;
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

    private function warnings(string $expectedChecksum, bool $gzipOk, string $preview, array $metadata, ?array $env): array
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

        return $warnings;
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

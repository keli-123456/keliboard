<?php

namespace App\Services\Backup;

use App\Models\BackupRecord;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\Sqlite;
use Throwable;

class BackupService
{
    private const BACKUP_DIR = 'backup';
    private const BACKUP_LOCK_KEY = 'backup:database:running';
    private const DEFAULT_AUTO_TIME = '03:30';
    private const DEFAULT_AUTO_KEEP = 7;
    private const DISK_GOOGLE_CLOUD = 'google_cloud';
    private const DISK_FTP = 'ftp';
    private const REMOTE_DISKS = [
        self::DISK_GOOGLE_CLOUD,
        self::DISK_FTP,
    ];

    public function createDatabaseBackup(bool $upload = false, array $options = []): array
    {
        if (!Cache::add(self::BACKUP_LOCK_KEY, time(), now()->addHour())) {
            throw new RuntimeException('A database backup is already running');
        }

        $record = null;
        $databaseBackupPath = null;
        $compressedBackupPath = null;

        try {
            $remoteDisk = $this->normalizeRemoteDisk((string) ($options['remote_disk'] ?? self::DISK_GOOGLE_CLOUD));
            $backupRoot = $this->ensureBackupDirectory();
            $database = $this->databaseNameForFilename();
            $filename = now()->format('Y-m-d_H-i-s') . '_' . $database . '_database_backup.sql';
            $databaseBackupPath = $backupRoot . DIRECTORY_SEPARATOR . $filename;
            $compressedBackupPath = $databaseBackupPath . '.gz';

            $record = $this->createRecord([
                'type' => BackupRecord::TYPE_DATABASE,
                'status' => BackupRecord::STATUS_RUNNING,
                'disk' => 'local',
                'filename' => basename($compressedBackupPath),
                'path' => $this->relativeStoragePath($compressedBackupPath),
                'options' => [
                    'database_connection' => config('database.default'),
                    'upload' => $upload,
                    ...$options,
                    'remote_disk' => $upload ? $remoteDisk : null,
                ],
                'started_at' => time(),
            ]);

            $this->dumpDatabase($databaseBackupPath);
            $this->compressGzip($databaseBackupPath, $compressedBackupPath);
            File::delete($databaseBackupPath);

            $size = File::size($compressedBackupPath);
            $checksum = hash_file('sha256', $compressedBackupPath) ?: null;
            $remotePath = null;
            $status = BackupRecord::STATUS_SUCCEEDED;
            $disk = 'local';

            if ($upload) {
                $remotePath = $this->uploadToRemoteDisk($compressedBackupPath, $remoteDisk);
                File::delete($compressedBackupPath);
                $status = BackupRecord::STATUS_UPLOADED;
                $disk = $remoteDisk;
            }

            $record = $this->updateRecord($record, [
                'status' => $status,
                'disk' => $disk,
                'remote_path' => $remotePath,
                'size' => $size,
                'checksum' => $checksum,
                'finished_at' => time(),
                'error' => null,
            ]);

            Log::channel('backup')->info('Database backup completed', [
                'id' => $record?->id,
                'path' => $this->relativeStoragePath($compressedBackupPath),
                'remote_path' => $remotePath,
                'size' => $size,
            ]);

            return $record ? $this->formatRecord($record) : [
                'status' => $status,
                'disk' => $disk,
                'filename' => basename($compressedBackupPath),
                'path' => $upload ? null : $this->relativeStoragePath($compressedBackupPath),
                'remote_path' => $remotePath,
                'size' => $size,
                'checksum' => $checksum,
            ];
        } catch (Throwable $e) {
            $this->updateRecord($record, [
                'status' => BackupRecord::STATUS_FAILED,
                'error' => $this->truncateError($e->getMessage()),
                'finished_at' => time(),
            ]);
            if ($databaseBackupPath) {
                File::delete($databaseBackupPath);
            }
            if ($compressedBackupPath && File::exists($compressedBackupPath)) {
                File::delete($compressedBackupPath);
            }
            Log::channel('backup')->error('Database backup failed', ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            Cache::forget(self::BACKUP_LOCK_KEY);
        }
    }

    public function overview(): array
    {
        $hasTable = $this->recordingAvailable();
        $backupRoot = storage_path(self::BACKUP_DIR);
        $query = $hasTable ? BackupRecord::query() : null;
        $localSucceeded = $hasTable ? (clone $query)
            ->where('type', BackupRecord::TYPE_DATABASE)
            ->where('disk', 'local')
            ->where('status', BackupRecord::STATUS_SUCCEEDED) : null;

        return [
            'database_connection' => config('database.default'),
            'backup_path' => $backupRoot,
            'backup_path_exists' => File::exists($backupRoot),
            'backup_path_writable' => File::exists($backupRoot) ? is_writable($backupRoot) : is_writable(storage_path()),
            'metadata_ready' => $hasTable,
            'gzip_ready' => function_exists('gzopen'),
            'google_cloud_ready' => $this->googleCloudReady(),
            'ftp_ready' => $this->ftpReady(),
            'remote_disks' => $this->remoteDisks(),
            'running' => $hasTable ? (clone $query)->where('status', BackupRecord::STATUS_RUNNING)->count() : 0,
            'total' => $hasTable ? (clone $query)->count() : 0,
            'local_total_size' => $localSucceeded ? (int) $localSucceeded->sum('size') : 0,
            'latest' => $hasTable ? $this->formatRecord((clone $query)->latest('id')->first()) : null,
            'latest_auto' => $this->formatRecord($this->latestAutomaticRecord()),
            'settings' => $this->settings(),
        ];
    }

    public function settings(): array
    {
        $enabled = (bool) admin_setting('backup_auto_enable', false);
        $time = $this->normalizeTime((string) admin_setting('backup_auto_time', self::DEFAULT_AUTO_TIME));
        $keep = $this->normalizeKeep(admin_setting('backup_auto_keep', self::DEFAULT_AUTO_KEEP));
        $remoteDisk = $this->normalizeRemoteDisk((string) admin_setting('backup_auto_remote_disk', self::DISK_GOOGLE_CLOUD));
        $upload = (bool) admin_setting('backup_auto_upload', false);
        if ($upload && !$this->remoteDiskReady($remoteDisk)) {
            $upload = false;
        }

        return [
            'enabled' => $enabled,
            'time' => $time,
            'keep' => $keep,
            'remote_disk' => $remoteDisk,
            'remote_disks' => $this->remoteDisks(),
            'upload' => $upload,
            'timezone' => (string) config('app.timezone'),
            'next_run_at' => $enabled ? $this->nextRunAt($time) : null,
        ];
    }

    public function updateSettings(array $settings): array
    {
        $time = $this->normalizeTime((string) ($settings['time'] ?? self::DEFAULT_AUTO_TIME));
        $keep = $this->normalizeKeep($settings['keep'] ?? self::DEFAULT_AUTO_KEEP);
        $remoteDisk = $this->normalizeRemoteDisk((string) ($settings['remote_disk'] ?? admin_setting('backup_auto_remote_disk', self::DISK_GOOGLE_CLOUD)));
        $upload = (bool) ($settings['upload'] ?? false);
        if ($upload && !$this->remoteDiskReady($remoteDisk)) {
            throw new RuntimeException($this->remoteDiskLabel($remoteDisk) . ' backup config is incomplete');
        }

        admin_setting([
            'backup_auto_enable' => (int) (bool) ($settings['enabled'] ?? false),
            'backup_auto_time' => $time,
            'backup_auto_keep' => $keep,
            'backup_auto_remote_disk' => $remoteDisk,
            'backup_auto_upload' => (int) $upload,
        ]);

        return $this->settings();
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = max(10, min(100, (int) ($filters['page_size'] ?? 20)));
        $page = max(1, (int) ($filters['current'] ?? 1));
        if (!$this->recordingAvailable()) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $query = BackupRecord::query()->orderByDesc('id');
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $query->where('type', $type);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function formatRecord(?BackupRecord $record): ?array
    {
        if (!$record) {
            return null;
        }

        $path = trim((string) $record->path);
        $localPath = $path !== '' ? storage_path($path) : '';
        $exists = $localPath !== '' && File::exists($localPath);

        return [
            'id' => (int) $record->id,
            'type' => (string) $record->type,
            'status' => (string) $record->status,
            'disk' => (string) $record->disk,
            'filename' => (string) $record->filename,
            'path' => $path,
            'remote_path' => $record->remote_path,
            'size' => (int) $record->size,
            'checksum' => $record->checksum,
            'options' => $record->options ?: [],
            'error' => $record->error,
            'exists' => $exists,
            'downloadable' => $exists && $record->disk === 'local' && $record->status === BackupRecord::STATUS_SUCCEEDED,
            'started_at' => $record->started_at,
            'finished_at' => $record->finished_at,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    public function findDownloadable(int $id): BackupRecord
    {
        $record = BackupRecord::query()->findOrFail($id);
        if ($record->disk !== 'local' || $record->status !== BackupRecord::STATUS_SUCCEEDED) {
            throw new RuntimeException('Backup is not downloadable');
        }
        $this->resolveLocalPath($record);
        return $record;
    }

    public function localPath(BackupRecord $record): string
    {
        return $this->resolveLocalPath($record);
    }

    public function verifyBackup(int $id): array
    {
        $record = BackupRecord::query()->findOrFail($id);
        if ($record->disk !== 'local' || $record->status !== BackupRecord::STATUS_SUCCEEDED) {
            throw new RuntimeException('Only successful local backups can be verified');
        }

        $checks = [];
        $path = null;

        try {
            $path = $this->resolveLocalPath($record);
            $checks[] = $this->verificationCheck('path', true, 'Backup file path is safe and readable');
        } catch (Throwable $e) {
            $checks[] = $this->verificationCheck('path', false, $e->getMessage());

            return $this->formatVerificationResult($record, $checks, null);
        }

        $actualSize = File::size($path);
        $expectedSize = (int) $record->size;
        $checks[] = $this->verificationCheck(
            'size',
            $expectedSize > 0 && $actualSize === $expectedSize,
            'Backup file size matches metadata',
            $expectedSize,
            $actualSize
        );

        $expectedChecksum = strtolower(trim((string) $record->checksum));
        $actualChecksum = strtolower((string) hash_file('sha256', $path));
        $checks[] = $this->verificationCheck(
            'checksum',
            $expectedChecksum !== '' && hash_equals($expectedChecksum, $actualChecksum),
            'Backup SHA256 checksum matches metadata',
            $expectedChecksum,
            $actualChecksum
        );

        [$gzipOk, $preview, $gzipError] = $this->readGzipPreview($path);
        $checks[] = $this->verificationCheck(
            'gzip',
            $gzipOk,
            $gzipOk ? 'Compressed backup can be read' : $gzipError
        );

        $looksLikeSql = $preview !== '' && $this->looksLikeSqlDump($preview);
        $checks[] = $this->verificationCheck(
            'sql_dump',
            $looksLikeSql,
            $looksLikeSql ? 'Compressed content looks like a SQL dump' : 'Compressed content does not look like a SQL dump'
        );

        return $this->formatVerificationResult($record, $checks, $path);
    }

    public function deleteBackup(int $id): void
    {
        $record = BackupRecord::query()->findOrFail($id);
        if ($record->disk === 'local') {
            try {
                File::delete($this->resolveLocalPath($record, false));
            } catch (Throwable) {
                // Missing files should not block metadata cleanup.
            }
        }
        $record->delete();
    }

    public function pruneLocalBackups(int $keep): array
    {
        $keep = max(1, $keep);
        $records = BackupRecord::query()
            ->where('type', BackupRecord::TYPE_DATABASE)
            ->where('disk', 'local')
            ->where('status', BackupRecord::STATUS_SUCCEEDED)
            ->orderByDesc('id')
            ->skip($keep)
            ->take(500)
            ->get();

        $deleted = 0;
        $freed = 0;
        foreach ($records as $record) {
            $freed += (int) $record->size;
            $this->deleteBackup((int) $record->id);
            $deleted++;
        }

        return ['deleted' => $deleted, 'freed' => $freed, 'keep' => $keep];
    }

    private function latestAutomaticRecord(): ?BackupRecord
    {
        if (!$this->recordingAvailable()) {
            return null;
        }

        return BackupRecord::query()
            ->where('type', BackupRecord::TYPE_DATABASE)
            ->latest('id')
            ->limit(200)
            ->get()
            ->first(fn(BackupRecord $record) => data_get($record->options, 'trigger') === 'schedule');
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            return self::DEFAULT_AUTO_TIME;
        }

        return $time;
    }

    private function normalizeKeep(mixed $keep): int
    {
        return max(1, min(365, (int) $keep));
    }

    private function googleCloudReady(): bool
    {
        return filled(config('cloud_storage.google_cloud.key_file')) && filled(config('cloud_storage.google_cloud.storage_bucket'));
    }

    private function ftpReady(): bool
    {
        return function_exists('ftp_connect')
            && filled(config('cloud_storage.ftp.host'))
            && filled(config('cloud_storage.ftp.username'))
            && (int) config('cloud_storage.ftp.port', 21) > 0;
    }

    private function remoteDiskReady(string $disk): bool
    {
        return match ($this->normalizeRemoteDisk($disk)) {
            self::DISK_FTP => $this->ftpReady(),
            default => $this->googleCloudReady(),
        };
    }

    private function remoteDisks(): array
    {
        return array_map(fn(string $disk) => [
            'disk' => $disk,
            'ready' => $this->remoteDiskReady($disk),
        ], self::REMOTE_DISKS);
    }

    private function normalizeRemoteDisk(string $disk): string
    {
        return in_array($disk, self::REMOTE_DISKS, true) ? $disk : self::DISK_GOOGLE_CLOUD;
    }

    private function remoteDiskLabel(string $disk): string
    {
        return match ($this->normalizeRemoteDisk($disk)) {
            self::DISK_FTP => 'FTP',
            default => 'Google Cloud Storage',
        };
    }

    private function nextRunAt(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $next = now()->setTime($hour, $minute);
        if ($next->lessThanOrEqualTo(now())) {
            $next->addDay();
        }

        return $next->timestamp;
    }

    private function createRecord(array $attributes): ?BackupRecord
    {
        if (!$this->recordingAvailable()) {
            return null;
        }

        return BackupRecord::query()->create($attributes);
    }

    private function updateRecord(?BackupRecord $record, array $attributes): ?BackupRecord
    {
        if (!$record) {
            return null;
        }

        $record->forceFill($attributes)->save();
        return $record->refresh();
    }

    private function recordingAvailable(): bool
    {
        try {
            return Schema::hasTable('v2_backup_record');
        } catch (Throwable) {
            return false;
        }
    }

    private function ensureBackupDirectory(): string
    {
        $path = storage_path(self::BACKUP_DIR);
        File::ensureDirectoryExists($path);
        if (!is_writable($path)) {
            throw new RuntimeException('Backup directory is not writable');
        }

        return $path;
    }

    private function databaseNameForFilename(): string
    {
        $connection = config('database.default');
        $database = $connection === 'sqlite'
            ? 'sqlite'
            : (string) config("database.connections.{$connection}.database", $connection);

        return trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $database) ?: 'database', '.-_') ?: 'database';
    }

    private function dumpDatabase(string $path): void
    {
        $connection = config('database.default');
        if ($connection === 'mysql') {
            MySql::create()
                ->setHost(config('database.connections.mysql.host'))
                ->setPort(config('database.connections.mysql.port'))
                ->setDbName(config('database.connections.mysql.database'))
                ->setUserName(config('database.connections.mysql.username'))
                ->setPassword(config('database.connections.mysql.password'))
                ->dumpToFile($path);
            return;
        }

        if ($connection === 'sqlite') {
            Sqlite::create()
                ->setDbName(config('database.connections.sqlite.database'))
                ->dumpToFile($path);
            return;
        }

        throw new RuntimeException("Unsupported backup database connection: {$connection}");
    }

    private function compressGzip(string $source, string $target): void
    {
        if (!function_exists('gzopen')) {
            throw new RuntimeException('PHP gzip extension is not available');
        }

        $input = fopen($source, 'rb');
        if (!$input) {
            throw new RuntimeException('Failed to open database dump for compression');
        }

        $output = gzopen($target, 'wb9');
        if (!$output) {
            fclose($input);
            throw new RuntimeException('Failed to open compressed backup file');
        }

        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                gzclose($output);
                fclose($input);
                throw new RuntimeException('Failed to read database dump during compression');
            }
            gzwrite($output, $chunk);
        }

        gzclose($output);
        fclose($input);
    }

    private function uploadToGoogleCloud(string $path): string
    {
        $keyFile = config('cloud_storage.google_cloud.key_file');
        $bucketName = config('cloud_storage.google_cloud.storage_bucket');
        if (blank($keyFile) || blank($bucketName)) {
            throw new RuntimeException('Google Cloud Storage backup config is incomplete');
        }

        $storage = new StorageClient(['keyFilePath' => $keyFile]);
        $bucket = $storage->bucket($bucketName);
        $objectName = 'backup/' . basename($path);
        $bucket->upload(fopen($path, 'r'), ['name' => $objectName]);

        return $objectName;
    }

    private function uploadToRemoteDisk(string $path, string $disk): string
    {
        return match ($this->normalizeRemoteDisk($disk)) {
            self::DISK_FTP => $this->uploadToFtp($path),
            default => $this->uploadToGoogleCloud($path),
        };
    }

    private function uploadToFtp(string $path): string
    {
        if (!function_exists('ftp_connect')) {
            throw new RuntimeException('PHP FTP extension is not available');
        }

        $host = trim((string) config('cloud_storage.ftp.host'));
        $port = max(1, (int) config('cloud_storage.ftp.port', 21));
        $username = trim((string) config('cloud_storage.ftp.username'));
        $password = (string) config('cloud_storage.ftp.password', '');
        $timeout = max(1, (int) config('cloud_storage.ftp.timeout', 30));
        $ssl = (bool) config('cloud_storage.ftp.ssl', false);
        $passive = (bool) config('cloud_storage.ftp.passive', true);

        if ($host === '' || $username === '') {
            throw new RuntimeException('FTP backup config is incomplete');
        }
        if ($ssl && !function_exists('ftp_ssl_connect')) {
            throw new RuntimeException('PHP FTP SSL support is not available');
        }

        $connection = $ssl ? @ftp_ssl_connect($host, $port, $timeout) : @ftp_connect($host, $port, $timeout);
        if (!$connection) {
            throw new RuntimeException('Failed to connect to FTP backup server');
        }

        try {
            if (!@ftp_login($connection, $username, $password)) {
                throw new RuntimeException('Failed to login to FTP backup server');
            }
            @ftp_pasv($connection, $passive);

            $remoteRoot = $this->normalizeRemotePath((string) config('cloud_storage.ftp.root', 'backup'));
            $this->ensureFtpDirectory($connection, $remoteRoot);
            $remotePath = ($remoteRoot !== '' ? rtrim($remoteRoot, '/') . '/' : '') . basename($path);

            if (!@ftp_put($connection, $remotePath, $path, FTP_BINARY)) {
                throw new RuntimeException('Failed to upload backup to FTP server');
            }

            return $remotePath;
        } finally {
            @ftp_close($connection);
        }
    }

    private function ensureFtpDirectory($connection, string $path): void
    {
        if ($path === '') {
            return;
        }

        $current = str_starts_with($path, '/') ? '/' : '';
        foreach (explode('/', trim($path, '/')) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $current = $current === '' || $current === '/' ? $current . $segment : $current . '/' . $segment;
            @ftp_mkdir($connection, $current);
        }
    }

    private function normalizeRemotePath(string $path): string
    {
        $absolute = str_starts_with(trim($path), '/');
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?: '';
        $path = trim($path, '/');
        return $path === '' ? '' : ($absolute ? '/' : '') . $path;
    }

    private function relativeStoragePath(string $path): string
    {
        $storagePath = rtrim(storage_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_replace('\\', '/', str_starts_with($path, $storagePath) ? substr($path, strlen($storagePath)) : $path);
    }

    private function resolveLocalPath(BackupRecord $record, bool $mustExist = true): string
    {
        $path = trim((string) $record->path);
        if ($path === '') {
            throw new RuntimeException('Backup file path is empty');
        }

        $backupRoot = realpath(storage_path(self::BACKUP_DIR)) ?: storage_path(self::BACKUP_DIR);
        $fullPath = storage_path($path);
        $realPath = realpath($fullPath);
        if ($mustExist && (!$realPath || !File::exists($realPath))) {
            throw new RuntimeException('Backup file does not exist');
        }

        $candidate = $realPath ?: $fullPath;
        $normalizedRoot = rtrim(str_replace('\\', '/', $backupRoot), '/') . '/';
        $normalizedCandidate = str_replace('\\', '/', $candidate);
        if (!str_starts_with($normalizedCandidate, $normalizedRoot)) {
            throw new RuntimeException('Backup file path is invalid');
        }

        return $candidate;
    }

    private function verificationCheck(
        string $key,
        bool $ok,
        string $message,
        int|string|null $expected = null,
        int|string|null $actual = null
    ): array {
        return [
            'key' => $key,
            'ok' => $ok,
            'message' => $message,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }

    private function formatVerificationResult(BackupRecord $record, array $checks, ?string $path): array
    {
        $ok = collect($checks)->every(fn(array $check) => (bool) ($check['ok'] ?? false));

        return [
            'id' => (int) $record->id,
            'filename' => (string) $record->filename,
            'disk' => (string) $record->disk,
            'status' => (string) $record->status,
            'ok' => $ok,
            'checked_at' => time(),
            'checks' => $checks,
            'restore' => [
                'local_path' => $path,
                'database_connection' => data_get($record->options ?: [], 'database_connection', config('database.default')),
                'commands' => $path ? $this->restoreCommands($record, $path) : [],
                'notes' => [
                    'Stop queue workers and Octane before restoring.',
                    'Create a fresh backup before restoring over production data.',
                    'Run the restore on a staging copy first when possible.',
                ],
            ],
        ];
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
            while (!gzeof($handle) && strlen($preview) < 262144) {
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

    private function looksLikeSqlDump(string $preview): bool
    {
        return (bool) preg_match(
            '/\b(CREATE|INSERT|DROP|ALTER|PRAGMA|BEGIN TRANSACTION|SET SQL_MODE|LOCK TABLES)\b/i',
            $preview
        );
    }

    private function restoreCommands(BackupRecord $record, string $path): array
    {
        $connection = (string) data_get($record->options ?: [], 'database_connection', config('database.default'));
        $quotedPath = $this->shellQuote(str_replace('\\', '/', $path));

        if ($connection === 'sqlite') {
            return [
                'php artisan down',
                "gzip -dc {$quotedPath} | sqlite3 \"database/database.sqlite\"",
                'php artisan migrate --force',
                'php artisan optimize:clear',
                'php artisan up',
            ];
        }

        return [
            'php artisan down',
            "gzip -dc {$quotedPath} | mysql -h \"\$DB_HOST\" -P \"\${DB_PORT:-3306}\" -u \"\$DB_USERNAME\" -p \"\$DB_DATABASE\"",
            'php artisan migrate --force',
            'php artisan optimize:clear',
            'php artisan up',
        ];
    }

    private function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    private function truncateError(string $error): string
    {
        $error = trim($error);
        return strlen($error) > 2000 ? substr($error, 0, 2000) : $error;
    }
}

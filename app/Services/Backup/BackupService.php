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

    public function createDatabaseBackup(bool $upload = false, array $options = []): array
    {
        if (!Cache::add(self::BACKUP_LOCK_KEY, time(), now()->addHour())) {
            throw new RuntimeException('A database backup is already running');
        }

        $record = null;
        $databaseBackupPath = null;
        $compressedBackupPath = null;

        try {
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
                $remotePath = $this->uploadToGoogleCloud($compressedBackupPath);
                File::delete($compressedBackupPath);
                $status = BackupRecord::STATUS_UPLOADED;
                $disk = 'google_cloud';
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
            'google_cloud_ready' => filled(config('cloud_storage.google_cloud.key_file')) && filled(config('cloud_storage.google_cloud.storage_bucket')),
            'running' => $hasTable ? (clone $query)->where('status', BackupRecord::STATUS_RUNNING)->count() : 0,
            'total' => $hasTable ? (clone $query)->count() : 0,
            'local_total_size' => $localSucceeded ? (int) $localSucceeded->sum('size') : 0,
            'latest' => $hasTable ? $this->formatRecord((clone $query)->latest('id')->first()) : null,
        ];
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

    private function truncateError(string $error): string
    {
        $error = trim($error);
        return strlen($error) > 2000 ? substr($error, 0, 2000) : $error;
    }
}

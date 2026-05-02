<?php

namespace App\Services\Backup;

use App\Models\BackupRecord;
use App\Models\Setting as SettingModel;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
    private const DEFAULT_REMOTE_PREFIX = 'backup';
    private const REMOTE_DISKS = [
        self::DISK_GOOGLE_CLOUD,
        self::DISK_FTP,
    ];
    private const GOOGLE_CLOUD_BUCKET_KEY = 'backup_remote_google_cloud_bucket';
    private const GOOGLE_CLOUD_CREDENTIALS_KEY = 'backup_remote_google_cloud_credentials';
    private const GOOGLE_CLOUD_PREFIX_KEY = 'backup_remote_google_cloud_prefix';
    private const FTP_HOST_KEY = 'backup_remote_ftp_host';
    private const FTP_PORT_KEY = 'backup_remote_ftp_port';
    private const FTP_USERNAME_KEY = 'backup_remote_ftp_username';
    private const FTP_PASSWORD_KEY = 'backup_remote_ftp_password';
    private const FTP_ROOT_KEY = 'backup_remote_ftp_root';
    private const FTP_SSL_KEY = 'backup_remote_ftp_ssl';
    private const FTP_PASSIVE_KEY = 'backup_remote_ftp_passive';
    private const FTP_TIMEOUT_KEY = 'backup_remote_ftp_timeout';
    private const RESTORE_DRILL_STATUSES = ['passed', 'failed', 'incomplete'];
    private const RESTORE_DRILL_ENVIRONMENTS = ['local', 'staging', 'production_rehearsal'];

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
            $this->prependRecoveryMetadata($databaseBackupPath);
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
            'latest_restore_drill' => $this->latestRestoreDrill(),
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
            'remote_storage' => $this->remoteStorageSettings(),
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

    public function remoteStorageSettings(): array
    {
        $google = $this->googleCloudConfig();
        $ftp = $this->ftpConfig();

        return [
            self::DISK_GOOGLE_CLOUD => [
                'bucket' => $google['bucket'],
                'prefix' => $google['prefix'],
                'credentials_configured' => $google['credentials_configured'],
                'panel_configured' => $google['panel_configured'],
                'env_configured' => $google['env_configured'],
                'source' => $google['source'],
                'key_file' => $google['key_file'],
            ],
            self::DISK_FTP => [
                'host' => $ftp['host'],
                'port' => $ftp['port'],
                'username' => $ftp['username'],
                'root' => $ftp['root'],
                'ssl' => $ftp['ssl'],
                'passive' => $ftp['passive'],
                'timeout' => $ftp['timeout'],
                'password_configured' => $ftp['password_configured'],
                'panel_configured' => $ftp['panel_configured'],
                'env_configured' => $ftp['env_configured'],
                'source' => $ftp['source'],
            ],
        ];
    }

    public function updateRemoteStorageSettings(array $payload): array
    {
        $settings = [];
        if (array_key_exists(self::DISK_GOOGLE_CLOUD, $payload) && is_array($payload[self::DISK_GOOGLE_CLOUD])) {
            $google = $payload[self::DISK_GOOGLE_CLOUD];
            $settings[self::GOOGLE_CLOUD_BUCKET_KEY] = trim((string) ($google['bucket'] ?? ''));
            $settings[self::GOOGLE_CLOUD_PREFIX_KEY] = $this->normalizeRemotePath((string) ($google['prefix'] ?? self::DEFAULT_REMOTE_PREFIX)) ?: self::DEFAULT_REMOTE_PREFIX;

            $credentials = trim((string) ($google['credentials_json'] ?? ''));
            if ((bool) ($google['clear_credentials'] ?? false)) {
                $settings[self::GOOGLE_CLOUD_CREDENTIALS_KEY] = '';
            } elseif ($credentials !== '') {
                $this->decodeGoogleCredentials($credentials);
                $settings[self::GOOGLE_CLOUD_CREDENTIALS_KEY] = $this->encryptSecret($credentials);
            }
        }

        if (array_key_exists(self::DISK_FTP, $payload) && is_array($payload[self::DISK_FTP])) {
            $ftp = $payload[self::DISK_FTP];
            $settings[self::FTP_HOST_KEY] = trim((string) ($ftp['host'] ?? ''));
            $settings[self::FTP_PORT_KEY] = max(1, (int) ($ftp['port'] ?? 21));
            $settings[self::FTP_USERNAME_KEY] = trim((string) ($ftp['username'] ?? ''));
            $settings[self::FTP_ROOT_KEY] = $this->normalizeRemotePath((string) ($ftp['root'] ?? self::DEFAULT_REMOTE_PREFIX)) ?: self::DEFAULT_REMOTE_PREFIX;
            $settings[self::FTP_SSL_KEY] = (int) (bool) ($ftp['ssl'] ?? false);
            $settings[self::FTP_PASSIVE_KEY] = (int) (bool) ($ftp['passive'] ?? true);
            $settings[self::FTP_TIMEOUT_KEY] = max(1, min(300, (int) ($ftp['timeout'] ?? 30)));

            $password = (string) ($ftp['password'] ?? '');
            if ((bool) ($ftp['clear_password'] ?? false)) {
                $settings[self::FTP_PASSWORD_KEY] = '';
            } elseif ($password !== '') {
                $settings[self::FTP_PASSWORD_KEY] = $this->encryptSecret($password);
            }
        }

        if ($settings !== []) {
            admin_setting($settings);
        }

        return $this->remoteStoragePayload();
    }

    public function testRemoteStorage(string $disk): array
    {
        $disk = $this->normalizeRemoteDisk($disk);
        if ($disk === self::DISK_GOOGLE_CLOUD) {
            $google = $this->googleCloudConfig();
            if (blank($google['bucket']) || !$google['credentials_configured']) {
                throw new RuntimeException('Google Cloud Storage backup config is incomplete');
            }

            $bucket = $this->googleStorageClient($google)->bucket($google['bucket']);
            if (!$bucket->exists()) {
                throw new RuntimeException('Google Cloud Storage bucket is not accessible');
            }

            return [
                'disk' => self::DISK_GOOGLE_CLOUD,
                'ok' => true,
                'message' => 'Google Cloud Storage connection OK',
                'checked_at' => time(),
            ];
        }

        $ftp = $this->ftpConfig();
        $connection = $this->connectFtp($ftp);
        try {
            $this->ensureFtpDirectory($connection, $ftp['root']);
        } finally {
            @ftp_close($connection);
        }

        return [
            'disk' => self::DISK_FTP,
            'ok' => true,
            'message' => 'FTP connection OK',
            'checked_at' => time(),
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
        $localPath = $path !== '' ? $this->storagePath($path) : '';
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
            'latest_restore_drill' => $this->latestDrillForRecord($record),
            'error' => $record->error,
            'exists' => $exists,
            'downloadable' => $exists && $record->disk === 'local' && $record->status === BackupRecord::STATUS_SUCCEEDED,
            'started_at' => $record->started_at,
            'finished_at' => $record->finished_at,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    public function recordRestoreDrill(int $id, array $payload): array
    {
        $record = BackupRecord::query()->findOrFail($id);
        if ($record->type !== BackupRecord::TYPE_DATABASE) {
            throw new RuntimeException('Only database backups can record restore drills');
        }
        if (in_array($record->status, [BackupRecord::STATUS_RUNNING, BackupRecord::STATUS_FAILED], true)) {
            throw new RuntimeException('Only completed backups can record restore drills');
        }

        $status = $this->normalizeRestoreDrillStatus((string) ($payload['status'] ?? 'incomplete'));
        $environment = $this->normalizeRestoreDrillEnvironment((string) ($payload['environment'] ?? 'staging'));
        $now = time();
        $drill = [
            'id' => $now . '-' . substr(sha1($record->id . '|' . $now . '|' . random_int(1, PHP_INT_MAX)), 0, 8),
            'backup_id' => (int) $record->id,
            'backup_filename' => (string) $record->filename,
            'status' => $status,
            'environment' => $environment,
            'note' => $this->truncateText((string) ($payload['note'] ?? ''), 1000),
            'operator' => $this->truncateText((string) ($payload['operator'] ?? ''), 120),
            'recorded_at' => $now,
        ];

        $options = $record->options ?: [];
        $drills = is_array($options['restore_drills'] ?? null) ? $options['restore_drills'] : [];
        array_unshift($drills, $drill);
        $options['restore_drills'] = array_slice($drills, 0, 20);

        $record->forceFill(['options' => $options])->save();

        return [
            'record' => $this->formatRecord($record->refresh()),
            'drill' => $drill,
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

    public function restorePreflight(int $id): array
    {
        $record = BackupRecord::query()->findOrFail($id);
        $blockers = [];
        $warnings = [];
        $verification = null;
        $currentConnection = (string) config('database.default');
        $backupConnection = (string) data_get($record->options ?: [], 'database_connection', '');
        $runningBackup = $this->backupRunning();
        $maintenanceMode = $this->maintenanceModeEnabled();

        if ($record->disk !== 'local') {
            $blockers[] = $this->preflightIssue(
                'remote_backup',
                'Remote backups must be downloaded to local storage before restore preflight'
            );
        }
        if ($record->status !== BackupRecord::STATUS_SUCCEEDED) {
            $blockers[] = $this->preflightIssue('backup_status', 'Only successful local backups can be restored');
        }

        if ($runningBackup) {
            $blockers[] = $this->preflightIssue('running_backup', 'A backup task is running; wait for it to finish before restoring');
        }

        if ($backupConnection === '') {
            $warnings[] = $this->preflightIssue('backup_connection_unknown', 'Backup database connection is not recorded');
        } elseif ($backupConnection !== $currentConnection) {
            $blockers[] = $this->preflightIssue(
                'database_connection_mismatch',
                "Backup connection {$backupConnection} does not match current connection {$currentConnection}"
            );
        }

        if (!$maintenanceMode) {
            $warnings[] = $this->preflightIssue(
                'maintenance_mode_disabled',
                'Application is not in maintenance mode; stop traffic and workers before restoring'
            );
        }

        try {
            $verification = $this->verifyBackup($id);
            if (!(bool) ($verification['ok'] ?? false)) {
                $blockers[] = $this->preflightIssue('backup_verification_failed', 'Backup file verification did not pass');
            }
        } catch (Throwable $e) {
            $blockers[] = $this->preflightIssue('backup_verification_error', $e->getMessage());
        }

        return [
            'id' => (int) $record->id,
            'filename' => (string) $record->filename,
            'ok' => count($blockers) === 0,
            'checked_at' => time(),
            'database' => [
                'current_connection' => $currentConnection,
                'backup_connection' => $backupConnection !== '' ? $backupConnection : null,
            ],
            'maintenance_mode' => $maintenanceMode,
            'running_backup' => $runningBackup,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'verification' => $verification,
            'restore' => $verification['restore'] ?? null,
        ];
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
        } elseif (in_array($record->disk, self::REMOTE_DISKS, true) && filled($record->remote_path)) {
            $this->deleteRemoteBackup($record);
        }
        $record->delete();
    }

    public function pruneLocalBackups(int $keep): array
    {
        $keep = max(1, $keep);
        $deleted = 0;
        $freed = 0;
        $failed = 0;
        $localDeleted = 0;
        $remoteDeleted = 0;

        foreach (['local', ...self::REMOTE_DISKS] as $disk) {
            $records = BackupRecord::query()
                ->where('type', BackupRecord::TYPE_DATABASE)
                ->where('disk', $disk)
                ->where('status', $disk === 'local' ? BackupRecord::STATUS_SUCCEEDED : BackupRecord::STATUS_UPLOADED)
                ->orderByDesc('id')
                ->skip($keep)
                ->take(500)
                ->get();

            foreach ($records as $record) {
                try {
                    $this->deleteBackup((int) $record->id);
                    $deleted++;
                    $freed += (int) $record->size;
                    if ($disk === 'local') {
                        $localDeleted++;
                    } else {
                        $remoteDeleted++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    Log::channel('backup')->warning('Failed to prune old backup', [
                        'id' => $record->id,
                        'disk' => $record->disk,
                        'remote_path' => $record->remote_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'deleted' => $deleted,
            'freed' => $freed,
            'keep' => $keep,
            'local_deleted' => $localDeleted,
            'remote_deleted' => $remoteDeleted,
            'failed' => $failed,
        ];
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

    private function latestRestoreDrill(): ?array
    {
        if (!$this->recordingAvailable()) {
            return null;
        }

        return BackupRecord::query()
            ->where('type', BackupRecord::TYPE_DATABASE)
            ->latest('updated_at')
            ->limit(200)
            ->get()
            ->map(fn(BackupRecord $record) => $this->latestDrillForRecord($record))
            ->filter()
            ->sortByDesc(fn(array $drill) => (int) ($drill['recorded_at'] ?? 0))
            ->first() ?: null;
    }

    private function latestDrillForRecord(BackupRecord $record): ?array
    {
        $drills = data_get($record->options ?: [], 'restore_drills', []);
        if (!is_array($drills) || $drills === []) {
            return null;
        }

        $latest = collect($drills)
            ->filter(fn($drill) => is_array($drill))
            ->sortByDesc(fn(array $drill) => (int) ($drill['recorded_at'] ?? 0))
            ->first();

        if (!is_array($latest)) {
            return null;
        }

        return [
            'id' => (string) ($latest['id'] ?? ''),
            'backup_id' => (int) ($latest['backup_id'] ?? $record->id),
            'backup_filename' => (string) ($latest['backup_filename'] ?? $record->filename),
            'status' => $this->normalizeRestoreDrillStatus((string) ($latest['status'] ?? 'incomplete')),
            'environment' => $this->normalizeRestoreDrillEnvironment((string) ($latest['environment'] ?? 'staging')),
            'note' => (string) ($latest['note'] ?? ''),
            'operator' => (string) ($latest['operator'] ?? ''),
            'recorded_at' => (int) ($latest['recorded_at'] ?? 0),
        ];
    }

    private function normalizeRestoreDrillStatus(string $status): string
    {
        return in_array($status, self::RESTORE_DRILL_STATUSES, true) ? $status : 'incomplete';
    }

    private function normalizeRestoreDrillEnvironment(string $environment): string
    {
        return in_array($environment, self::RESTORE_DRILL_ENVIRONMENTS, true) ? $environment : 'staging';
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
        $config = $this->googleCloudConfig();
        return filled($config['bucket']) && $config['credentials_configured'];
    }

    private function ftpReady(): bool
    {
        $config = $this->ftpConfig();
        return function_exists('ftp_connect')
            && filled($config['host'])
            && filled($config['username'])
            && (int) $config['port'] > 0;
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

    private function remoteStoragePayload(): array
    {
        return [
            'remote_storage' => $this->remoteStorageSettings(),
            'remote_disks' => $this->remoteDisks(),
            'google_cloud_ready' => $this->googleCloudReady(),
            'ftp_ready' => $this->ftpReady(),
        ];
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

    private function prependRecoveryMetadata(string $path): void
    {
        $metadata = $this->recoveryMetadataSql();
        $input = fopen($path, 'rb');
        if (!$input) {
            throw new RuntimeException('Failed to open database dump for recovery metadata');
        }

        $tempPath = $path . '.recovery';
        $output = fopen($tempPath, 'wb');
        if (!$output) {
            fclose($input);
            throw new RuntimeException('Failed to write database dump recovery metadata');
        }

        try {
            if (fwrite($output, $metadata) === false || stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('Failed to prepend database dump recovery metadata');
            }
        } catch (Throwable $throwable) {
            File::delete($tempPath);
            throw $throwable;
        } finally {
            fclose($output);
            fclose($input);
        }

        File::move($tempPath, $path);
    }

    private function recoveryMetadataSql(): string
    {
        $lines = [
            '-- KELI_RECOVERY_START',
            '-- KELI_RECOVERY_FORMAT=env-base64-v1',
            '-- KELI_RECOVERY_DATABASE_CONNECTION=' . str_replace(["\r", "\n"], '', (string) config('database.default', '')),
            '-- KELI_RECOVERY_GENERATED_AT=' . gmdate('c'),
        ];

        $environmentPath = $this->recoveryEnvironmentFilePath();
        if (File::isFile($environmentPath)) {
            $lines[] = '-- KELI_RECOVERY_ENV_FILE=.env';
            $lines[] = '-- KELI_RECOVERY_ENV_BASE64_BEGIN';

            foreach (str_split(base64_encode(File::get($environmentPath)), 76) as $chunk) {
                $lines[] = '-- ' . $chunk;
            }

            $lines[] = '-- KELI_RECOVERY_ENV_BASE64_END';
        } else {
            $lines[] = '-- KELI_RECOVERY_ENV_FILE=missing';
        }

        $lines[] = '-- KELI_RECOVERY_END';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function recoveryEnvironmentFilePath(): string
    {
        $configuredPath = config('backup.recovery_environment_file');
        if (is_string($configuredPath) && $configuredPath !== '') {
            return $configuredPath;
        }

        $app = app();
        if (method_exists($app, 'environmentFilePath')) {
            return $app->environmentFilePath();
        }

        return getcwd() . DIRECTORY_SEPARATOR . '.env';
    }

    private function uploadToGoogleCloud(string $path): string
    {
        $config = $this->googleCloudConfig();
        if (blank($config['bucket']) || !$config['credentials_configured']) {
            throw new RuntimeException('Google Cloud Storage backup config is incomplete');
        }

        $bucket = $this->googleStorageClient($config)->bucket($config['bucket']);
        $objectName = ($config['prefix'] !== '' ? rtrim($config['prefix'], '/') . '/' : '') . basename($path);
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
        $config = $this->ftpConfig();
        $connection = $this->connectFtp($config);

        try {
            $remoteRoot = $config['root'];
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

    private function deleteRemoteBackup(BackupRecord $record): void
    {
        $remotePath = $this->normalizeRemotePath((string) $record->remote_path);
        if ($remotePath === '') {
            return;
        }

        if ($record->disk === self::DISK_GOOGLE_CLOUD) {
            $config = $this->googleCloudConfig();
            if (blank($config['bucket']) || !$config['credentials_configured']) {
                throw new RuntimeException('Google Cloud Storage backup config is incomplete');
            }

            $object = $this->googleStorageClient($config)->bucket($config['bucket'])->object($remotePath);
            if ($object->exists()) {
                $object->delete();
            }
            return;
        }

        if ($record->disk === self::DISK_FTP) {
            $connection = $this->connectFtp($this->ftpConfig());
            try {
                if (!@ftp_delete($connection, $remotePath)) {
                    $size = @ftp_size($connection, $remotePath);
                    if ($size !== -1) {
                        throw new RuntimeException('Failed to delete backup from FTP server');
                    }
                }
            } finally {
                @ftp_close($connection);
            }
        }
    }

    private function googleCloudConfig(): array
    {
        $credentials = $this->decryptSecret((string) $this->settingValue(self::GOOGLE_CLOUD_CREDENTIALS_KEY, ''));
        $bucket = trim((string) $this->settingValue(self::GOOGLE_CLOUD_BUCKET_KEY, ''));
        $prefix = $this->normalizeRemotePath((string) $this->settingValue(self::GOOGLE_CLOUD_PREFIX_KEY, self::DEFAULT_REMOTE_PREFIX));

        $envKeyFile = (string) config('cloud_storage.google_cloud.key_file', '');
        $envBucket = (string) config('cloud_storage.google_cloud.storage_bucket', '');

        $keyFile = $credentials !== '' ? null : ($envKeyFile !== '' ? $envKeyFile : null);
        $key = $credentials !== '' ? $this->decodeGoogleCredentials($credentials) : null;
        $panelConfigured = $this->settingExists(self::GOOGLE_CLOUD_BUCKET_KEY)
            || $this->settingExists(self::GOOGLE_CLOUD_CREDENTIALS_KEY)
            || $this->settingExists(self::GOOGLE_CLOUD_PREFIX_KEY);
        $envConfigured = filled($envKeyFile) && filled($envBucket);

        return [
            'bucket' => $bucket !== '' ? $bucket : $envBucket,
            'prefix' => $prefix !== '' ? $prefix : self::DEFAULT_REMOTE_PREFIX,
            'key_file' => $keyFile,
            'key' => $key,
            'credentials_configured' => $credentials !== '' || filled($envKeyFile),
            'panel_configured' => $panelConfigured,
            'env_configured' => $envConfigured,
            'source' => $this->configSource($panelConfigured, $envConfigured),
        ];
    }

    private function googleStorageClient(array $config): StorageClient
    {
        if (is_array($config['key'] ?? null)) {
            return new StorageClient(['keyFile' => $config['key']]);
        }

        return new StorageClient(['keyFilePath' => $config['key_file']]);
    }

    private function ftpConfig(): array
    {
        $host = trim((string) $this->settingValue(self::FTP_HOST_KEY, ''));
        $port = (int) $this->settingValue(self::FTP_PORT_KEY, 0);
        $username = trim((string) $this->settingValue(self::FTP_USERNAME_KEY, ''));
        $password = $this->decryptSecret((string) $this->settingValue(self::FTP_PASSWORD_KEY, ''));
        $root = $this->normalizeRemotePath((string) $this->settingValue(self::FTP_ROOT_KEY, ''));
        $ssl = $this->settingValue(self::FTP_SSL_KEY);
        $passive = $this->settingValue(self::FTP_PASSIVE_KEY);
        $timeout = (int) $this->settingValue(self::FTP_TIMEOUT_KEY, 0);

        $panelConfigured = $this->settingExists(self::FTP_HOST_KEY)
            || $this->settingExists(self::FTP_PORT_KEY)
            || $this->settingExists(self::FTP_USERNAME_KEY)
            || $this->settingExists(self::FTP_PASSWORD_KEY)
            || $this->settingExists(self::FTP_ROOT_KEY)
            || $this->settingExists(self::FTP_SSL_KEY)
            || $this->settingExists(self::FTP_PASSIVE_KEY)
            || $this->settingExists(self::FTP_TIMEOUT_KEY);
        $envConfigured = filled(config('cloud_storage.ftp.host'))
            && filled(config('cloud_storage.ftp.username'))
            && (int) config('cloud_storage.ftp.port', 21) > 0;

        return [
            'host' => $host !== '' ? $host : trim((string) config('cloud_storage.ftp.host', '')),
            'port' => $port > 0 ? $port : max(1, (int) config('cloud_storage.ftp.port', 21)),
            'username' => $username !== '' ? $username : trim((string) config('cloud_storage.ftp.username', '')),
            'password' => $password !== '' ? $password : (string) config('cloud_storage.ftp.password', ''),
            'root' => $root !== '' ? $root : $this->normalizeRemotePath((string) config('cloud_storage.ftp.root', self::DEFAULT_REMOTE_PREFIX)),
            'ssl' => $ssl === null ? (bool) config('cloud_storage.ftp.ssl', false) : (bool) $ssl,
            'passive' => $passive === null ? (bool) config('cloud_storage.ftp.passive', true) : (bool) $passive,
            'timeout' => $timeout > 0 ? max(1, min(300, $timeout)) : max(1, (int) config('cloud_storage.ftp.timeout', 30)),
            'password_configured' => $password !== '' || filled(config('cloud_storage.ftp.password', '')),
            'panel_configured' => $panelConfigured,
            'env_configured' => $envConfigured,
            'source' => $this->configSource($panelConfigured, $envConfigured),
        ];
    }

    private function connectFtp(array $config)
    {
        if (!function_exists('ftp_connect')) {
            throw new RuntimeException('PHP FTP extension is not available');
        }
        if ($config['host'] === '' || $config['username'] === '') {
            throw new RuntimeException('FTP backup config is incomplete');
        }
        if ($config['ssl'] && !function_exists('ftp_ssl_connect')) {
            throw new RuntimeException('PHP FTP SSL support is not available');
        }

        $connection = $config['ssl']
            ? @ftp_ssl_connect($config['host'], $config['port'], $config['timeout'])
            : @ftp_connect($config['host'], $config['port'], $config['timeout']);
        if (!$connection) {
            throw new RuntimeException('Failed to connect to FTP backup server');
        }

        try {
            if (!@ftp_login($connection, $config['username'], $config['password'])) {
                throw new RuntimeException('Failed to login to FTP backup server');
            }
            @ftp_pasv($connection, $config['passive']);
            return $connection;
        } catch (Throwable $e) {
            @ftp_close($connection);
            throw $e;
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

    private function settingValue(string $key, mixed $default = null): mixed
    {
        try {
            $value = SettingModel::query()->where('name', strtolower($key))->value('value');
        } catch (Throwable) {
            return $default;
        }

        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function settingExists(string $key): bool
    {
        try {
            return SettingModel::query()->where('name', strtolower($key))->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function encryptSecret(string $value): string
    {
        return Crypt::encryptString($value);
    }

    private function decryptSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return '';
        }
    }

    private function decodeGoogleCredentials(string $json): array
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('Google Cloud credentials JSON is invalid');
        }
        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (blank($decoded[$key] ?? null)) {
                throw new RuntimeException("Google Cloud credentials JSON is missing {$key}");
            }
        }

        return $decoded;
    }

    private function configSource(bool $panelConfigured, bool $envConfigured): string
    {
        if ($panelConfigured && $envConfigured) {
            return 'mixed';
        }
        if ($panelConfigured) {
            return 'panel';
        }
        if ($envConfigured) {
            return 'env';
        }

        return 'none';
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

    private function backupRunning(): bool
    {
        try {
            if (Cache::has(self::BACKUP_LOCK_KEY)) {
                return true;
            }
        } catch (Throwable) {
            // Fall back to metadata when cache is unavailable.
        }

        if (!$this->recordingAvailable()) {
            return false;
        }

        return BackupRecord::query()->where('status', BackupRecord::STATUS_RUNNING)->exists();
    }

    private function maintenanceModeEnabled(): bool
    {
        try {
            return method_exists(app(), 'isDownForMaintenance') && app()->isDownForMaintenance();
        } catch (Throwable) {
            return false;
        }
    }

    private function preflightIssue(string $key, string $message): array
    {
        return [
            'key' => $key,
            'message' => $message,
        ];
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

    private function truncateText(string $value, int $limit): string
    {
        $value = trim($value);
        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }

    private function storagePath(string $path = ''): string
    {
        if (method_exists(app(), 'storagePath')) {
            return storage_path($path);
        }

        $base = getcwd() . DIRECTORY_SEPARATOR . 'storage';
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

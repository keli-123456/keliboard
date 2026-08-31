<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Backup\BackupNotificationService;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {upload? : Whether to upload to remote storage}
        {--disk=google_cloud : Remote backup disk, google_cloud or ftp}
        {--keep=0 : Keep the latest N backups after success}
        {--trigger=manual : Backup trigger source}
        {--sync : Run in the current process instead of the backup queue}
        {--json : Output a single machine-readable result}';
    protected $description = '排队执行数据库备份并记录备份元数据';

    public function handle(BackupService $backups, BackupNotificationService $notifications): int
    {
        $upload = filter_var($this->argument('upload'), FILTER_VALIDATE_BOOLEAN);
        $keep = max(0, (int) $this->option('keep'));
        $trigger = in_array($this->option('trigger'), ['manual', 'schedule'], true)
            ? (string) $this->option('trigger')
            : 'manual';
        $jsonOutput = (bool) $this->option('json');
        $options = [
            'trigger' => $trigger,
            'remote_disk' => (string) $this->option('disk'),
            'keep' => $keep,
        ];

        try {
            if ((bool) $this->option('sync')) {
                if (!$jsonOutput) {
                    $this->info('开始同步备份数据库');
                }
                $record = $backups->createDatabaseBackup($upload, $options);
                if ((bool) data_get($record, 'options.verify_after_backup', true)) {
                    $verification = $backups->verifyBackup((int) ($record['id'] ?? 0), true);
                    if (!($verification['ok'] ?? false)) {
                        throw new \RuntimeException('Automatic backup verification failed');
                    }
                }
                if ($upload && !(bool) data_get($record, 'options.keep_local_after_upload', true)) {
                    $record = $backups->finalizeRemoteOnlyBackup((int) ($record['id'] ?? 0));
                }
                if ($keep > 0) {
                    $result = $backups->pruneLocalBackups($keep);
                    if (!$jsonOutput) {
                        $this->info("已清理 {$result['deleted']} 个旧备份，保留最近 {$result['keep']} 个备份");
                    }
                }
                $path = $record['remote_path'] ?? $record['path'] ?? $record['filename'] ?? null;
                if ($jsonOutput) {
                    $this->line(json_encode([
                        'status' => 'succeeded',
                        'record_id' => (int) ($record['id'] ?? 0),
                        'path' => $path,
                        'checksum' => $record['checksum'] ?? null,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                } else {
                    $this->info('数据库备份完成：' . ($path ?? '-'));
                }
            } else {
                $record = $backups->queueDatabaseBackup($upload, $options);
                if ($jsonOutput) {
                    $this->line(json_encode([
                        'status' => 'queued',
                        'record_id' => (int) ($record['id'] ?? 0),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                } else {
                    $this->info('数据库备份已进入后台队列，记录 ID：' . ($record['id'] ?? '-'));
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ((bool) $this->option('sync')) {
                $notifications->backupFailed(null, $e);
            }
            if ($jsonOutput) {
                $this->line((string) json_encode([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('数据库备份失败：' . $e->getMessage());
            }
            return self::FAILURE;
        }
    }
}

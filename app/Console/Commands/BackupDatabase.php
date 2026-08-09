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
        {--sync : Run in the current process instead of the backup queue}';
    protected $description = '排队执行数据库备份并记录备份元数据';

    public function handle(BackupService $backups, BackupNotificationService $notifications): int
    {
        $upload = filter_var($this->argument('upload'), FILTER_VALIDATE_BOOLEAN);
        $keep = max(0, (int) $this->option('keep'));
        $trigger = in_array($this->option('trigger'), ['manual', 'schedule'], true)
            ? (string) $this->option('trigger')
            : 'manual';
        $options = [
            'trigger' => $trigger,
            'remote_disk' => (string) $this->option('disk'),
            'keep' => $keep,
        ];

        try {
            if ((bool) $this->option('sync')) {
                $this->info('开始同步备份数据库');
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
                    $this->info("已清理 {$result['deleted']} 个旧备份，保留最近 {$result['keep']} 个备份");
                }
                $this->info('数据库备份完成：' . ($record['remote_path'] ?? $record['path'] ?? $record['filename'] ?? '-'));
            } else {
                $record = $backups->queueDatabaseBackup($upload, $options);
                $this->info('数据库备份已进入后台队列，记录 ID：' . ($record['id'] ?? '-'));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ((bool) $this->option('sync')) {
                $notifications->backupFailed(null, $e);
            }
            $this->error('数据库备份失败：' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

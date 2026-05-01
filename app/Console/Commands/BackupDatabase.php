<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database {upload? : Whether to upload to Google Cloud Storage} {--keep=0 : Keep the latest N local database backups after success}';
    protected $description = '备份数据库并记录备份元数据';

    public function handle(BackupService $backups)
    {
        $upload = filter_var($this->argument('upload'), FILTER_VALIDATE_BOOLEAN);
        $keep = (int) $this->option('keep');

        try {
            $this->info('开始备份数据库');
            $record = $backups->createDatabaseBackup($upload);
            $this->info('数据库备份完成：' . ($record['remote_path'] ?? $record['path'] ?? $record['filename'] ?? '-'));

            if ($keep > 0) {
                $result = $backups->pruneLocalBackups($keep);
                $this->info("已清理 {$result['deleted']} 个旧备份，保留最近 {$result['keep']} 个本地备份");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('数据库备份失败：' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

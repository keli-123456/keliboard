<?php

namespace App\Services\Backup;

use App\Models\BackupRecord;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupNotificationService
{
    public function backupSucceeded(array $record): void
    {
        if (!$this->settingEnabled('backup_notify_success', false)) {
            return;
        }

        $this->notify(sprintf(
            "[备份成功]\n文件：%s\n大小：%s\n位置：%s",
            (string) ($record['filename'] ?? '-'),
            $this->formatBytes((int) ($record['size'] ?? 0)),
            (string) ($record['disk'] ?? 'local')
        ));
    }

    public function backupFailed(?BackupRecord $record, ?Throwable $exception): void
    {
        if (!$this->settingEnabled('backup_notify_failure', true)) {
            return;
        }

        $this->notify(sprintf(
            "[备份失败]\n记录：%s\n文件：%s\n原因：%s",
            $record?->id ?? '-',
            $record?->filename ?: '-',
            mb_substr($exception?->getMessage() ?: (string) $record?->error ?: '未知错误', 0, 800)
        ));
    }

    private function notify(string $message): void
    {
        $this->safeLog('Backup notification', ['message' => $message]);

        if (!$this->settingEnabled('telegram_bot_enable', false)) {
            return;
        }

        try {
            app(TelegramService::class)->sendMessageWithAdmin($message);
        } catch (Throwable $e) {
            $this->safeLog('Failed to send backup notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function settingEnabled(string $key, bool $default): bool
    {
        try {
            return (bool) admin_setting($key, $default);
        } catch (Throwable $e) {
            $this->safeLog('Failed to read backup notification setting', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $default;
        }
    }

    private function safeLog(string $message, array $context = []): void
    {
        try {
            Log::channel('backup')->warning($message, $context);
        } catch (Throwable) {
            // Notifications and their logs must never change the backup result.
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $index), 2) . ' ' . $units[$index];
    }
}

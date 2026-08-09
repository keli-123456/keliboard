<?php

namespace App\Jobs;

use App\Models\BackupRecord;
use App\Services\Backup\BackupNotificationService;
use App\Services\Backup\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class BackupDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $recordId,
        public readonly bool $upload = false,
        public readonly array $options = []
    ) {
        $this->onConnection('redis_backup');
        $this->onQueue('backup');
        $this->timeout = max(300, (int) config('backup.job_timeout_seconds', 21600));
    }

    public function handle(BackupService $backups, BackupNotificationService $notifications): void
    {
        $queuedRecord = BackupRecord::query()->find($this->recordId);
        if (!$queuedRecord || $queuedRecord->status !== BackupRecord::STATUS_QUEUED) {
            return;
        }

        $record = $backups->createDatabaseBackup($this->upload, [
            ...$this->options,
            'record_id' => $this->recordId,
        ]);

        if ((bool) ($this->options['verify_after_backup'] ?? true)) {
            $verification = $backups->verifyBackup((int) ($record['id'] ?? 0), true);
            if (!($verification['ok'] ?? false)) {
                throw new RuntimeException('Automatic backup verification failed');
            }
            $record = $backups->formatRecord(BackupRecord::query()->find($this->recordId)) ?: $record;
        }

        if ($this->upload && !(bool) data_get($record, 'options.keep_local_after_upload', true)) {
            $record = $backups->finalizeRemoteOnlyBackup($this->recordId);
        }

        $keep = max(0, (int) ($this->options['keep'] ?? 0));
        if ($keep > 0) {
            $backups->pruneLocalBackups($keep);
        }

        $notifications->backupSucceeded($record);
    }

    public function failed(?Throwable $exception): void
    {
        $record = BackupRecord::query()->find($this->recordId);
        if ($record && in_array($record->status, [BackupRecord::STATUS_QUEUED, BackupRecord::STATUS_RUNNING], true)) {
            $record->forceFill([
                'status' => BackupRecord::STATUS_FAILED,
                'error' => mb_substr($exception?->getMessage() ?: 'Backup worker stopped unexpectedly', 0, 2000),
                'finished_at' => time(),
            ])->save();
        }

        try {
            app(BackupNotificationService::class)->backupFailed($record, $exception);
        } catch (Throwable) {
            // Queue failure reporting must never mask the original failure.
        }
    }
}

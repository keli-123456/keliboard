<?php

namespace App\Console\Commands;

use App\Models\BackupRecord;
use App\Services\Backup\BackupRecoveryService;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use RuntimeException;

class BackupRestorePlan extends Command
{
    protected $signature = 'backup:restore-plan
        {backup? : Local .sql.gz path or backup record id}
        {--id= : Backup record id}
        {--expected-sha256= : Expected compressed backup SHA256}
        {--connection= : Override database connection, for example mysql or sqlite}
        {--extract-env= : Write embedded .env to this target path}
        {--extract-files= : Write embedded recovery support files to this directory}
        {--force : Overwrite extracted targets}
        {--json : Output machine-readable JSON}';

    protected $description = '校验数据库备份、提取 .env 并输出恢复步骤';

    public function handle(BackupRecoveryService $recovery, BackupService $backups): int
    {
        try {
            [$path, $checksum, $connection] = $this->resolveBackupInput($backups);
            $result = $recovery->inspect($path, [
                'expected_sha256' => (string) ($this->option('expected-sha256') ?: $checksum),
                'connection' => (string) ($this->option('connection') ?: $connection),
            ]);

            $extractTarget = trim((string) $this->option('extract-env'));
            if ($extractTarget !== '') {
                $contents = $result['env']['contents'] ?? null;
                if (!is_string($contents) || $contents === '') {
                    throw new RuntimeException('This backup does not contain an embedded .env file');
                }
                $recovery->writeEnvironmentFile($contents, $extractTarget, (bool) $this->option('force'));
                $result['env']['extracted_to'] = $extractTarget;
            }

            $extractFilesTarget = trim((string) $this->option('extract-files'));
            if ($extractFilesTarget !== '') {
                $result['files_extracted_to'] = $recovery->writeEmbeddedFiles(
                    is_array($result['files'] ?? null) ? $result['files'] : [],
                    $extractFilesTarget,
                    (bool) $this->option('force')
                );
            }

            unset($result['env']['contents']);
            foreach ($result['files'] as &$file) {
                unset($file['contents']);
            }
            unset($file);
            if ((bool) $this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return self::SUCCESS;
            }

            $this->renderResult($result);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function resolveBackupInput(BackupService $backups): array
    {
        $id = $this->option('id');
        $argument = $this->argument('backup');
        if ($id === null && is_scalar($argument) && preg_match('/^\d+$/', (string) $argument)) {
            $id = (int) $argument;
            $argument = null;
        }

        if ($id !== null && $id !== '') {
            $record = BackupRecord::query()->findOrFail((int) $id);
            if ($record->disk !== 'local' || $record->status !== BackupRecord::STATUS_SUCCEEDED) {
                throw new RuntimeException('Only successful local backup records can be inspected by id');
            }

            return [
                $backups->localPath($record),
                (string) $record->checksum,
                (string) data_get($record->options ?: [], 'database_connection', ''),
            ];
        }

        $path = trim((string) $argument);
        if ($path === '') {
            $record = BackupRecord::query()
                ->where('type', BackupRecord::TYPE_DATABASE)
                ->where('disk', 'local')
                ->where('status', BackupRecord::STATUS_SUCCEEDED)
                ->orderByDesc('id')
                ->first();
            if (!$record) {
                throw new RuntimeException('Pass a backup file path, backup record id, or create a local backup first');
            }

            return [
                $backups->localPath($record),
                (string) $record->checksum,
                (string) data_get($record->options ?: [], 'database_connection', ''),
            ];
        }

        return [$path, '', ''];
    }

    private function renderResult(array $result): void
    {
        $this->info('备份恢复检查');
        $this->line('文件: ' . $result['path']);
        $this->line('大小: ' . $result['size'] . ' bytes');
        $this->line('SHA256: ' . $result['sha256']);
        if ($result['checksum_ok'] !== null) {
            $this->line('校验: ' . ($result['checksum_ok'] ? '通过' : '失败'));
        }
        $this->line('gzip: ' . ($result['gzip_ok'] ? '可读取' : '不可读取'));
        $this->line('SQL: ' . ($result['sql_dump'] ? '看起来是 SQL dump' : '未识别'));
        $this->line('数据库连接: ' . $result['database_connection']);
        $this->line('.env: ' . ($result['env']['present'] ? ('已内嵌，' . $result['env']['bytes'] . ' bytes') : '未内嵌'));
        $this->line('恢复文件: ' . count($result['files'] ?? []) . ' 个');
        if (!empty($result['env']['extracted_to'])) {
            $this->line('.env 已写入: ' . $result['env']['extracted_to']);
        }
        if (!empty($result['files_extracted_to'])) {
            foreach ($result['files_extracted_to'] as $file) {
                $this->line('恢复文件已写入: ' . $file['path']);
            }
        }

        if (!empty($result['warnings'])) {
            $this->warn('注意事项');
            foreach ($result['warnings'] as $warning) {
                $this->line('- ' . $warning);
            }
        }

        $this->info('建议恢复步骤');
        foreach ($result['restore_commands'] as $index => $command) {
            $this->line(($index + 1) . '. ' . $command);
        }
    }
}

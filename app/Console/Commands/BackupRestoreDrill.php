<?php

namespace App\Console\Commands;

use App\Models\BackupRecord;
use App\Services\Backup\BackupRecoveryService;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use RuntimeException;

class BackupRestoreDrill extends Command
{
    protected $signature = 'backup:restore-drill
        {backup? : Local .sql.gz path or backup record id}
        {--id= : Backup record id}
        {--expected-sha256= : Expected compressed backup SHA256}
        {--connection= : Override database connection, for example mysql or sqlite}
        {--extract-env= : Write embedded .env to this target path}
        {--extract-files= : Write embedded recovery support files to this directory}
        {--force : Overwrite extracted targets}
        {--record : Record the drill result on the backup record when available}
        {--operator= : Operator name for recorded drill}
        {--environment=staging : local, staging, or production_rehearsal}
        {--json : Output machine-readable JSON}';

    protected $description = '自动检查备份是否具备异机恢复所需的关键材料';

    public function handle(BackupRecoveryService $recovery, BackupService $backups): int
    {
        try {
            [$path, $checksum, $connection, $record] = $this->resolveBackupInput($backups);
            $options = [
                'expected_sha256' => (string) ($this->option('expected-sha256') ?: $checksum),
                'connection' => (string) ($this->option('connection') ?: $connection),
            ];

            $result = $recovery->drill($path, $options);
            $extractEnvTarget = trim((string) $this->option('extract-env'));
            $extractFilesTarget = trim((string) $this->option('extract-files'));
            if ($extractEnvTarget !== '' || $extractFilesTarget !== '') {
                $inspection = $recovery->inspect($path, $options);
                if ($extractEnvTarget !== '') {
                    $contents = $inspection['env']['contents'] ?? null;
                    if (!is_string($contents) || $contents === '') {
                        throw new RuntimeException('This backup does not contain an embedded .env file');
                    }

                    $recovery->writeEnvironmentFile($contents, $extractEnvTarget, (bool) $this->option('force'));
                    $result['inspection']['env']['extracted_to'] = $extractEnvTarget;
                }

                if ($extractFilesTarget !== '') {
                    $written = $recovery->writeEmbeddedFiles(
                        is_array($inspection['files'] ?? null) ? $inspection['files'] : [],
                        $extractFilesTarget,
                        (bool) $this->option('force')
                    );
                    $result['inspection']['files_extracted_to'] = $written;
                }
            }

            if ((bool) $this->option('record')) {
                if (!$record) {
                    throw new RuntimeException('A backup record is required when using --record');
                }

                $recorded = $backups->recordRestoreDrill((int) $record->id, [
                    'status' => $result['ok'] ? 'passed' : 'failed',
                    'environment' => (string) $this->option('environment'),
                    'operator' => (string) ($this->option('operator') ?: 'backup:restore-drill'),
                    'note' => $this->drillNote($result),
                ]);
                $result['recorded_drill'] = $recorded['drill'] ?? null;
            }

            if ((bool) $this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return $result['ok'] ? self::SUCCESS : self::FAILURE;
            }

            $this->renderResult($result);

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
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
                throw new RuntimeException('Only successful local backup records can be drilled by id');
            }

            return [
                $backups->localPath($record),
                (string) $record->checksum,
                (string) data_get($record->options ?: [], 'database_connection', ''),
                $record,
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
                $record,
            ];
        }

        return [$path, '', '', null];
    }

    private function renderResult(array $result): void
    {
        $inspection = $result['inspection'] ?? [];
        $this->info('备份恢复演练');
        $this->line('结果: ' . ($result['ok'] ? '通过' : '失败'));
        $this->line('文件: ' . (string) ($inspection['path'] ?? '-'));
        $this->line('SHA256: ' . (string) ($inspection['sha256'] ?? '-'));
        $this->line('数据库连接: ' . (string) ($inspection['database_connection'] ?? '-'));

        $this->info('检查项');
        foreach ($result['checks'] ?? [] as $check) {
            $prefix = (bool) ($check['ok'] ?? false) ? '[OK]' : ((bool) ($check['warning'] ?? false) ? '[WARN]' : '[FAIL]');
            $this->line($prefix . ' ' . (string) ($check['key'] ?? '-') . ' - ' . (string) ($check['message'] ?? ''));
        }

        if (!empty($inspection['env']['extracted_to'])) {
            $this->line('.env 已写入: ' . $inspection['env']['extracted_to']);
        }
        if (!empty($inspection['files_extracted_to'])) {
            foreach ($inspection['files_extracted_to'] as $file) {
                $this->line('恢复文件已写入: ' . $file['path']);
            }
        }
        if (!empty($result['recorded_drill'])) {
            $this->line('已记录演练: ' . $result['recorded_drill']['id']);
        }
    }

    private function drillNote(array $result): string
    {
        $failed = array_values(array_filter(
            $result['checks'] ?? [],
            fn(array $check) => !(bool) ($check['ok'] ?? false) && !(bool) ($check['warning'] ?? false)
        ));
        if ($failed === []) {
            return 'Automated restore drill passed.';
        }

        return 'Automated restore drill failed: ' . implode('; ', array_map(
            fn(array $check) => (string) ($check['key'] ?? 'unknown') . ' - ' . (string) ($check['message'] ?? ''),
            $failed
        ));
    }
}

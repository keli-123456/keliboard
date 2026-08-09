<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BackupRecord;
use App\Jobs\BackupDatabaseJob;
use App\Models\Setting as SettingModel;
use App\Services\Backup\BackupRecoveryService;
use App\Services\Backup\BackupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Illuminate\Contracts\Bus\Dispatcher;
use Tests\TestCase;

final class BackupServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        app()->instance('encrypter', new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
        app()->instance('files', new Filesystem());
        $this->createBackupRecordTable();
        $this->createSettingsTable();
    }

    public function test_record_restore_drill_stores_latest_summary_without_losing_options(): void
    {
        $record = $this->createBackupRecord([
            'options' => ['database_connection' => 'mysql'],
        ]);

        $result = (new BackupService())->recordRestoreDrill($record->id, [
            'status' => 'passed',
            'environment' => 'staging',
            'note' => str_repeat('A', 1100),
            'operator' => str_repeat('B', 130),
        ]);

        $record->refresh();
        $drills = $record->options['restore_drills'] ?? [];

        $this->assertSame('mysql', $record->options['database_connection']);
        $this->assertCount(1, $drills);
        $this->assertSame('passed', $drills[0]['status']);
        $this->assertSame('staging', $drills[0]['environment']);
        $this->assertSame(1000, strlen($drills[0]['note']));
        $this->assertSame(120, strlen($drills[0]['operator']));
        $this->assertSame($drills[0]['id'], $result['record']['latest_restore_drill']['id']);
        $this->assertSame($drills[0]['id'], $result['drill']['id']);
    }

    public function test_record_restore_drill_rejects_incomplete_backup(): void
    {
        $record = $this->createBackupRecord([
            'status' => BackupRecord::STATUS_RUNNING,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only completed backups can record restore drills');

        (new BackupService())->recordRestoreDrill($record->id, [
            'status' => 'incomplete',
            'environment' => 'local',
        ]);
    }

    public function test_restore_drill_check_can_record_automated_result(): void
    {
        $filesystem = new Filesystem();
        $backupDir = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backup';
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . 'restore-drill-check.sql.gz';
        $env = "APP_KEY=base64:test-key\nDB_PASSWORD=secret\n";
        $sql = implode("\n", [
            '-- KELI_RECOVERY_START',
            '-- KELI_RECOVERY_FORMAT=env-base64-v1',
            '-- KELI_RECOVERY_DATABASE_CONNECTION=mysql',
            '-- KELI_RECOVERY_ENV_FILE=.env',
            '-- KELI_RECOVERY_ENV_BASE64_BEGIN',
            '-- ' . base64_encode($env),
            '-- KELI_RECOVERY_ENV_BASE64_END',
            '-- KELI_RECOVERY_FILES=0',
            '-- KELI_RECOVERY_END',
            'CREATE TABLE users (id int);',
            '',
        ]);

        $filesystem->ensureDirectoryExists($backupDir);
        $this->writeGzip($backupPath, $sql);

        try {
            $record = $this->createBackupRecord([
                'filename' => basename($backupPath),
                'path' => 'backup/' . basename($backupPath),
                'size' => filesize($backupPath),
                'checksum' => hash_file('sha256', $backupPath),
                'options' => ['database_connection' => 'mysql'],
            ]);

            $result = (new BackupService())->restoreDrillCheck($record->id, [
                'record' => true,
                'environment' => 'staging',
                'operator' => 'phpunit',
            ], new BackupRecoveryService());

            $this->assertTrue($result['ok']);
            $this->assertSame($record->id, $result['id']);
            $this->assertSame('passed', $result['drill']['status']);
            $this->assertSame('phpunit', $result['drill']['operator']);
            $this->assertSame($record->id, $result['record']['id']);
            $this->assertSame('passed', $result['record']['latest_restore_drill']['status']);
        } finally {
            $filesystem->delete($backupPath);
        }
    }

    public function test_database_dump_recovery_metadata_contains_full_env_file(): void
    {
        $filesystem = new Filesystem();
        $storagePath = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        $environmentDir = $storagePath . DIRECTORY_SEPARATOR . 'testing-backup-env';
        $environmentPath = $environmentDir . DIRECTORY_SEPARATOR . '.env';
        $composePath = $environmentDir . DIRECTORY_SEPARATOR . 'docker-compose.yml';
        $dumpPath = $storagePath . DIRECTORY_SEPARATOR . 'testing-backup-env-dump.sql';
        $envContents = implode("\n", [
            'APP_KEY=base64:test-key',
            'DB_PASSWORD="secret=value"',
            'PAYMENT_SECRET=pay_secret',
            '',
        ]);
        $composeContents = "services:\n  web:\n    image: ghcr.io/keli/keliboard:latest\n";

        config([
            'backup.recovery_environment_file' => $environmentPath,
            'backup.recovery_files' => [
                'docker-compose.yml' => $composePath,
            ],
            'database.default' => 'mysql',
        ]);

        $filesystem->ensureDirectoryExists($environmentDir);
        $filesystem->put($environmentPath, $envContents);
        $filesystem->put($composePath, $composeContents);
        $filesystem->put($dumpPath, "CREATE TABLE test (id int);\n");

        try {
            $method = new \ReflectionMethod(BackupService::class, 'prependRecoveryMetadata');
            $method->setAccessible(true);
            $method->invoke(new BackupService(), $dumpPath);

            $contents = $filesystem->get($dumpPath);
            $encodedEnv = $this->extractRecoveryEnvBase64($contents);

            $this->assertStringStartsWith("-- KELI_RECOVERY_START\n", $contents);
            $this->assertStringContainsString("-- KELI_RECOVERY_FORMAT=env-base64-v1\n", $contents);
            $this->assertStringContainsString("-- KELI_RECOVERY_DATABASE_CONNECTION=mysql\n", $contents);
            $this->assertSame($envContents, base64_decode($encodedEnv, true));
            $this->assertStringContainsString("-- KELI_RECOVERY_FILES=1\n", $contents);
            $this->assertStringContainsString("-- KELI_RECOVERY_FILE_BEGIN=docker-compose.yml\n", $contents);
            $this->assertStringContainsString("-- KELI_RECOVERY_FILE_SHA256=" . hash('sha256', $composeContents) . "\n", $contents);
            $this->assertSame($composeContents, base64_decode($this->extractRecoveryFileBase64($contents, 'docker-compose.yml'), true));
            $this->assertStringContainsString("CREATE TABLE test (id int);\n", $contents);
        } finally {
            $filesystem->delete($dumpPath);
            $filesystem->delete($dumpPath . '.recovery');
            $filesystem->deleteDirectory($environmentDir);
        }
    }

    public function test_remote_storage_settings_prefer_panel_config_and_hide_secrets(): void
    {
        config([
            'cloud_storage.google_cloud.key_file' => '/env/key.json',
            'cloud_storage.google_cloud.storage_bucket' => 'env-bucket',
            'cloud_storage.ftp.host' => 'env-ftp.example.test',
            'cloud_storage.ftp.username' => 'env-user',
            'cloud_storage.ftp.password' => 'env-secret',
        ]);

        $credentials = json_encode([
            'project_id' => 'panel-project',
            'client_email' => 'backup@example.test',
            'private_key' => "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----\n",
        ]);

        $result = (new BackupService())->updateRemoteStorageSettings([
            'google_cloud' => [
                'bucket' => 'panel-bucket',
                'prefix' => 'panel-backup',
                'credentials_json' => $credentials,
            ],
            'ftp' => [
                'host' => 'ftp.example.test',
                'port' => 2121,
                'username' => 'backup',
                'password' => 'panel-secret',
                'root' => '/snapshots',
                'ssl' => true,
                'passive' => false,
                'timeout' => 45,
            ],
        ]);

        $google = $result['remote_storage']['google_cloud'];
        $ftp = $result['remote_storage']['ftp'];

        $this->assertSame('panel-bucket', $google['bucket']);
        $this->assertSame('panel-backup', $google['prefix']);
        $this->assertTrue($google['credentials_configured']);
        $this->assertSame('panel', $google['source']);
        $this->assertArrayNotHasKey('credentials_json', $google);

        $this->assertSame('ftp.example.test', $ftp['host']);
        $this->assertSame(2121, $ftp['port']);
        $this->assertSame('backup', $ftp['username']);
        $this->assertSame('/snapshots', $ftp['root']);
        $this->assertTrue($ftp['password_configured']);
        $this->assertSame('mixed', $ftp['source']);
        $this->assertArrayNotHasKey('password', $ftp);

        $this->assertNotSame($credentials, SettingModel::where('name', 'backup_remote_google_cloud_credentials')->value('value'));
        $this->assertNotSame('panel-secret', SettingModel::where('name', 'backup_remote_ftp_password')->value('value'));
    }

    public function test_remote_storage_settings_validate_google_credentials_json(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Cloud credentials JSON is missing private_key');

        (new BackupService())->updateRemoteStorageSettings([
            'google_cloud' => [
                'bucket' => 'panel-bucket',
                'credentials_json' => json_encode([
                    'project_id' => 'panel-project',
                    'client_email' => 'backup@example.test',
                ]),
            ],
        ]);
    }

    public function test_prune_backups_uses_same_retention_for_local_and_remote_records(): void
    {
        $olderLocal = $this->createBackupRecord(['finished_at' => time() - 40, 'created_at' => time() - 40]);
        $latestLocal = $this->createBackupRecord(['finished_at' => time() - 30, 'created_at' => time() - 30]);
        $olderRemote = $this->createBackupRecord([
            'status' => BackupRecord::STATUS_UPLOADED,
            'disk' => 'google_cloud',
            'path' => null,
            'remote_path' => null,
            'finished_at' => time() - 20,
            'created_at' => time() - 20,
        ]);
        $latestRemote = $this->createBackupRecord([
            'status' => BackupRecord::STATUS_UPLOADED,
            'disk' => 'google_cloud',
            'path' => null,
            'remote_path' => null,
            'finished_at' => time() - 10,
            'created_at' => time() - 10,
        ]);

        $result = (new BackupService())->pruneLocalBackups(1);

        $this->assertSame(2, $result['deleted']);
        $this->assertSame(1, $result['local_deleted']);
        $this->assertSame(1, $result['remote_deleted']);
        $this->assertSame(0, $result['failed']);
        $this->assertNull(BackupRecord::find($olderLocal->id));
        $this->assertNotNull(BackupRecord::find($latestLocal->id));
        $this->assertNull(BackupRecord::find($olderRemote->id));
        $this->assertNotNull(BackupRecord::find($latestRemote->id));
    }

    public function test_manual_backup_is_queued_without_running_dump_in_http_request(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (mixed $job): bool {
                $this->assertInstanceOf(BackupDatabaseJob::class, $job);
                $this->assertSame('redis_backup', $job->connection);
                $this->assertSame('backup', $job->queue);
                $this->assertSame(max(300, (int) config('backup.job_timeout_seconds', 21600)), $job->timeout);

                return true;
            }))
            ->willReturnCallback(fn(mixed $job): mixed => $job);
        app()->instance(Dispatcher::class, $dispatcher);

        $record = (new BackupService())->queueDatabaseBackup(false, [
            'trigger' => 'manual',
            'keep' => 7,
        ]);

        $this->assertSame(BackupRecord::STATUS_QUEUED, $record['status']);
        $this->assertSame('', $record['path']);
    }

    public function test_backup_queue_has_an_isolated_worker_and_safe_retry_window(): void
    {
        $queue = require getcwd() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'queue.php';
        $horizon = require getcwd() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'horizon.php';
        $production = $horizon['environments']['production'];

        $this->assertSame('redis_backup', $production['Backup']['connection']);
        $this->assertSame(['backup'], $production['Backup']['queue']);
        $this->assertSame(1, $production['Backup']['maxProcesses']);
        $this->assertNotContains('backup', $production['Xboard']['queue']);
        $this->assertGreaterThan(
            (int) $production['Backup']['timeout'],
            (int) $queue['connections']['redis_backup']['retry_after']
        );
    }

    public function test_stale_queued_backup_is_marked_failed(): void
    {
        config([
            'backup.job_timeout_seconds' => 300,
            'backup.stale_after_seconds' => 300,
        ]);
        $record = $this->createBackupRecord([
            'status' => BackupRecord::STATUS_QUEUED,
            'started_at' => time() - 1200,
            'finished_at' => null,
        ]);

        $recovered = (new BackupService())->recoverStaleRunningBackups();

        $this->assertSame(1, $recovered);
        $record->refresh();
        $this->assertSame(BackupRecord::STATUS_FAILED, $record->status);
        $this->assertNotNull($record->finished_at);
        $this->assertStringContainsString('worker stopped', (string) $record->error);
    }

    public function test_running_backup_is_not_recovered_before_job_timeout_window(): void
    {
        config([
            'backup.job_timeout_seconds' => 300,
            'backup.stale_after_seconds' => 300,
        ]);
        $record = $this->createBackupRecord([
            'status' => BackupRecord::STATUS_RUNNING,
            'started_at' => time() - 600,
            'finished_at' => null,
        ]);

        $recovered = (new BackupService())->recoverStaleRunningBackups();

        $this->assertSame(0, $recovered);
        $record->refresh();
        $this->assertSame(BackupRecord::STATUS_RUNNING, $record->status);
        $this->assertNull($record->finished_at);
    }

    public function test_missing_google_key_file_is_not_reported_as_configured(): void
    {
        config([
            'cloud_storage.google_cloud.key_file' => getcwd() . '/missing-google-key.json',
            'cloud_storage.google_cloud.storage_bucket' => 'env-bucket',
        ]);

        $service = new BackupService();
        $google = $service->remoteStorageSettings()['google_cloud'];
        $readyMethod = new \ReflectionMethod(BackupService::class, 'googleCloudReady');
        $readyMethod->setAccessible(true);
        $ready = $readyMethod->invoke($service);

        $this->assertFalse($google['credentials_configured']);
        $this->assertFalse($google['key_file_readable']);
        $this->assertFalse($google['env_configured']);
        $this->assertFalse($ready);
    }

    public function test_remote_backup_can_be_retrieved_and_keeps_remote_copy_metadata(): void
    {
        $filesystem = new Filesystem();
        $source = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework'
            . DIRECTORY_SEPARATOR . 'remote-backup-fixture.enc';
        $target = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backup'
            . DIRECTORY_SEPARATOR . 'retrieved-backup.enc';
        $contents = random_bytes(4096);
        $filesystem->ensureDirectoryExists(dirname($source));
        $filesystem->put($source, $contents);
        $filesystem->delete($target);

        $record = $this->createBackupRecord([
            'status' => BackupRecord::STATUS_UPLOADED,
            'disk' => 'google_cloud',
            'filename' => basename($target),
            'path' => null,
            'remote_path' => 'backup/retrieved-backup.enc',
            'size' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'options' => ['remote_disk' => 'google_cloud'],
        ]);

        $service = new class($source) extends BackupService {
            public function __construct(private readonly string $fixture)
            {
            }

            protected function downloadFromRemoteDisk(string $remotePath, string $target, string $disk): void
            {
                if (!copy($this->fixture, $target)) {
                    throw new RuntimeException('Fixture copy failed');
                }
            }
        };

        try {
            $result = $service->retrieveRemoteBackup($record->id);

            $this->assertSame(BackupRecord::STATUS_SUCCEEDED, $result['status']);
            $this->assertSame('local', $result['disk']);
            $this->assertTrue($result['local_copy']['exists']);
            $this->assertTrue($result['remote_copy']['exists']);
            $this->assertTrue($result['downloadable']);
            $this->assertTrue($result['retrievable']);
            $this->assertSame($contents, $filesystem->get($target));

            $record->refresh();
            $this->assertSame('backup/retrieved-backup.enc', $record->remote_path);
            $this->assertSame('google_cloud', $record->options['remote_disk']);
            $this->assertNotEmpty($record->options['retrieved_at']);
        } finally {
            $filesystem->delete($source);
            $filesystem->delete($target);
        }
    }

    public function test_verified_remote_backup_can_drop_its_local_copy(): void
    {
        $filesystem = new Filesystem();
        $filename = 'finalize-' . bin2hex(random_bytes(4)) . '.enc';
        $relativePath = 'backup/' . $filename;
        $path = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backup'
            . DIRECTORY_SEPARATOR . $filename;
        $contents = random_bytes(512);
        $filesystem->ensureDirectoryExists(dirname($path));
        $filesystem->put($path, $contents);

        $record = $this->createBackupRecord([
            'filename' => $filename,
            'path' => $relativePath,
            'remote_path' => 'backup/' . $filename,
            'size' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'options' => [
                'remote_disk' => 'ftp',
                'remote_uploaded' => true,
                'keep_local_after_upload' => false,
                'last_verification' => ['ok' => true],
            ],
        ]);

        try {
            $result = (new BackupService())->finalizeRemoteOnlyBackup($record->id);

            $this->assertFalse($filesystem->isFile($path));
            $this->assertSame(BackupRecord::STATUS_UPLOADED, $result['status']);
            $this->assertSame('ftp', $result['disk']);
            $this->assertFalse($result['local_copy']['exists']);
            $this->assertTrue($result['remote_copy']['exists']);
            $this->assertFalse($result['downloadable']);

            $record->refresh();
            $this->assertNull($record->path);
            $this->assertNotEmpty($record->options['local_removed_at']);
        } finally {
            $filesystem->delete($path);
        }
    }

    public function test_remote_backup_keeps_local_copy_until_required_verification_passes(): void
    {
        $filesystem = new Filesystem();
        $filename = 'unverified-' . bin2hex(random_bytes(4)) . '.enc';
        $relativePath = 'backup/' . $filename;
        $path = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backup'
            . DIRECTORY_SEPARATOR . $filename;
        $filesystem->ensureDirectoryExists(dirname($path));
        $filesystem->put($path, random_bytes(128));
        $record = $this->createBackupRecord([
            'filename' => $filename,
            'path' => $relativePath,
            'remote_path' => 'backup/' . $filename,
            'options' => [
                'remote_disk' => 'ftp',
                'remote_uploaded' => true,
                'keep_local_after_upload' => false,
                'verify_after_backup' => true,
            ],
        ]);

        try {
            (new BackupService())->finalizeRemoteOnlyBackup($record->id);
            $this->fail('Unverified backup local copy was removed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('must pass verification', $e->getMessage());
            $this->assertTrue($filesystem->isFile($path));
            $record->refresh();
            $this->assertSame(BackupRecord::STATUS_SUCCEEDED, $record->status);
            $this->assertSame($relativePath, $record->path);
        } finally {
            $filesystem->delete($path);
        }
    }

    private function extractRecoveryEnvBase64(string $dump): string
    {
        $encoded = '';
        $collecting = false;

        foreach (explode("\n", $dump) as $line) {
            if ($line === '-- KELI_RECOVERY_ENV_BASE64_BEGIN') {
                $collecting = true;
                continue;
            }

            if ($line === '-- KELI_RECOVERY_ENV_BASE64_END') {
                return $encoded;
            }

            if ($collecting && str_starts_with($line, '-- ')) {
                $encoded .= substr($line, 3);
            }
        }

        return $encoded;
    }

    private function extractRecoveryFileBase64(string $dump, string $name): string
    {
        $encoded = '';
        $insideFile = false;
        $collecting = false;

        foreach (explode("\n", $dump) as $line) {
            if ($line === '-- KELI_RECOVERY_FILE_BEGIN=' . $name) {
                $insideFile = true;
                continue;
            }

            if ($insideFile && $line === '-- KELI_RECOVERY_FILE_BASE64_BEGIN') {
                $collecting = true;
                continue;
            }

            if ($insideFile && $line === '-- KELI_RECOVERY_FILE_BASE64_END') {
                return $encoded;
            }

            if ($collecting && str_starts_with($line, '-- ')) {
                $encoded .= substr($line, 3);
            }
        }

        return $encoded;
    }

    private function writeGzip(string $path, string $contents): void
    {
        $handle = gzopen($path, 'wb9');
        if (!$handle) {
            $this->fail('Failed to create gzip fixture');
        }

        gzwrite($handle, $contents);
        gzclose($handle);
    }

    private function createBackupRecord(array $overrides = []): BackupRecord
    {
        $now = time();

        return BackupRecord::create(array_merge([
            'type' => BackupRecord::TYPE_DATABASE,
            'status' => BackupRecord::STATUS_SUCCEEDED,
            'disk' => 'local',
            'filename' => '2026-05-02_03-30-00_xboard_database_backup.sql.gz',
            'path' => 'backup/2026-05-02_03-30-00_xboard_database_backup.sql.gz',
            'remote_path' => null,
            'size' => 128,
            'checksum' => str_repeat('a', 64),
            'options' => [],
            'error' => null,
            'started_at' => $now - 10,
            'finished_at' => $now,
            'created_at' => $now - 10,
            'updated_at' => $now,
        ], $overrides));
    }

    private function createBackupRecordTable(): void
    {
        Schema::create('v2_backup_record', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('type', 32)->default('database')->index();
            $table->string('status', 24)->default('running')->index();
            $table->string('disk', 32)->default('local');
            $table->string('filename', 255);
            $table->string('path', 1024)->nullable();
            $table->string('remote_path', 1024)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->json('options')->nullable();
            $table->text('error')->nullable();
            $table->integer('started_at')->nullable()->index();
            $table->integer('finished_at')->nullable()->index();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    private function createSettingsTable(): void
    {
        Schema::create('v2_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group')->nullable();
            $table->string('type')->nullable();
            $table->string('name')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}

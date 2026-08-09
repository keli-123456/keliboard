<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Backup\BackupRecoveryService;
use App\Services\Backup\BackupBundleService;
use App\Services\Backup\BackupEncryptionService;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class BackupRecoveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->instance('files', new Filesystem());
    }

    public function test_inspect_reads_env_recovery_metadata_and_restore_commands(): void
    {
        config(['database.default' => 'mysql']);

        $filesystem = new Filesystem();
        $tempDir = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'backup-recovery-test';
        $path = $tempDir . DIRECTORY_SEPARATOR . 'backup.sql.gz';
        $env = "APP_KEY=base64:test\nDB_PASSWORD=secret\n";
        $compose = "services:\n  web:\n    image: ghcr.io/keli/keliboard:latest\n";
        $sql = implode("\n", [
            '-- KELI_RECOVERY_START',
            '-- KELI_RECOVERY_FORMAT=env-base64-v1',
            '-- KELI_RECOVERY_DATABASE_CONNECTION=mysql',
            '-- KELI_RECOVERY_GENERATED_AT=2026-05-02T00:00:00+00:00',
            '-- KELI_RECOVERY_ENV_FILE=.env',
            '-- KELI_RECOVERY_ENV_BASE64_BEGIN',
            '-- ' . base64_encode($env),
            '-- KELI_RECOVERY_ENV_BASE64_END',
            '-- KELI_RECOVERY_FILES=1',
            '-- KELI_RECOVERY_FILE_BEGIN=docker-compose.yml',
            '-- KELI_RECOVERY_FILE_BYTES=' . strlen($compose),
            '-- KELI_RECOVERY_FILE_SHA256=' . hash('sha256', $compose),
            '-- KELI_RECOVERY_FILE_BASE64_BEGIN',
            '-- ' . base64_encode($compose),
            '-- KELI_RECOVERY_FILE_BASE64_END',
            '-- KELI_RECOVERY_FILE_END',
            '-- KELI_RECOVERY_END',
            'CREATE TABLE users (id int);',
            '',
        ]);

        $filesystem->ensureDirectoryExists($tempDir);
        $this->writeGzip($path, $sql);

        try {
            $checksum = hash_file('sha256', $path);
            $result = (new BackupRecoveryService())->inspect($path, [
                'expected_sha256' => $checksum,
            ]);

            $this->assertTrue($result['checksum_ok']);
            $this->assertTrue($result['gzip_ok']);
            $this->assertTrue($result['sql_dump']);
            $this->assertSame('mysql', $result['database_connection']);
            $this->assertTrue($result['env']['present']);
            $this->assertSame(strlen($env), $result['env']['bytes']);
            $this->assertSame($env, $result['env']['contents']);
            $this->assertSame(1, $result['metadata']['files_count']);
            $this->assertCount(1, $result['files']);
            $this->assertSame('docker-compose.yml', $result['files'][0]['name']);
            $this->assertSame($compose, $result['files'][0]['contents']);
            $this->assertTrue($result['files'][0]['checksum_ok']);
            $this->assertStringContainsString('base64 -d > .env', $result['restore_commands'][0]);
            $this->assertStringContainsString('mysql -h "$DB_HOST"', $result['restore_commands'][2]);
        } finally {
            $filesystem->deleteDirectory($tempDir);
        }
    }

    public function test_drill_fails_when_required_app_key_is_missing(): void
    {
        $filesystem = new Filesystem();
        $tempDir = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'backup-recovery-drill-test';
        $path = $tempDir . DIRECTORY_SEPARATOR . 'backup.sql.gz';
        $env = "DB_PASSWORD=secret\n";
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

        $filesystem->ensureDirectoryExists($tempDir);
        $this->writeGzip($path, $sql);

        try {
            $result = (new BackupRecoveryService())->drill($path, [
                'expected_sha256' => hash_file('sha256', $path),
            ]);

            $this->assertFalse($result['ok']);
            $this->assertSame('env_required_keys', $result['checks'][4]['key']);
            $this->assertStringContainsString('APP_KEY', $result['checks'][4]['message']);
            $this->assertArrayNotHasKey('contents', $result['inspection']['env']);
        } finally {
            $filesystem->deleteDirectory($tempDir);
        }
    }

    public function test_write_environment_file_requires_force_to_overwrite(): void
    {
        $filesystem = new Filesystem();
        $tempDir = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'backup-recovery-write-test';
        $path = $tempDir . DIRECTORY_SEPARATOR . '.env';
        $service = new BackupRecoveryService();

        try {
            $service->writeEnvironmentFile("APP_KEY=old\n", $path);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Target .env already exists');
            $service->writeEnvironmentFile("APP_KEY=new\n", $path);
        } finally {
            $filesystem->deleteDirectory($tempDir);
        }
    }

    public function test_write_embedded_files_requires_force_to_overwrite(): void
    {
        $filesystem = new Filesystem();
        $tempDir = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'backup-recovery-files-test';
        $service = new BackupRecoveryService();

        try {
            $service->writeEmbeddedFiles([
                [
                    'name' => 'docker-compose.yml',
                    'contents' => "services:\n  web:\n    image: old\n",
                ],
            ], $tempDir);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Target recovery file docker-compose.yml already exists');
            $service->writeEmbeddedFiles([
                [
                    'name' => 'docker-compose.yml',
                    'contents' => "services:\n  web:\n    image: new\n",
                ],
            ], $tempDir);
        } finally {
            $filesystem->deleteDirectory($tempDir);
        }
    }

    public function test_inspect_and_extract_encrypted_recovery_bundle(): void
    {
        $filesystem = new Filesystem();
        $tempDir = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework'
            . DIRECTORY_SEPARATOR . 'backup-recovery-encrypted-test-' . bin2hex(random_bytes(4));
        $database = $tempDir . DIRECTORY_SEPARATOR . 'database.sql.gz';
        $resourceRoot = $tempDir . DIRECTORY_SEPARATOR . 'ticket_attachments';
        $bundle = $tempDir . DIRECTORY_SEPARATOR . 'backup.keli.zip';
        $encrypted = $bundle . '.enc';
        $restoredDatabase = $tempDir . DIRECTORY_SEPARATOR . 'restored.sql.gz';
        $restoredResources = $tempDir . DIRECTORY_SEPARATOR . 'restored-resources';
        $env = "APP_KEY=base64:test-key\n";
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

        $filesystem->ensureDirectoryExists($resourceRoot);
        $filesystem->put($resourceRoot . DIRECTORY_SEPARATOR . 'ticket.txt', 'ticket-data');
        $this->writeGzip($database, $sql);
        config([
            'backup.encryption_key' => 'base64:' . base64_encode(str_repeat('r', 32)),
            'backup.resource_sets' => [
                'ticket_attachments' => [
                    'path' => $resourceRoot,
                    'label' => 'Ticket attachments',
                ],
            ],
        ]);

        try {
            (new BackupBundleService())->create($database, $bundle, ['ticket_attachments']);
            (new BackupEncryptionService())->encryptFile($bundle, $encrypted);

            $service = new BackupRecoveryService();
            $result = $service->inspect($encrypted, [
                'expected_sha256' => hash_file('sha256', $encrypted),
            ]);

            $this->assertTrue($result['checksum_ok']);
            $this->assertTrue($result['artifact']['encrypted']);
            $this->assertTrue($result['artifact']['bundled']);
            $this->assertSame(BackupBundleService::FORMAT, $result['artifact']['format']);
            $this->assertSame(1, $result['artifact']['resources'][0]['files']);
            $this->assertTrue($result['gzip_ok']);
            $this->assertTrue($result['sql_dump']);
            $this->assertSame($env, $result['env']['contents']);
            $this->assertStringContainsString('--extract-database=', $result['restore_commands'][0]);

            $databaseResult = $service->writeDatabaseArtifact($encrypted, $restoredDatabase);
            $resourceResult = $service->writeBundledResources($encrypted, $restoredResources);

            $this->assertSame(hash_file('sha256', $database), $databaseResult['sha256']);
            $this->assertCount(1, $resourceResult);
            $this->assertSame(
                'ticket-data',
                $filesystem->get(
                    $restoredResources . DIRECTORY_SEPARATOR . 'ticket_attachments'
                    . DIRECTORY_SEPARATOR . 'ticket.txt'
                )
            );
        } finally {
            $filesystem->deleteDirectory($tempDir);
        }
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
}

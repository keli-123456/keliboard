<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Backup\BackupRecoveryService;
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
        $sql = implode("\n", [
            '-- KELI_RECOVERY_START',
            '-- KELI_RECOVERY_FORMAT=env-base64-v1',
            '-- KELI_RECOVERY_DATABASE_CONNECTION=mysql',
            '-- KELI_RECOVERY_GENERATED_AT=2026-05-02T00:00:00+00:00',
            '-- KELI_RECOVERY_ENV_FILE=.env',
            '-- KELI_RECOVERY_ENV_BASE64_BEGIN',
            '-- ' . base64_encode($env),
            '-- KELI_RECOVERY_ENV_BASE64_END',
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
            $this->assertStringContainsString('base64 -d > .env', $result['restore_commands'][0]);
            $this->assertStringContainsString('mysql -h "$DB_HOST"', $result['restore_commands'][2]);
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

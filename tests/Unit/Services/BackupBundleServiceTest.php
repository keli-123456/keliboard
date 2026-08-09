<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Backup\BackupBundleService;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Tests\TestCase;

final class BackupBundleServiceTest extends TestCase
{
    private Filesystem $filesystem;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        app()->instance('files', $this->filesystem);
        $this->tempDir = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework'
            . DIRECTORY_SEPARATOR . 'backup-bundle-test-' . bin2hex(random_bytes(4));
        $this->filesystem->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_bundle_contains_database_manifest_and_selected_resources(): void
    {
        $database = $this->tempDir . DIRECTORY_SEPARATOR . 'database.sql.gz';
        $resources = $this->tempDir . DIRECTORY_SEPARATOR . 'ticket_attachments';
        $bundle = $this->tempDir . DIRECTORY_SEPARATOR . 'backup.keli.zip';
        $restoredDatabase = $this->tempDir . DIRECTORY_SEPARATOR . 'restored.sql.gz';
        $restoredResources = $this->tempDir . DIRECTORY_SEPARATOR . 'restored-resources';
        $sql = "CREATE TABLE users (id int);\n";
        $this->writeGzip($database, $sql);
        $this->filesystem->ensureDirectoryExists($resources . DIRECTORY_SEPARATOR . '1');
        $this->filesystem->put($resources . DIRECTORY_SEPARATOR . '1' . DIRECTORY_SEPARATOR . 'image.webp', 'image-data');

        config([
            'backup.resource_sets' => [
                'ticket_attachments' => [
                    'path' => $resources,
                    'label' => 'Ticket attachments',
                ],
            ],
            'backup.resource_max_files' => 100,
            'backup.resource_max_bytes' => 1048576,
        ]);

        $service = new BackupBundleService();
        $created = $service->create($database, $bundle, ['ticket_attachments']);
        $inspection = $service->inspect($bundle);
        $databaseResult = $service->extractDatabase($bundle, $restoredDatabase);
        $written = $service->extractResources($bundle, $restoredResources);

        $this->assertSame(BackupBundleService::FORMAT, $created['format']);
        $this->assertSame(BackupBundleService::FORMAT, $inspection['format']);
        $this->assertSame(1, $inspection['resources'][0]['files']);
        $this->assertSame(hash_file('sha256', $database), $databaseResult['checksum']);
        $this->assertSame($sql, $this->readGzip($restoredDatabase));
        $this->assertCount(1, $written);
        $this->assertSame(
            'image-data',
            $this->filesystem->get($restoredResources . DIRECTORY_SEPARATOR . 'ticket_attachments'
                . DIRECTORY_SEPARATOR . '1' . DIRECTORY_SEPARATOR . 'image.webp')
        );
    }

    public function test_resource_extraction_rejects_archives_over_configured_file_limit(): void
    {
        $database = $this->tempDir . DIRECTORY_SEPARATOR . 'database.sql.gz';
        $resources = $this->tempDir . DIRECTORY_SEPARATOR . 'resources';
        $bundle = $this->tempDir . DIRECTORY_SEPARATOR . 'limited.keli.zip';
        $target = $this->tempDir . DIRECTORY_SEPARATOR . 'limited-restore';
        $this->writeGzip($database, "SELECT 1;\n");
        $this->filesystem->ensureDirectoryExists($resources);
        $this->filesystem->put($resources . DIRECTORY_SEPARATOR . 'first.txt', 'first');
        $this->filesystem->put($resources . DIRECTORY_SEPARATOR . 'second.txt', 'second');

        config([
            'backup.resource_sets' => [
                'fixtures' => [
                    'path' => $resources,
                    'label' => 'Fixtures',
                ],
            ],
            'backup.resource_max_files' => 10,
            'backup.resource_max_bytes' => 1048576,
        ]);

        $service = new BackupBundleService();
        $service->create($database, $bundle, ['fixtures']);
        config(['backup.resource_max_files' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resources exceed configured safety limits');
        $service->extractResources($bundle, $target);
    }

    private function writeGzip(string $path, string $contents): void
    {
        $handle = gzopen($path, 'wb9');
        $this->assertIsResource($handle);
        gzwrite($handle, $contents);
        gzclose($handle);
    }

    private function readGzip(string $path): string
    {
        $handle = gzopen($path, 'rb');
        $this->assertIsResource($handle);
        $contents = '';
        while (!gzeof($handle)) {
            $contents .= (string) gzread($handle, 8192);
        }
        gzclose($handle);

        return $contents;
    }
}

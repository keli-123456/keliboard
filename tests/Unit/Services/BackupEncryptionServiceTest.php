<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Backup\BackupEncryptionService;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Tests\TestCase;

final class BackupEncryptionServiceTest extends TestCase
{
    private Filesystem $filesystem;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        app()->instance('files', $this->filesystem);
        $this->tempDir = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework'
            . DIRECTORY_SEPARATOR . 'backup-encryption-test-' . bin2hex(random_bytes(4));
        $this->filesystem->ensureDirectoryExists($this->tempDir);
        config(['backup.encryption_key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_encrypt_and_decrypt_large_file_without_loading_it_as_one_value(): void
    {
        $source = $this->tempDir . DIRECTORY_SEPARATOR . 'source.bin';
        $encrypted = $this->tempDir . DIRECTORY_SEPARATOR . 'source.bin.enc';
        $decrypted = $this->tempDir . DIRECTORY_SEPARATOR . 'restored.bin';
        $contents = random_bytes(1048576 + 37);
        $this->filesystem->put($source, $contents);

        $service = new BackupEncryptionService();
        $metadata = $service->encryptFile($source, $encrypted);

        $this->assertTrue($metadata['encrypted']);
        $this->assertSame('aes-256-cbc+hmac-sha256', $metadata['cipher']);
        $this->assertTrue($service->isEncryptedFile($encrypted));
        $this->assertNotSame(hash_file('sha256', $source), hash_file('sha256', $encrypted));

        $service->decryptFile($encrypted, $decrypted);

        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $decrypted));
        $this->assertSame($contents, $this->filesystem->get($decrypted));
    }

    public function test_tampered_encrypted_file_is_rejected_before_decryption(): void
    {
        $source = $this->tempDir . DIRECTORY_SEPARATOR . 'source.txt';
        $encrypted = $this->tempDir . DIRECTORY_SEPARATOR . 'source.txt.enc';
        $decrypted = $this->tempDir . DIRECTORY_SEPARATOR . 'restored.txt';
        $this->filesystem->put($source, str_repeat('backup-data-', 100));

        $service = new BackupEncryptionService();
        $service->encryptFile($source, $encrypted);

        $handle = fopen($encrypted, 'r+b');
        $this->assertIsResource($handle);
        fseek($handle, 64);
        $byte = fread($handle, 1);
        fseek($handle, 64);
        fwrite($handle, chr(ord($byte) ^ 0x01));
        fclose($handle);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('authentication failed');

        try {
            $service->decryptFile($encrypted, $decrypted);
        } finally {
            $this->assertFileDoesNotExist($decrypted);
        }
    }
}

<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class BackupEncryptionService
{
    private const MAGIC = "KELI-BACKUP-V1\0\0";
    private const SALT_BYTES = 16;
    private const IV_BYTES = 16;
    private const MAC_BYTES = 32;
    private const BLOCK_BYTES = 16;
    private const CHUNK_BYTES = 1048576;

    public function available(): bool
    {
        return extension_loaded('openssl') && $this->masterKey() !== null;
    }

    public function keyFingerprint(): ?string
    {
        $key = $this->masterKey();

        return $key === null ? null : substr(hash('sha256', $key), 0, 16);
    }

    public function isEncryptedFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        try {
            return hash_equals(self::MAGIC, (string) fread($handle, strlen(self::MAGIC)));
        } finally {
            fclose($handle);
        }
    }

    public function encryptFile(string $source, string $target): array
    {
        $masterKey = $this->requireMasterKey();
        if (!File::isFile($source) || !is_readable($source)) {
            throw new RuntimeException('Backup source file is not readable');
        }

        $salt = random_bytes(self::SALT_BYTES);
        $iv = random_bytes(self::IV_BYTES);
        [$encryptionKey, $macKey] = $this->deriveKeys($masterKey, $salt);
        $header = self::MAGIC . $salt . $iv;
        $temporary = $this->temporaryPath($target);
        $input = null;
        $output = null;

        try {
            $input = fopen($source, 'rb');
            $output = fopen($temporary, 'wb');
            if (!$input || !$output) {
                throw new RuntimeException('Failed to open backup file for encryption');
            }

            $mac = hash_init('sha256', HASH_HMAC, $macKey);
            $this->writeAll($output, $header);
            hash_update($mac, $header);

            $buffer = '';
            $currentIv = $iv;
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Failed to read backup file during encryption');
                }

                $buffer .= $chunk;
                $processable = strlen($buffer) - (strlen($buffer) % self::BLOCK_BYTES);
                if ($processable <= 0) {
                    continue;
                }

                $plain = substr($buffer, 0, $processable);
                $buffer = substr($buffer, $processable);
                $cipher = openssl_encrypt(
                    $plain,
                    'aes-256-cbc',
                    $encryptionKey,
                    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                    $currentIv
                );
                if (!is_string($cipher)) {
                    throw new RuntimeException('Failed to encrypt backup file');
                }

                $this->writeAll($output, $cipher);
                hash_update($mac, $cipher);
                $currentIv = substr($cipher, -self::IV_BYTES);
            }

            $padding = self::BLOCK_BYTES - (strlen($buffer) % self::BLOCK_BYTES);
            $finalPlain = $buffer . str_repeat(chr($padding), $padding);
            $finalCipher = openssl_encrypt(
                $finalPlain,
                'aes-256-cbc',
                $encryptionKey,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $currentIv
            );
            if (!is_string($finalCipher)) {
                throw new RuntimeException('Failed to finalize backup encryption');
            }

            $this->writeAll($output, $finalCipher);
            hash_update($mac, $finalCipher);
            $this->writeAll($output, hash_final($mac, true));

            fclose($input);
            fclose($output);
            $input = null;
            $output = null;
            $this->replaceFile($temporary, $target);

            return [
                'encrypted' => true,
                'cipher' => 'aes-256-cbc+hmac-sha256',
                'key_fingerprint' => $this->keyFingerprint(),
                'size' => File::size($target),
            ];
        } catch (Throwable $e) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            File::delete($temporary);
            throw $e;
        }
    }

    public function decryptFile(string $source, string $target): array
    {
        $masterKey = $this->requireMasterKey();
        $size = File::isFile($source) ? File::size($source) : 0;
        $headerBytes = strlen(self::MAGIC) + self::SALT_BYTES + self::IV_BYTES;
        $cipherBytes = $size - $headerBytes - self::MAC_BYTES;
        if ($cipherBytes <= 0 || $cipherBytes % self::BLOCK_BYTES !== 0) {
            throw new RuntimeException('Encrypted backup file is truncated');
        }

        $input = fopen($source, 'rb');
        if (!$input) {
            throw new RuntimeException('Encrypted backup file is not readable');
        }

        $header = (string) fread($input, $headerBytes);
        if (strlen($header) !== $headerBytes || !hash_equals(self::MAGIC, substr($header, 0, strlen(self::MAGIC)))) {
            fclose($input);
            throw new RuntimeException('Encrypted backup header is invalid');
        }

        $salt = substr($header, strlen(self::MAGIC), self::SALT_BYTES);
        $iv = substr($header, strlen(self::MAGIC) + self::SALT_BYTES, self::IV_BYTES);
        [$encryptionKey, $macKey] = $this->deriveKeys($masterKey, $salt);
        $mac = hash_init('sha256', HASH_HMAC, $macKey);
        hash_update($mac, $header);

        $remaining = $cipherBytes;
        while ($remaining > 0) {
            $chunk = fread($input, min(self::CHUNK_BYTES, $remaining));
            if (!is_string($chunk) || $chunk === '') {
                fclose($input);
                throw new RuntimeException('Encrypted backup payload is truncated');
            }
            hash_update($mac, $chunk);
            $remaining -= strlen($chunk);
        }

        $storedMac = (string) fread($input, self::MAC_BYTES);
        fclose($input);
        if (strlen($storedMac) !== self::MAC_BYTES || !hash_equals(hash_final($mac, true), $storedMac)) {
            throw new RuntimeException('Encrypted backup authentication failed');
        }

        $temporary = $this->temporaryPath($target);
        $input = null;
        $output = null;

        try {
            $input = fopen($source, 'rb');
            $output = fopen($temporary, 'wb');
            if (!$input || !$output) {
                throw new RuntimeException('Failed to open backup file for decryption');
            }
            fseek($input, $headerBytes);

            $remaining = $cipherBytes;
            $currentIv = $iv;
            $pendingPlain = null;
            while ($remaining > 0) {
                $cipher = fread($input, min(self::CHUNK_BYTES, $remaining));
                if (!is_string($cipher) || $cipher === '' || strlen($cipher) % self::BLOCK_BYTES !== 0) {
                    throw new RuntimeException('Encrypted backup payload is invalid');
                }

                $plain = openssl_decrypt(
                    $cipher,
                    'aes-256-cbc',
                    $encryptionKey,
                    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                    $currentIv
                );
                if (!is_string($plain)) {
                    throw new RuntimeException('Failed to decrypt backup file');
                }

                if (is_string($pendingPlain)) {
                    $this->writeAll($output, $pendingPlain);
                }
                $pendingPlain = $plain;
                $currentIv = substr($cipher, -self::IV_BYTES);
                $remaining -= strlen($cipher);
            }

            if (!is_string($pendingPlain) || $pendingPlain === '') {
                throw new RuntimeException('Encrypted backup payload is empty');
            }
            $padding = ord(substr($pendingPlain, -1));
            if ($padding < 1 || $padding > self::BLOCK_BYTES) {
                throw new RuntimeException('Encrypted backup padding is invalid');
            }
            $expectedPadding = str_repeat(chr($padding), $padding);
            if (!hash_equals($expectedPadding, substr($pendingPlain, -$padding))) {
                throw new RuntimeException('Encrypted backup padding is invalid');
            }
            $this->writeAll($output, substr($pendingPlain, 0, -$padding));

            fclose($input);
            fclose($output);
            $input = null;
            $output = null;
            $this->replaceFile($temporary, $target);

            return [
                'encrypted' => true,
                'cipher' => 'aes-256-cbc+hmac-sha256',
                'key_fingerprint' => $this->keyFingerprint(),
                'size' => File::size($target),
            ];
        } catch (Throwable $e) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            File::delete($temporary);
            throw $e;
        }
    }

    private function requireMasterKey(): string
    {
        $key = $this->masterKey();
        if ($key === null) {
            throw new RuntimeException('Backup encryption key is not configured');
        }

        return $key;
    }

    private function masterKey(): ?string
    {
        $configured = trim((string) config('backup.encryption_key', ''));
        if ($configured === '') {
            return null;
        }

        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return hash('sha256', $decoded, true);
            }
        }

        return hash('sha256', $configured, true);
    }

    private function deriveKeys(string $masterKey, string $salt): array
    {
        $material = hash_hkdf('sha256', $masterKey, 64, 'keli-backup-v1', $salt);
        if (!is_string($material) || strlen($material) !== 64) {
            throw new RuntimeException('Failed to derive backup encryption keys');
        }

        return [substr($material, 0, 32), substr($material, 32, 32)];
    }

    private function temporaryPath(string $target): string
    {
        File::ensureDirectoryExists(dirname($target));

        return $target . '.part-' . bin2hex(random_bytes(6));
    }

    private function replaceFile(string $source, string $target): void
    {
        File::delete($target);
        if (!@rename($source, $target)) {
            File::delete($source);
            throw new RuntimeException('Failed to finalize backup file');
        }
    }

    private function writeAll($handle, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('Failed to write backup file');
            }
            $offset += $written;
        }
    }
}

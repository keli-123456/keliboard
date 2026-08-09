<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupBundleService
{
    public const FORMAT = 'keli-disaster-recovery-v2';
    public const MANIFEST_ENTRY = 'manifest.json';
    public const DATABASE_ENTRY = 'database/database.sql.gz';

    public function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function isBundleFile(string $path): bool
    {
        if (!File::isFile($path) || !is_readable($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        try {
            $signature = (string) fread($handle, 4);
        } finally {
            fclose($handle);
        }

        return in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }


    public function resourceSets(): array
    {
        $sets = [];
        foreach ((array) config('backup.resource_sets', []) as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $sets[] = [
                'key' => (string) $key,
                'label' => (string) ($definition['label'] ?? $key),
                'path' => (string) ($definition['path'] ?? ''),
                'exists' => File::isDirectory($this->resolveConfiguredPath((string) ($definition['path'] ?? ''))),
            ];
        }

        return $sets;
    }

    public function create(string $databaseGzip, string $target, array $selectedResourceSets = []): array
    {
        if (!$this->available()) {
            throw new RuntimeException('PHP zip extension is not available');
        }
        if (!File::isFile($databaseGzip) || !is_readable($databaseGzip)) {
            throw new RuntimeException('Compressed database dump is not readable');
        }

        $temporary = $target . '.part-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists(dirname($target));
        $zip = new ZipArchive();
        $opened = $zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Failed to create disaster recovery bundle');
        }

        try {
            if (!$zip->addFile($databaseGzip, self::DATABASE_ENTRY)) {
                throw new RuntimeException('Failed to add database dump to recovery bundle');
            }
            $zip->setCompressionName(self::DATABASE_ENTRY, ZipArchive::CM_STORE);

            $resourceManifest = $this->addResources($zip, $selectedResourceSets);
            $manifest = [
                'format' => self::FORMAT,
                'generated_at' => now()->toIso8601String(),
                'database' => [
                    'entry' => self::DATABASE_ENTRY,
                    'bytes' => File::size($databaseGzip),
                    'sha256' => hash_file('sha256', $databaseGzip),
                ],
                'resources' => $resourceManifest,
            ];
            $encodedManifest = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($encodedManifest) || !$zip->addFromString(self::MANIFEST_ENTRY, $encodedManifest)) {
                throw new RuntimeException('Failed to write recovery bundle manifest');
            }

            if (!$zip->close()) {
                throw new RuntimeException('Failed to finalize disaster recovery bundle');
            }
            File::delete($target);
            if (!@rename($temporary, $target)) {
                throw new RuntimeException('Failed to move disaster recovery bundle into place');
            }

            return [
                'format' => self::FORMAT,
                'filename' => basename($target),
                'size' => File::size($target),
                'checksum' => hash_file('sha256', $target),
                'database' => $manifest['database'],
                'resources' => $resourceManifest,
            ];
        } catch (Throwable $e) {
            $zip->close();
            File::delete($temporary);
            throw $e;
        }
    }

    public function inspect(string $path): array
    {
        $zip = $this->open($path);

        try {
            $manifestJson = $zip->getFromName(self::MANIFEST_ENTRY);
            $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
                throw new RuntimeException('Recovery bundle manifest is invalid');
            }
            if ($zip->locateName(self::DATABASE_ENTRY) === false) {
                throw new RuntimeException('Recovery bundle database dump is missing');
            }

            return [
                'format' => self::FORMAT,
                'manifest' => $manifest,
                'entries' => $zip->numFiles,
                'resources' => is_array($manifest['resources'] ?? null) ? $manifest['resources'] : [],
            ];
        } finally {
            $zip->close();
        }
    }

    public function extractDatabase(string $bundle, string $target): array
    {
        $zip = $this->open($bundle);

        try {
            $manifestJson = $zip->getFromName(self::MANIFEST_ENTRY);
            $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
                throw new RuntimeException('Recovery bundle manifest is invalid');
            }

            $this->copyEntry($zip, self::DATABASE_ENTRY, $target);
            $expected = strtolower((string) data_get($manifest, 'database.sha256', ''));
            $actual = strtolower((string) hash_file('sha256', $target));
            if ($expected === '' || !hash_equals($expected, $actual)) {
                File::delete($target);
                throw new RuntimeException('Recovery bundle database checksum mismatch');
            }

            return [
                'path' => $target,
                'size' => File::size($target),
                'checksum' => $actual,
                'manifest' => $manifest,
            ];
        } finally {
            $zip->close();
        }
    }

    public function extractResources(string $bundle, string $targetDirectory): array
    {
        $zip = $this->open($bundle);
        File::ensureDirectoryExists($targetDirectory);
        $targetRoot = rtrim(str_replace('\\', '/', $targetDirectory), '/');
        $written = [];
        $maxFiles = max(1, (int) config('backup.resource_max_files', 20000));
        $maxBytes = max(1, (int) config('backup.resource_max_bytes', 5368709120));
        $totalFiles = 0;
        $totalBytes = 0;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (!str_starts_with($name, 'resources/') || str_ends_with($name, '/')) {
                    continue;
                }

                $stat = $zip->statIndex($index);
                $declaredSize = max(0, (int) (is_array($stat) ? ($stat['size'] ?? 0) : 0));
                if (++$totalFiles > $maxFiles || $totalBytes + $declaredSize > $maxBytes) {
                    throw new RuntimeException('Recovery bundle resources exceed configured safety limits');
                }
                $totalBytes += $declaredSize;

                $relative = $this->safeEntryName(substr($name, strlen('resources/')));
                $target = $targetDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                File::ensureDirectoryExists(dirname($target));
                $normalizedTarget = str_replace('\\', '/', dirname($target) . DIRECTORY_SEPARATOR . basename($target));
                if (!str_starts_with($normalizedTarget, $targetRoot . '/')) {
                    throw new RuntimeException('Recovery bundle resource path is invalid');
                }

                $this->copyEntry($zip, $name, $target);
                $actualSize = File::size($target);
                if ($actualSize !== $declaredSize) {
                    File::delete($target);
                    throw new RuntimeException('Recovery bundle resource size mismatch');
                }
                $written[] = [
                    'entry' => $name,
                    'path' => $target,
                    'size' => $actualSize,
                ];
            }

            return $written;
        } catch (Throwable $e) {
            foreach ($written as $file) {
                File::delete((string) ($file['path'] ?? ''));
            }
            throw $e;
        } finally {
            $zip->close();
        }
    }

    private function addResources(ZipArchive $zip, array $selected): array
    {
        $definitions = (array) config('backup.resource_sets', []);
        $selected = array_values(array_unique(array_filter(
            array_map('strval', $selected),
            fn(string $key): bool => isset($definitions[$key]) && is_array($definitions[$key])
        )));
        $maxFiles = max(1, (int) config('backup.resource_max_files', 20000));
        $maxBytes = max(1, (int) config('backup.resource_max_bytes', 5368709120));
        $totalFiles = 0;
        $totalBytes = 0;
        $manifest = [];

        foreach ($selected as $key) {
            $definition = $definitions[$key];
            $root = $this->resolveConfiguredPath((string) ($definition['path'] ?? ''));
            $summary = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'source' => (string) ($definition['path'] ?? ''),
                'present' => File::isDirectory($root),
                'files' => 0,
                'bytes' => 0,
            ];
            if (!$summary['present']) {
                $manifest[] = $summary;
                continue;
            }

            $realRoot = realpath($root);
            if (!is_string($realRoot)) {
                $manifest[] = $summary;
                continue;
            }
            $normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isLink() || !$file->isFile()) {
                    continue;
                }
                $realPath = $file->getRealPath();
                if (!is_string($realPath)) {
                    continue;
                }
                $normalizedPath = str_replace('\\', '/', $realPath);
                if (!str_starts_with($normalizedPath, $normalizedRoot)) {
                    continue;
                }

                $size = max(0, (int) $file->getSize());
                if (++$totalFiles > $maxFiles || $totalBytes + $size > $maxBytes) {
                    throw new RuntimeException('Selected backup resources exceed configured safety limits');
                }
                $totalBytes += $size;
                $relative = ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
                $entry = 'resources/' . $key . '/' . $this->safeEntryName($relative);
                if (!$zip->addFile($realPath, $entry)) {
                    throw new RuntimeException('Failed to add resource file to recovery bundle');
                }
                $summary['files']++;
                $summary['bytes'] += $size;
            }

            $manifest[] = $summary;
        }

        return $manifest;
    }

    private function open(string $path): ZipArchive
    {
        if (!$this->available()) {
            throw new RuntimeException('PHP zip extension is not available');
        }
        if (!File::isFile($path) || !is_readable($path)) {
            throw new RuntimeException('Recovery bundle is not readable');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Recovery bundle cannot be opened');
        }

        return $zip;
    }

    private function copyEntry(ZipArchive $zip, string $entry, string $target): void
    {
        $input = $zip->getStream($entry);
        if (!is_resource($input)) {
            throw new RuntimeException("Recovery bundle entry {$entry} is missing");
        }

        File::ensureDirectoryExists(dirname($target));
        $temporary = $target . '.part-' . bin2hex(random_bytes(4));
        $output = fopen($temporary, 'wb');
        if (!$output) {
            fclose($input);
            throw new RuntimeException('Failed to create extracted recovery file');
        }

        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('Failed to extract recovery bundle entry');
            }
            fclose($input);
            fclose($output);
            $input = null;
            $output = null;
            File::delete($target);
            if (!@rename($temporary, $target)) {
                throw new RuntimeException('Failed to finalize extracted recovery file');
            }
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

    private function resolveConfiguredPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path)) {
            return $path;
        }

        try {
            return base_path($path);
        } catch (Throwable) {
            return getcwd() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }
    }

    private function safeEntryName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        $segments = array_values(array_filter(explode('/', $name), fn(string $segment): bool => $segment !== ''));
        if ($segments === [] || in_array('..', $segments, true)) {
            throw new RuntimeException('Recovery bundle contains an unsafe path');
        }

        return implode('/', $segments);
    }
}

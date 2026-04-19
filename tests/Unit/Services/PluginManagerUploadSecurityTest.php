<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Plugin\PluginManager;
use Tests\TestCase;

final class PluginManagerUploadSecurityTest extends TestCase
{
    public function test_archive_guard_rejects_path_traversal_entries(): void
    {
        $archivePath = $this->createArchive([
            '../evil.txt' => 'owned',
            'Demo/config.json' => '{}',
        ]);

        $this->assertArchiveGuardFails($archivePath, '非法路径条目');
    }

    public function test_archive_guard_rejects_absolute_path_entries(): void
    {
        $archivePath = $this->createArchive([
            '/etc/passwd' => 'owned',
            'Demo/config.json' => '{}',
        ]);

        $this->assertArchiveGuardFails($archivePath, '非法路径条目');
    }

    public function test_archive_guard_rejects_symlink_entries(): void
    {
        $archivePath = $this->createArchive(
            [
                'Demo/config.json' => '{}',
                'Demo/link' => 'target.txt',
            ],
            static function (\ZipArchive $zip): void {
                if (!method_exists($zip, 'setExternalAttributesName')) {
                    return;
                }
                $isSymlinkMode = (0120000 | 0777) << 16;
                $zip->setExternalAttributesName('Demo/link', \ZipArchive::OPSYS_UNIX, $isSymlinkMode);
            }
        );

        $zip = $this->openArchive($archivePath);
        try {
            if (!method_exists($zip, 'getExternalAttributesIndex')) {
                $this->markTestSkipped('Zip extension does not support external attributes inspection.');
            }
            $this->invokeArchiveGuard(new PluginManager(), $zip);
            $this->fail('Expected archive guard to reject symlink entry.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('符号链接', $e->getMessage());
        } finally {
            $zip->close();
            @unlink($archivePath);
        }
    }

    public function test_archive_guard_accepts_normal_plugin_archive(): void
    {
        $archivePath = $this->createArchive([
            'DemoPlugin/config.json' => json_encode([
                'name' => 'Demo Plugin',
                'code' => 'demo_plugin',
                'version' => '1.0.0',
                'description' => 'demo',
                'author' => 'tester',
            ], JSON_UNESCAPED_UNICODE),
            'DemoPlugin/Plugin.php' => '<?php // demo',
        ]);

        $zip = $this->openArchive($archivePath);
        try {
            $this->invokeArchiveGuard(new PluginManager(), $zip);
            $this->assertTrue(true);
        } finally {
            $zip->close();
            @unlink($archivePath);
        }
    }

    private function assertArchiveGuardFails(string $archivePath, string $expectedMessage): void
    {
        $zip = $this->openArchive($archivePath);
        try {
            $this->invokeArchiveGuard(new PluginManager(), $zip);
            $this->fail('Expected archive guard to reject malicious archive.');
        } catch (\Exception $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        } finally {
            $zip->close();
            @unlink($archivePath);
        }
    }

    private function invokeArchiveGuard(PluginManager $manager, \ZipArchive $zip): void
    {
        $method = new \ReflectionMethod(PluginManager::class, 'assertArchiveIsSafeToExtract');
        $method->setAccessible(true);
        $method->invoke($manager, $zip);
    }

    /**
     * @param array<string, string> $entries
     * @param null|callable(\ZipArchive):void $configure
     */
    private function createArchive(array $entries, ?callable $configure = null): string
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'plugin_zip_');
        if ($archivePath === false) {
            $this->fail('Failed to create temporary zip archive path.');
        }

        $zip = new \ZipArchive();
        $result = $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            @unlink($archivePath);
            $this->fail('Failed to open temporary zip archive for writing.');
        }

        foreach ($entries as $name => $content) {
            if (!$zip->addFromString($name, $content)) {
                $zip->close();
                @unlink($archivePath);
                $this->fail("Failed to add zip entry: {$name}");
            }
        }

        if ($configure) {
            $configure($zip);
        }

        $zip->close();
        return $archivePath;
    }

    private function openArchive(string $archivePath): \ZipArchive
    {
        $zip = new \ZipArchive();
        $result = $zip->open($archivePath);
        if ($result !== true) {
            @unlink($archivePath);
            $this->fail('Failed to open zip archive for reading.');
        }
        return $zip;
    }
}

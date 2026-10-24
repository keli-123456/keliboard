<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\PluginController;
use App\Models\Plugin;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PluginSourceIsolationTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private string $root;
    private Filesystem $files;
    private PluginManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->files = new Filesystem();
        app()->instance('files', $this->files);
        $this->root = sys_get_temp_dir() . '/keli_plugin_sources_' . bin2hex(random_bytes(8));
        $this->files->makeDirectory($this->root . '/plugins-core', 0755, true);
        $this->files->makeDirectory($this->root . '/plugins', 0755, true);
        app()->useStoragePath($this->root . '/storage');
        $this->manager = new class($this->root) extends PluginManager {
            public function __construct(string $root)
            {
                $this->corePluginPath = $root . '/plugins-core';
                $this->pluginPath = $root . '/plugins';
            }
        };
        app()->instance(PluginManager::class, $this->manager);
        $this->database->schema()->create('v2_plugins', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code')->unique();
            $table->string('name')->default('Test');
            $table->string('version')->default('1.0.0');
            $table->string('type')->default('feature');
            $table->boolean('is_enabled')->default(false);
            $table->text('config')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        $this->files->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_core_metadata_and_configuration_win_without_removing_old_user_files(): void
    {
        $core = $this->fixture('plugins-core', 'bundled_probe');
        $old = $this->fixture('plugins', 'bundled_probe', '0.1.0');
        $uploaded = $this->fixture('plugins', 'uploaded_probe');
        $this->assertTrue($this->manager->isCorePlugin('bundled_probe'));
        $this->assertSame($core, $this->manager->getPluginPath('bundled_probe'));
        $this->assertSame($uploaded, $this->manager->getPluginPath('uploaded_probe'));
        $this->assertSame(array_map('realpath', [$core, $uploaded]), array_map('realpath', $this->manager->getPluginDirectories()));
        $this->assertFileExists($old . '/config.json');

        Plugin::create(['code' => 'bundled_probe', 'is_enabled' => true, 'config' => json_encode(['setting' => 'saved-value'])]);
        $config = (new PluginConfigService())->getConfig('bundled_probe');
        $this->assertSame('saved-value', $config['setting']['value']);
        $this->assertTrue(Plugin::where('code', 'bundled_probe')->firstOrFail()->is_enabled);
    }

    public function test_plugin_list_deduplicates_core_plugins_and_keeps_uploads_manageable(): void
    {
        $this->fixture('plugins-core', 'bundled_probe');
        $this->fixture('plugins', 'bundled_probe', '0.1.0');
        $this->fixture('plugins', 'uploaded_probe');
        $controller = new PluginController($this->manager, new PluginConfigService());
        $response = $controller->index(Request::create('/plugins'));
        $plugins = collect($response->getData(true)['data'])->keyBy('code');
        $this->assertCount(2, $plugins);
        $this->assertSame('1.0.0', $plugins['bundled_probe']['version']);
        $this->assertTrue($plugins['bundled_probe']['is_core']);
        $this->assertFalse($plugins['bundled_probe']['can_be_deleted']);
        $this->assertFalse($plugins['uploaded_probe']['is_core']);
        $this->assertTrue($plugins['uploaded_probe']['can_be_deleted']);
    }

    public function test_core_entrypoint_is_loaded_before_any_legacy_autoloader(): void
    {
        $name = 'SourceProbe' . bin2hex(random_bytes(4));
        $directory = $this->root . '/plugins-core/' . $name;
        $this->files->makeDirectory($directory);
        $class = 'Plugin\\' . $name . '\\Plugin';
        $this->files->put($directory . '/Plugin.php', '<?php namespace Plugin\\' . $name
            . '; class Plugin extends \\App\\Services\\Plugin\\AbstractPlugin { public function boot(): void {} }');
        $autoloaded = false;
        $loader = static function (string $requested) use ($class, &$autoloaded): void {
            if ($requested === $class) {
                $autoloaded = true;
            }
        };
        spl_autoload_register($loader, true, true);
        try {
            $method = new \ReflectionMethod(PluginManager::class, 'loadPlugin');
            $plugin = $method->invoke($this->manager, $name);
            $this->assertFalse($autoloaded);
            $this->assertSame(realpath($directory), realpath($plugin->getBasePath()));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function test_core_files_cannot_be_deleted_even_via_the_manager_directly(): void
    {
        $directory = $this->fixture('plugins-core', 'bundled_probe');
        try {
            $this->manager->delete('bundled_probe');
            $this->fail('Bundled files must not be deleted.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('内置插件', $e->getMessage());
            $this->assertFileExists($directory . '/config.json');
        }
    }

    public function test_incomplete_core_files_do_not_erase_saved_plugin_configuration(): void
    {
        $this->fixture('plugins-core', 'bundled_probe');
        Plugin::create(['code' => 'bundled_probe', 'is_enabled' => true, 'config' => '{"setting":"saved-value"}']);
        $plugin = (new \ReflectionMethod(PluginManager::class, 'loadPlugin'))->invoke($this->manager, 'bundled_probe');
        $this->assertNull($plugin);
        $this->assertSame('{"setting":"saved-value"}', Plugin::where('code', 'bundled_probe')->firstOrFail()->config);
    }

    public function test_uploaded_archive_cannot_replace_a_bundled_plugin(): void
    {
        $directory = $this->fixture('plugins-core', 'bundled_probe');
        $old = $this->fixture('plugins', 'bundled_probe', '0.1.0');
        $archive = $this->archive('bundled_probe', '9.0.0');
        try {
            $this->manager->upload(new UploadedFile($archive, 'plugin.zip', null, null, true));
            $this->fail('A ZIP must not shadow a bundled plugin.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('内置插件', $e->getMessage());
            $this->assertSame('1.0.0', json_decode($this->files->get($directory . '/config.json'), true)['version']);
            $this->assertSame('0.1.0', json_decode($this->files->get($old . '/config.json'), true)['version']);
            $this->assertSame([], $this->files->directories($this->root . '/storage/tmp/plugins'));
        }
    }

    public function test_new_user_plugins_can_still_be_uploaded(): void
    {
        $archive = $this->archive('uploaded_probe', '1.0.0');
        $this->assertTrue($this->manager->upload(new UploadedFile($archive, 'plugin.zip', null, null, true)));
        $this->assertFalse($this->manager->isCorePlugin('uploaded_probe'));
        $this->assertFileExists($this->root . '/plugins/UploadedProbe/config.json');
    }

    public function test_bundled_migrations_use_the_resolved_absolute_directory(): void
    {
        $directory = $this->fixture('plugins-core', 'bundled_probe');
        $migrations = $directory . '/database/migrations';
        $this->files->makeDirectory($migrations, 0755, true);
        $kernel = \Mockery::mock(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->shouldReceive('call')->once()->with('migrate', ['--path' => $migrations, '--realpath' => true, '--force' => true]);
        app()->instance(\Illuminate\Contracts\Console\Kernel::class, $kernel);
        (new \ReflectionMethod(PluginManager::class, 'runMigrations'))->invoke($this->manager, 'bundled_probe');
        $this->addToAssertionCount(1);
    }

    public function test_composer_autoloads_packaged_classes_from_the_core_directory(): void
    {
        $reflection = new \ReflectionClass(\Plugin\Epay\Plugin::class);
        $this->assertStringContainsString('/plugins-core/', str_replace('\\', '/', $reflection->getFileName()));
    }

    private function fixture(string $root, string $code, string $version = '1.0.0'): string
    {
        $path = $this->root . '/' . $root . '/' . \Illuminate\Support\Str::studly($code);
        $this->files->makeDirectory($path, 0755, true);
        $this->files->put($path . '/config.json', json_encode($this->metadata($code, $version)));
        return $path;
    }

    private function metadata(string $code, string $version): array
    {
        return ['code' => $code, 'version' => $version, 'name' => 'Fixture', 'description' => 'Fixture plugin',
            'author' => 'Tests', 'type' => 'feature', 'config' => ['setting' => ['type' => 'string', 'default' => 'default-value']]];
    }

    private function archive(string $code, string $version): string
    {
        $path = $this->root . '/archive.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('Package/config.json', json_encode($this->metadata($code, $version)));
        $zip->addFromString('Package/Plugin.php', '<?php // upload fixture');
        $zip->close();
        return $path;
    }
}

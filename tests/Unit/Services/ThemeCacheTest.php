<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HiddenApiPathService;
use App\Services\SiteNavigationService;
use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\View\ViewServiceProvider;
use Psr\Log\NullLogger;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;
use ZipArchive;

final class ThemeCacheTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private string $root;
    private string $webRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webRoutes = base_path('routes/web.php');
        $this->root = storage_path('framework/testing/theme-cache-' . bin2hex(random_bytes(8)));
        File::makeDirectory($this->root . '/storage/framework/views', 0755, true);
        app()->setBasePath($this->root);
        app()->useStoragePath($this->root . '/storage');
        app()->usePublicPath($this->root . '/public');
        config([
            'app.key' => 'theme-cache-test-key',
            'view.paths' => [],
            'view.compiled' => storage_path('framework/views'),
        ]);
        app()->register(ViewServiceProvider::class);
        app()->register(RoutingServiceProvider::class);
        app()->instance('log', new NullLogger());
        $this->bindTestSettings([
            'frontend_theme' => 'custom',
            'current_theme' => 'custom',
            'theme_custom' => ['accent' => 'green'],
        ]);
    }

    protected function tearDown(): void
    {
        // Only remove this test's isolated workspace, never installed themes.
        if (isset($this->root) && str_contains($this->root, 'theme-cache-')) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_asset_version_changes_with_theme_not_panel_release_or_mtime(): void
    {
        $this->writeTheme('custom', '1.0.0');
        $service = new ThemeService();
        $first = $service->getAssetVersion('custom', 'panel-1');
        $this->assertSame($first, $service->getAssetVersion('custom', 'panel-1'));
        touch(storage_path('theme/custom/config.json'), 315532800);
        $this->assertSame($first, $service->getAssetVersion('custom', 'panel-1'));
        $this->writeTheme('custom', '1.0.1');
        touch(storage_path('theme/custom/config.json'), 315532800);
        $this->assertNotSame($first, $service->getAssetVersion('custom', 'panel-1'));
        $this->assertNotSame($first, $service->getAssetVersion('custom', 'panel-2'));
    }

    public function test_themes_without_metadata_keep_legacy_version_fallback(): void
    {
        $this->assertSame('panel-1', (new ThemeService())->getAssetVersion('missing', 'panel-1'));
    }

    public function test_upload_immediately_publishes_assets_without_switching_theme(): void
    {
        $service = new ThemeService();
        $this->assertTrue($service->upload($this->package('1.0.0', 'new', 'alternative')));
        $this->assertSame('new-js', File::get(public_path('theme/alternative/assets/umi.js')));
        $this->assertSame('new-css', File::get(public_path('theme/alternative/assets/umi.css')));
        $this->assertStringContainsString('new', view('theme::alternative.dashboard')->render());
        $this->assertSame('custom', admin_setting('frontend_theme'));
        $this->assertSame('custom', admin_setting('current_theme'));
    }

    public function test_upload_invalidates_compiled_views_and_preserves_settings(): void
    {
        $this->writeTheme('custom', '1.0.0');
        $this->writeTheme('other', '1.0.0');
        $service = new ThemeService();
        $old = view('theme::custom.dashboard')->render();
        $other = view('theme::other.dashboard')->render();
        $compiled = app('blade.compiler')->getCompiledPath(View::getFinder()->find('theme::custom.dashboard'));
        $otherCompiled = app('blade.compiler')->getCompiledPath(View::getFinder()->find('theme::other.dashboard'));
        touch($compiled, time() + 3600);
        cache()->put('theme_custom_assets', 'old');
        cache()->put('unrelated-cache', 'keep');

        $this->assertTrue($service->upload($this->package('1.0.1', 'new')));

        $this->assertFileDoesNotExist($compiled);
        $this->assertFileExists($otherCompiled);
        $this->assertNotSame($old, view('theme::custom.dashboard')->render());
        $this->assertStringContainsString('new', view('theme::custom.dashboard')->render());
        $this->assertSame($other, view('theme::other.dashboard')->render());
        $this->assertSame('new-js', File::get(public_path('theme/custom/assets/umi.js')));
        $this->assertSame('green', admin_setting('theme_custom')['accent']);
        $this->assertSame('custom', admin_setting('frontend_theme'));
        $this->assertNull(cache()->get('theme_custom_assets'));
        $this->assertSame('keep', cache()->get('unrelated-cache'));
    }

    public function test_rejected_upload_keeps_existing_theme_and_public_assets(): void
    {
        $service = new ThemeService();
        $service->upload($this->package('1.0.0', 'old'));
        try {
            $service->upload($this->package('1.0.0', 'new'));
            $this->fail('Same-version uploads must still be rejected.');
        } catch (\Exception $e) {
            $this->assertSame('Theme exists and not a newer version', $e->getMessage());
        }
        $this->assertSame('old-js', File::get(public_path('theme/custom/assets/umi.js')));
        $this->assertStringContainsString('old', view('theme::custom.dashboard')->render());
    }

    public function test_homepage_is_not_cacheable_and_changes_legacy_asset_urls_after_upload(): void
    {
        $this->writeTheme('custom', '1.0.0');
        $navigation = $this->createMock(SiteNavigationService::class);
        $navigation->method('pageForRequest')->willReturn(null);
        app()->instance(SiteNavigationService::class, $navigation);
        $update = $this->createMock(UpdateService::class);
        $update->method('getCurrentVersion')->willReturn('panel-1');
        app()->instance(UpdateService::class, $update);
        $hiddenApi = $this->createMock(HiddenApiPathService::class);
        $hiddenApi->method('get')->willReturn('/api/v1');
        app()->instance(HiddenApiPathService::class, $hiddenApi);
        $request = Request::create('https://theme.example.test/');
        app()->instance('request', $request);
        require $this->webRoutes;
        $first = app('router')->dispatch($request);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertTrue($first->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($first->headers->hasCacheControlDirective('private'));
        $this->assertSame('no-cache', $first->headers->get('Pragma'));
        preg_match('/umi\.js\?v=([^"]+)/', $first->getContent(), $oldUrl);
        $this->assertNotEmpty($oldUrl[1] ?? null);

        (new ThemeService())->upload($this->package('1.0.1', 'new'));
        $second = app('router')->dispatch($request);
        preg_match('/umi\.js\?v=([^"]+)/', $second->getContent(), $newUrl);
        $this->assertNotEmpty($newUrl[1] ?? null);
        $this->assertNotSame($oldUrl[1], $newUrl[1]);
        $this->assertStringContainsString('new', $second->getContent());
    }

    private function writeTheme(string $name, string $version): void
    {
        $path = storage_path('theme/' . $name);
        File::ensureDirectoryExists($path . '/assets');
        File::put($path . '/config.json', json_encode($this->metadata($name, $version)));
        File::put($path . '/dashboard.blade.php', $this->blade('old'));
        File::put($path . '/assets/umi.js', 'old-js');
    }

    private function metadata(string $name, string $version): array
    {
        return ['name' => $name, 'version' => $version, 'configs' => [
            ['field_name' => 'accent', 'default_value' => 'blue'],
        ]];
    }

    private function blade(string $label): string
    {
        return '<div>' . $label . '</div><script src="/theme/{{$theme ?? \'custom\'}}/assets/umi.js?v={{$version ?? \'none\'}}"></script>';
    }

    private function package(string $version, string $label, string $name = 'custom'): UploadedFile
    {
        $path = $this->root . '/' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE));
        foreach ([
            'config.json' => json_encode($this->metadata($name, $version)),
            'dashboard.blade.php' => $this->blade($label),
            'assets/umi.js' => $label . '-js',
            'assets/umi.css' => $label . '-css',
        ] as $file => $content) {
            $zip->addFromString($name . '/' . $file, $content);
            $zip->setMtimeName($name . '/' . $file, 315532800);
        }
        $zip->close();
        return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
    }
}

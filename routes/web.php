<?php

use App\Services\ThemeService;
use App\Services\HiddenApiPathService;
use App\Services\SiteNavigationService;
use App\Services\UpdateService;
use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\WellKnown\KeliClientDiscoveryController;
use App\Support\LegacySubscribeRoutePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/.well-known/keli-client.json', [KeliClientDiscoveryController::class, 'show']);

// Theme locale fallback (for themes missing `assets/locales/*.json`)
Route::get('/theme/{theme}/assets/locales/{locale}.json', function (string $theme, string $locale) {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $theme) || !preg_match('/^[A-Za-z0-9_-]+$/', $locale)) {
        abort(404);
    }

    $publicPath = public_path("theme/{$theme}/assets/locales/{$locale}.json");
    if (File::exists($publicPath)) {
        return response()->file($publicPath, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    $fallbackPath = resource_path("theme-locales/{$locale}.json");
    if (File::exists($fallbackPath)) {
        return response()->file($fallbackPath, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    $aliasLocale = match ($locale) {
        'zh' => 'zh-CN',
        'en' => 'en-US',
        default => null,
    };
    if ($aliasLocale) {
        $aliasPath = resource_path("theme-locales/{$aliasLocale}.json");
        if (File::exists($aliasPath)) {
            return response()->file($aliasPath, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
    }

    return response()->json((object) []);
})->where([
    'theme' => '[A-Za-z0-9_-]+',
    'locale' => '[A-Za-z0-9_-]+',
]);


Route::get('/', function (Request $request) {
    $navigation = app(SiteNavigationService::class)->pageForRequest($request);
    if ($navigation !== null) {
        if (!$request->isSecure()) {
            $httpsUrl = 'https://' . $request->getHost() . $request->getRequestUri();
            return redirect()->to($httpsUrl, 308);
        }

        return response()
            ->view('site_navigation', $navigation)
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'")
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Strict-Transport-Security', 'max-age=31536000');
    }

    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        if ($request->server('HTTP_HOST') !== parse_url(admin_setting('app_url'))['host']) {
            abort(403);
        }
    }

    $configuredTheme = admin_setting('frontend_theme', 'Xboard');
    $theme = $configuredTheme;
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Configured theme is temporarily unavailable; rendering the default theme without changing the selection', [
                    'theme' => $theme,
                ]);
                $theme = 'Xboard';
            }
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        // Ensure locale files exist for themes that don't ship them.
        $themeService->ensurePublicThemeLocales($theme);

        // 自动注入隐藏API路径
        $hiddenApiPath = app(HiddenApiPathService::class)->get();
        
        // 获取主题配置
        $themeConfig = $themeService->getConfig($theme);
        
        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'Xboard is best'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeConfig,
            'hidden_api_path' => $hiddenApiPath  // 备用：直接传递
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
});

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

// Alternative admin UI (xboard-admin)
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/xadmin/{any?}', function () {
    return view('admin_xboard', [
        'title' => admin_setting('app_name', 'XBoard'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
})->where('any', '.*');

$subscribeController = [ClientController::class, 'subscribe'];
$subscribePath = LegacySubscribeRoutePaths::currentPath(admin_setting('subscribe_path', 's'));
$legacySubscribePaths = LegacySubscribeRoutePaths::aliases($subscribePath, (string) admin_setting('legacy_subscribe_paths', ''));

Route::get('/' . $subscribePath . '/{token}', $subscribeController)
    ->middleware('client')
    ->name('client.subscribe');

// Keep migrated users' old subscription URLs working after their domain is proxied to this panel.
foreach ($legacySubscribePaths as $legacySubscribePath) {
    $routeSuffix = LegacySubscribeRoutePaths::routeNameSuffix($legacySubscribePath);

    Route::get('/' . $legacySubscribePath . '/{token}', $subscribeController)
        ->middleware('client')
        ->name('client.subscribe.legacy.' . $routeSuffix);

    Route::get('/' . $legacySubscribePath, $subscribeController)
        ->middleware('client')
        ->name('client.subscribe.legacy.' . $routeSuffix . '.query');
}

if (LegacySubscribeRoutePaths::shouldRegisterSiteTokenAlias($subscribePath, $legacySubscribePaths)) {
    Route::get('/sub/{site}/{token}', $subscribeController)
        ->middleware('client')
        ->name('client.subscribe.legacy.sub.site');
}

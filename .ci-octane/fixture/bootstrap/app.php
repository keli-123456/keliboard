<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\OctaneServiceProvider;

if (getenv('KELI_OCTANE_FIXTURE') !== 'isolated-ci' || file_exists('/www/.env')) throw new RuntimeException('fixture_only');
$app = Application::configure(dirname(__DIR__))
    ->withRouting(using: static function (): void { require dirname(__DIR__) . '/routes.php'; })
    ->withMiddleware()
    ->withExceptions()
    ->create();
$app->usePublicPath('/www/public');
$app->useStoragePath('/state');
$app->useConfigPath('/www/config');
$app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
    // Load the mounted files normally, without enabling any application providers or scheduler.
    $app['config']->set([
        'app.providers' => [...ServiceProvider::defaultProviders()->toArray(), OctaneServiceProvider::class],
        'app.key' => 'base64:' . base64_encode(str_repeat('x', 32)),
        'octane.tick' => false,
        'octane.max_execution_time' => 0,
        'octane.cache' => ['rows' => 64, 'bytes' => 4096],
        'database.default' => 'fixture_disabled',
        'queue.default' => 'fixture_disabled',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);
});
return $app;

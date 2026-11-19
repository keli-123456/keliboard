<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Laravel\Octane\OctaneServiceProvider;

if (getenv('KELI_OCTANE_FIXTURE') !== 'isolated-ci' || file_exists('/www/.env')) throw new RuntimeException('fixture_only');
$app = Application::configure(dirname(__DIR__))
    ->withRouting(using: static function (): void { require dirname(__DIR__) . '/routes.php'; })
    ->withMiddleware()
    ->withExceptions()
    ->withProviders([OctaneServiceProvider::class], withBootstrapProviders: false)
    ->create();
$app->usePublicPath('/www/public');
$app->useStoragePath('/state');
return $app;

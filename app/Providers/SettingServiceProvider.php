<?php

namespace App\Providers;

use App\Support\Setting;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->scoped(Setting::class, function (Application $app) {
            return new Setting();
        });

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        try {
            $connection = DB::connection('sqlite');
            $pdo = $connection->getPdo();
            $pdo->exec('PRAGMA busy_timeout = ' . (int) config('database.connections.sqlite.busy_timeout', 30000));
            $pdo->exec("PRAGMA journal_mode = '" . str_replace("'", '', (string) config('database.connections.sqlite.journal_mode', 'wal')) . "'");
            $pdo->exec("PRAGMA synchronous = " . strtoupper((string) config('database.connections.sqlite.synchronous', 'normal')));
        } catch (\Throwable $e) {
            Log::warning('Failed to apply SQLite PRAGMA settings: ' . $e->getMessage());
        }
    }
}

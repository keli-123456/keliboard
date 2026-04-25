<?php

namespace App\Services;

use App\Support\Setting as SettingRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class HiddenApiPathService
{
    public const DEFAULT_PATH = '/oxa/3vm';

    private const SETTING_KEY = 'hidden_api_path';
    private const ROUTE_CACHE_KEY = 'hidden_api_path:route';
    private const LEGACY_ROUTE_CACHE_KEY = 'hidden_api_path_route';
    private const LEGACY_WEB_CACHE_KEY = 'hidden_api_path';
    private const ROUTE_CACHE_SECONDS = 60;

    public function get(): string
    {
        return $this->getOrCreate();
    }

    public function getForRoute(): string
    {
        if (!$this->isOctaneRuntime()) {
            return $this->getOrCreate();
        }

        try {
            return Cache::remember(self::ROUTE_CACHE_KEY, self::ROUTE_CACHE_SECONDS, function (): string {
                return $this->getOrCreate();
            });
        } catch (Throwable $e) {
            Log::warning('Failed to read hidden API route cache: ' . $e->getMessage());

            return $this->getOrCreate();
        }
    }

    public function clearCache(): void
    {
        foreach ([self::ROUTE_CACHE_KEY, self::LEGACY_ROUTE_CACHE_KEY, self::LEGACY_WEB_CACHE_KEY] as $key) {
            try {
                Cache::forget($key);
            } catch (Throwable) {
            }
        }

        try {
            Cache::store('redis')->forget(SettingRepository::CACHE_KEY);
        } catch (Throwable) {
        }
    }

    private function getOrCreate(): string
    {
        try {
            return DB::transaction(function (): string {
                $paths = DB::table('v2_settings')
                    ->where('name', self::SETTING_KEY)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                if ($paths->isEmpty()) {
                    $path = $this->generatePath();
                    DB::table('v2_settings')->insert([
                        'name' => self::SETTING_KEY,
                        'value' => $path,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->clearCache();

                    Log::info('Generated hidden API path', ['path' => $path]);

                    return $path;
                }

                $keep = $paths->first();
                $path = $this->normalizePath($keep->value) ?? $this->generatePath();

                if ($path !== $keep->value) {
                    DB::table('v2_settings')
                        ->where('id', $keep->id)
                        ->update([
                            'value' => $path,
                            'updated_at' => now(),
                        ]);
                }

                if ($paths->count() > 1) {
                    DB::table('v2_settings')
                        ->where('name', self::SETTING_KEY)
                        ->where('id', '!=', $keep->id)
                        ->delete();

                    Log::info('Cleaned duplicate hidden API paths', [
                        'kept' => $path,
                        'deleted_count' => $paths->count() - 1,
                    ]);
                }

                return $path;
            }, 3);
        } catch (Throwable $e) {
            Log::warning('Failed to resolve hidden API path: ' . $e->getMessage());

            return self::DEFAULT_PATH;
        }
    }

    private function normalizePath(mixed $path): ?string
    {
        if (!is_string($path) && !is_numeric($path)) {
            return null;
        }

        $path = '/' . trim((string) $path, " \t\n\r\0\x0B/");
        if ($path === '/') {
            return null;
        }

        return preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#', $path) ? $path : null;
    }

    private function generatePath(): string
    {
        return '/' . strtolower(Str::random(3)) . '/' . strtolower(Str::random(3));
    }

    private function isOctaneRuntime(): bool
    {
        return (bool) ($_SERVER['LARAVEL_OCTANE'] ?? $_ENV['LARAVEL_OCTANE'] ?? false);
    }
}

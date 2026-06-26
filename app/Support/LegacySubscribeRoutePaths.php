<?php

declare(strict_types=1);

namespace App\Support;

final class LegacySubscribeRoutePaths
{
    private const DEFAULT_ALIASES = [
        'sub',
        'subscribe',
    ];

    public static function currentPath(mixed $path): string
    {
        return self::normalizePath((string) ($path ?? '')) ?? 's';
    }

    /**
     * @return list<string>
     */
    public static function aliases(string $currentPath, ?string $configuredAliases = null): array
    {
        $currentPath = self::currentPath($currentPath);
        $paths = self::DEFAULT_ALIASES;

        if ($configuredAliases !== null && trim($configuredAliases) !== '') {
            $paths = array_merge($paths, preg_split('/[\s,;|]+/', $configuredAliases) ?: []);
        }

        $aliases = [];
        foreach ($paths as $path) {
            $normalized = self::normalizePath((string) $path);
            if ($normalized === null || $normalized === $currentPath || in_array($normalized, $aliases, true)) {
                continue;
            }

            $aliases[] = $normalized;
        }

        return $aliases;
    }

    public static function shouldRegisterSiteTokenAlias(string $currentPath, array $aliases): bool
    {
        return self::currentPath($currentPath) === 'sub' || in_array('sub', $aliases, true);
    }

    public static function routeNameSuffix(string $path): string
    {
        $suffix = preg_replace('/[^A-Za-z0-9_]+/', '_', str_replace(['.', '-'], '_', $path));

        return trim((string) $suffix, '_') ?: 'path';
    }

    private static function normalizePath(string $path): ?string
    {
        $path = trim($path);
        $path = trim($path, '/');

        if ($path === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $path)) {
            return null;
        }

        return $path;
    }
}

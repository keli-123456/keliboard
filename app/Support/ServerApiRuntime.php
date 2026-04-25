<?php

declare(strict_types=1);

namespace App\Support;

class ServerApiRuntime
{
    public static function applyMemoryLimit(): void
    {
        $limit = trim((string) config('server_api_cache.memory_limit', '1024M'));

        if ($limit === '' || strtolower($limit) === 'default') {
            return;
        }

        @ini_set('memory_limit', $limit);
    }
}

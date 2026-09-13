<?php

namespace App\Services;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

final class CacheQueueIsolation
{
    public function inspect(?string $store = null): array
    {
        $store ??= (string) config('cache.default');
        if (config("cache.stores.$store.driver") !== 'redis') {
            return ['safe' => true, 'reason' => 'non_redis'];
        }
        $connection = (string) (config("cache.stores.$store.connection") ?: 'default');
        $protected = [(string) config('horizon.use', 'default')];
        foreach ((array) config('queue.connections', []) as $queue) {
            if (($queue['driver'] ?? null) === 'redis') {
                $protected[] = (string) ($queue['connection'] ?? 'default');
            }
        }
        if (config('session.driver') === 'redis') {
            $protected[] = (string) (config('session.connection') ?: 'default');
        }
        try {
            $cache = $this->connectionConfig($connection);
            foreach (array_unique($protected) as $name) {
                $other = $this->connectionConfig($name);
                if ((int) ($cache['database'] ?? 0) !== (int) ($other['database'] ?? 0)) {
                    continue;
                }
                // Prefixes do not isolate FLUSHDB. Check aliases by actual server identity.
                if ($connection === $name || $this->serverId($connection) === $this->serverId($name)) {
                    return ['safe' => false, 'reason' => 'shared_queue_database'];
                }
            }
        } catch (Throwable) {
            return ['safe' => false, 'reason' => 'unverified_redis_database'];
        }
        return ['safe' => true, 'reason' => 'isolated'];
    }

    public function assertSafeToClear(?string $store = null): void
    {
        $result = $this->inspect($store);
        if (!$result['safe']) {
            throw new RuntimeException('Cache clear refused: ' . $result['reason']
                . '. Keep queue data intact; migrate the cache to an isolated Redis database first.');
        }
    }

    private function connectionConfig(string $name): array
    {
        $config = config('database.redis.' . $name);
        if (!is_array($config) || array_is_list($config)) {
            throw new RuntimeException('Redis connection cannot be verified.');
        }
        return (new ConfigurationUrlParser())->parseConfiguration($config);
    }

    private function serverId(string $name): string
    {
        $info = app('redis')->connection($name)->info('server');
        $id = $info['run_id'] ?? $info['Server']['run_id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('Redis server identity is unavailable.');
        }
        return $id;
    }
}

<?php


namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class UserOnlineService
{
    /**
     * 缓存相关常量
     */
    private const CACHE_PREFIX = 'ALIVE_IP_USER_';
    private const CACHE_TTL_SECONDS = 120;
    private const REALTIME_ACTIVE_USERS_KEY = 'ALIVE_IP_ACTIVE_USERS';
    private const REALTIME_ACTIVE_COUNTS_KEY = 'ALIVE_IP_ACTIVE_COUNTS';
    private const REALTIME_SUMMARY_CACHE_KEY = 'ALIVE_IP_SUMMARY';
    private const REALTIME_READY_KEY = 'ALIVE_IP_READY';
    private const REALTIME_SUMMARY_CACHE_TTL_SECONDS = 15;

    public static function nodeDataExpirySeconds(): int
    {
        $pushInterval = max(1, (int) admin_setting('server_push_interval', 60));

        // Keep the default 60s -> 100s behavior while adapting to longer push intervals.
        return max(100, $pushInterval + 40);
    }

    public static function cacheKey(int $userId): string
    {
        return self::CACHE_PREFIX . $userId;
    }

    public static function aliveCacheTtlSeconds(): int
    {
        return self::CACHE_TTL_SECONDS;
    }

    public static function supportsRealtimeIndex(): bool
    {
        $defaultStore = (string) config('cache.default');
        return data_get(config('cache.stores'), $defaultStore . '.driver') === 'redis';
    }

    public static function isRealtimeIndexReady(): bool
    {
        if (!self::supportsRealtimeIndex()) {
            return false;
        }

        return (bool) cache()->get(self::realtimeReadyKey(), false);
    }

    public static function markRealtimeIndexReady(): void
    {
        if (!self::supportsRealtimeIndex()) {
            return;
        }

        cache()->forever(self::realtimeReadyKey(), time());
    }

    /**
     * 获取所有限制设备用户的在线数量
     */
    public function getAliveList(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $cachedCounts = $this->getFreshAliveCountsForUserIds($userIds);
        if (!self::isRealtimeIndexReady()) {
            return $cachedCounts;
        }

        return self::mergeOnlineCounts(
            $userIds,
            $cachedCounts,
            self::getActiveRealtimeCountsForUserIds($userIds),
        );
    }

    public function getAliveSnapshot(array $userIds): array
    {
        $mode = self::getDeviceLimitMode();
        if (empty($userIds)) {
            return [
                'alive' => [],
                'alive_ips' => [],
                'mode' => $mode,
            ];
        }

        $alive = [];
        $aliveIps = [];

        foreach ($this->getFreshAliveSummariesForUserIds($userIds, $mode) as $userId => $summary) {
            $count = (int) ($summary['alive_ip'] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $alive[$userId] = $count;
            if ($mode === 1 && !empty($summary['ips'])) {
                $aliveIps[$userId] = $summary['ips'];
                $alive[$userId] = count($summary['ips']);
            }
        }

        return [
            'alive' => $alive,
            'alive_ips' => $aliveIps,
            'mode' => $mode,
        ];
    }

    /**
     * 获取指定用户的在线设备信息
     */
    public static function getUserDevices(int $userId): array
    {
        $summary = self::summarizeAliveCache(cache()->get(self::cacheKey($userId), []));
        if ($summary['alive_ip'] <= 0) {
            return ['total_count' => 0, 'devices' => []];
        }

        $devices = collect($summary['nodes'])
            ->filter(fn(mixed $item): bool => is_array($item) && isset($item['aliveips']))
            ->flatMap(function (array $nodeData, string $nodeKey): array {
                return collect($nodeData['aliveips'])
                    ->mapWithKeys(function (string $ipNodeId) use ($nodeData, $nodeKey): array {
                        $ip = Str::before($ipNodeId, '_');
                        return [
                            $ip => [
                                'ip' => $ip,
                                'last_seen' => $nodeData['lastupdateAt'],
                                'node_type' => Str::before($nodeKey, (string) $nodeData['lastupdateAt'])
                            ]
                        ];
                    })
                    ->all();
            })
            ->values()
            ->all();

        return [
            'total_count' => $summary['alive_ip'],
            'devices' => $devices
        ];
    }

    /**
     * 获取指定用户的在线 IP 列表（按设备数限制模式去重）
     */
    public static function getUserDeviceIps(int $userId): array
    {
        $mode = (int) admin_setting('device_limit_mode', 0);
        $summary = self::summarizeAliveCache(cache()->get(self::cacheKey($userId), []), $mode);
        if ($summary['alive_ip'] <= 0) {
            return ['mode' => $mode, 'total_count' => 0, 'ips' => []];
        }

        $ips = collect($summary['nodes'])
            ->filter(fn(mixed $item): bool => is_array($item) && isset($item['aliveips']))
            ->flatMap(fn(array $nodeData): array => collect($nodeData['aliveips'])
                ->map(fn(string $ipNodeId): string => Str::before($ipNodeId, '_'))
                ->all())
            ->when($mode === 1, fn(Collection $collection): Collection => $collection->unique())
            ->values()
            ->all();

        return [
            'mode' => $mode,
            'total_count' => $summary['alive_ip'],
            'ips' => $ips
        ];
    }


    /**
     * 批量获取用户在线设备数
     */
    public function getOnlineCounts(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $cachedCounts = $this->getFreshAliveCountsForUserIds($userIds);
        if (!self::isRealtimeIndexReady()) {
            return self::mergeOnlineCounts($userIds, $cachedCounts);
        }

        return self::mergeOnlineCounts(
            $userIds,
            $cachedCounts,
            self::getActiveRealtimeCountsForUserIds($userIds),
        );
    }

    /**
     * 获取用户在线设备数
     */
    public function getOnlineCount(int $userId): int
    {
        $cachedCount = self::summarizeAliveCache(cache()->get(self::cacheKey($userId), []))['alive_ip'];
        if (!self::isRealtimeIndexReady()) {
            return $cachedCount;
        }

        $realtimeCount = self::getRealtimeCount($userId);
        return max($cachedCount, $realtimeCount ?? 0);
    }

    public static function updateRealtimeIndex(array $userCounts, int $expiresAt): void
    {
        if (!self::supportsRealtimeIndex() || empty($userCounts)) {
            return;
        }

        $zaddArgs = [self::realtimeActiveUsersKey()];
        $hsetArgs = [self::realtimeActiveCountsKey()];
        $removeIds = [];

        foreach ($userCounts as $userId => $count) {
            $normalizedUserId = (int) $userId;
            if ($normalizedUserId <= 0) {
                continue;
            }

            $normalizedCount = max(0, (int) $count);
            if ($normalizedCount > 0) {
                $zaddArgs[] = (string) $expiresAt;
                $zaddArgs[] = (string) $normalizedUserId;
                $hsetArgs[] = (string) $normalizedUserId;
                $hsetArgs[] = (string) $normalizedCount;
                continue;
            }

            $removeIds[] = (string) $normalizedUserId;
        }

        if (count($zaddArgs) > 1) {
            self::redisCommand('zadd', $zaddArgs);
        }

        if (count($hsetArgs) > 1) {
            self::redisCommand('hmset', $hsetArgs);
        }

        if (!empty($removeIds)) {
            self::redisCommand('zrem', array_merge([self::realtimeActiveUsersKey()], $removeIds));
            self::redisCommand('hdel', array_merge([self::realtimeActiveCountsKey()], $removeIds));
        }

        self::markRealtimeIndexReady();
        cache()->forget(self::realtimeSummaryCacheKey());
    }

    public static function getRealtimeSummary(): ?array
    {
        if (!self::isRealtimeIndexReady()) {
            return null;
        }

        $cached = cache()->get(self::realtimeSummaryCacheKey());
        if (is_array($cached) && isset($cached['online_devices'], $cached['online_users'])) {
            return [
                'online_devices' => max(0, (int) $cached['online_devices']),
                'online_users' => max(0, (int) $cached['online_users']),
            ];
        }

        $activeUserIds = self::getActiveRealtimeUserIds(time() + 1);
        $summary = [
            'online_devices' => 0,
            'online_users' => count($activeUserIds),
        ];

        if (!empty($activeUserIds)) {
            $summary['online_devices'] = array_sum(self::getRealtimeCountsForUserIds($activeUserIds));
        }

        cache()->put(
            self::realtimeSummaryCacheKey(),
            $summary,
            now()->addSeconds(self::REALTIME_SUMMARY_CACHE_TTL_SECONDS)
        );

        return $summary;
    }

    public static function getExpiredRealtimeUserIds(int $now): array
    {
        if (!self::isRealtimeIndexReady()) {
            return [];
        }

        return self::normalizeRedisUserIds(
            self::redisCommand('zrangebyscore', [
                self::realtimeActiveUsersKey(),
                '-inf',
                (string) $now,
            ])
        );
    }

    public static function purgeRealtimeUsers(array $userIds): void
    {
        if (!self::supportsRealtimeIndex()) {
            return;
        }

        $normalizedIds = array_map('strval', self::normalizeRedisUserIds($userIds));
        if (empty($normalizedIds)) {
            return;
        }

        self::redisCommand('zrem', array_merge([self::realtimeActiveUsersKey()], $normalizedIds));
        self::redisCommand('hdel', array_merge([self::realtimeActiveCountsKey()], $normalizedIds));
        cache()->forget(self::realtimeSummaryCacheKey());
    }

    public static function forgetAliveCaches(array $userIds): void
    {
        foreach ($userIds as $userId) {
            $normalizedUserId = (int) $userId;
            if ($normalizedUserId <= 0) {
                continue;
            }

            cache()->forget(self::cacheKey($normalizedUserId));
        }
    }

    /**
     * 计算在线设备数量
     */
    public static function calculateDeviceCount(array $ipsArray, ?int $mode = null): int
    {
        $mode ??= self::getDeviceLimitMode();

        return match ($mode) {
            1 => self::countUniqueAliveIps($ipsArray),
            0 => self::countAliveIps($ipsArray),
            default => throw new \InvalidArgumentException("Invalid device limit mode: $mode"),
        };
    }

    public static function getDeviceLimitMode(): int
    {
        return (int) admin_setting('device_limit_mode', 0);
    }

    private static function countUniqueAliveIps(array $ipsArray): int
    {
        $uniqueIps = [];

        foreach ($ipsArray as $data) {
            if (!is_array($data) || !isset($data['aliveips']) || !is_array($data['aliveips'])) {
                continue;
            }

            foreach ($data['aliveips'] as $ipNodeId) {
                $uniqueIps[Str::before((string) $ipNodeId, '_')] = true;
            }
        }

        return count($uniqueIps);
    }

    private function getAliveIpsForUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $keyToUserId = [];
        foreach ($userIds as $userId) {
            $normalizedUserId = (int) $userId;
            if ($normalizedUserId <= 0) {
                continue;
            }
            $cacheKey = self::cacheKey($normalizedUserId);
            $keyToUserId[$cacheKey] = $normalizedUserId;
        }

        $aliveIps = [];
        foreach (array_chunk($keyToUserId, 1000, true) as $keyBatch) {
            foreach (cache()->many(array_keys($keyBatch)) as $cacheKey => $data) {
                $ips = self::summarizeAliveCache($data)['ips'];
                if (empty($ips)) {
                    continue;
                }

                $aliveIps[$keyBatch[$cacheKey]] = $ips;
            }
        }

        return $aliveIps;
    }

    private function getFreshAliveCountsForUserIds(array $userIds): array
    {
        $counts = [];
        foreach ($this->getFreshAliveSummariesForUserIds($userIds) as $userId => $summary) {
            $counts[$userId] = (int) ($summary['alive_ip'] ?? 0);
        }

        return $counts;
    }

    private function getFreshAliveSummariesForUserIds(array $userIds, ?int $mode = null): array
    {
        if (empty($userIds)) {
            return [];
        }

        $keyToUserId = [];
        foreach ($userIds as $userId) {
            $normalizedUserId = (int) $userId;
            if ($normalizedUserId <= 0) {
                continue;
            }
            $keyToUserId[self::cacheKey($normalizedUserId)] = $normalizedUserId;
        }

        $summaries = [];
        foreach (array_chunk($keyToUserId, 1000, true) as $keyBatch) {
            foreach (cache()->many(array_keys($keyBatch)) as $cacheKey => $data) {
                $summary = self::summarizeAliveCache($data, $mode);
                if (($summary['alive_ip'] ?? 0) <= 0 && empty($summary['ips'])) {
                    continue;
                }
                $summaries[$keyBatch[$cacheKey]] = $summary;
            }
        }

        return $summaries;
    }

    private static function extractAliveIps(array $ipsArray): array
    {
        $uniqueIps = [];

        foreach ($ipsArray as $data) {
            if (!is_array($data) || !isset($data['aliveips']) || !is_array($data['aliveips'])) {
                continue;
            }

            foreach ($data['aliveips'] as $ipNodeId) {
                $ip = trim(Str::before((string) $ipNodeId, '_'));
                if ($ip === '') {
                    continue;
                }
                $uniqueIps[$ip] = true;
            }
        }

        $ips = array_keys($uniqueIps);
        sort($ips, SORT_STRING);

        return $ips;
    }

    private static function countAliveIps(array $ipsArray): int
    {
        $count = 0;

        foreach ($ipsArray as $data) {
            if (!is_array($data) || !isset($data['aliveips']) || !is_array($data['aliveips'])) {
                continue;
            }

            $count += count($data['aliveips']);
        }

        return $count;
    }

    private static function summarizeAliveCache(mixed $cachedData, ?int $mode = null, ?int $now = null): array
    {
        $freshNodes = self::filterFreshAliveCacheNodes($cachedData, $now);
        $mode ??= self::getDeviceLimitMode();

        return [
            'nodes' => $freshNodes,
            'alive_ip' => self::calculateDeviceCount($freshNodes, $mode),
            'ips' => self::extractAliveIps($freshNodes),
        ];
    }

    private static function filterFreshAliveCacheNodes(mixed $cachedData, ?int $now = null): array
    {
        if (!is_array($cachedData)) {
            return [];
        }

        $now ??= time();
        $expirySeconds = self::nodeDataExpirySeconds();
        $filtered = [];

        foreach ($cachedData as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $lastUpdateAt = (int) ($value['lastupdateAt'] ?? 0);
            if ($lastUpdateAt <= 0 || ($now - $lastUpdateAt) > $expirySeconds) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    private static function realtimeReadyKey(): string
    {
        return self::REALTIME_READY_KEY;
    }

    private static function realtimeSummaryCacheKey(): string
    {
        return self::REALTIME_SUMMARY_CACHE_KEY;
    }

    private static function realtimeActiveUsersKey(): string
    {
        return self::REALTIME_ACTIVE_USERS_KEY;
    }

    private static function realtimeActiveCountsKey(): string
    {
        return self::REALTIME_ACTIVE_COUNTS_KEY;
    }

    private static function redisConnectionName(): string
    {
        $defaultStore = (string) config('cache.default');
        return (string) data_get(config('cache.stores'), $defaultStore . '.connection', 'default');
    }

    private static function redisCommand(string $method, array $parameters): mixed
    {
        $normalizedMethod = strtolower($method);
        if ($normalizedMethod === 'hmset') {
            $parameters = self::normalizeHmsetParameters($parameters);
        } elseif ($normalizedMethod === 'hmget') {
            $parameters = self::normalizeHmgetParameters($parameters);
        }

        return Redis::connection(self::redisConnectionName())->command($normalizedMethod, $parameters);
    }

    private static function normalizeHmsetParameters(array $parameters): array
    {
        if (count($parameters) === 2 && is_array($parameters[1])) {
            return $parameters;
        }

        $key = array_shift($parameters);
        if ($key === null) {
            return $parameters;
        }

        $hash = [];
        $parameterCount = count($parameters);
        for ($index = 0; $index < $parameterCount; $index += 2) {
            if (!array_key_exists($index + 1, $parameters)) {
                break;
            }

            $hash[(string) $parameters[$index]] = (string) $parameters[$index + 1];
        }

        return [$key, $hash];
    }

    private static function normalizeHmgetParameters(array $parameters): array
    {
        if (count($parameters) === 2 && is_array($parameters[1])) {
            return $parameters;
        }

        $key = array_shift($parameters);
        if ($key === null) {
            return $parameters;
        }

        return [$key, array_map('strval', $parameters)];
    }

    private static function normalizeRedisUserIds(mixed $userIds): array
    {
        if (!is_array($userIds)) {
            return [];
        }

        $normalized = [];
        foreach ($userIds as $userId) {
            $value = (int) $userId;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    private static function getActiveRealtimeUserIds(int $minExpiresAt): array
    {
        return self::normalizeRedisUserIds(
            self::redisCommand('zrangebyscore', [
                self::realtimeActiveUsersKey(),
                (string) $minExpiresAt,
                '+inf',
            ])
        );
    }

    private static function getRealtimeCountsForUserIds(array $userIds): array
    {
        $normalizedIds = self::normalizeRedisUserIds($userIds);
        if (empty($normalizedIds)) {
            return [];
        }

        $counts = [];
        foreach (array_chunk($normalizedIds, 1000) as $chunk) {
            $rawCounts = self::redisCommand(
                'hmget',
                array_merge([self::realtimeActiveCountsKey()], array_map('strval', $chunk))
            );

            if (!is_array($rawCounts)) {
                continue;
            }

            foreach ($chunk as $index => $userId) {
                $value = $rawCounts[$index] ?? null;
                if ($value === null || $value === false) {
                    continue;
                }

                $counts[$userId] = max(0, (int) $value);
            }
        }

        return $counts;
    }

    private static function getActiveRealtimeCountsForUserIds(array $userIds): array
    {
        $activeUserIds = self::filterActiveRealtimeUserIds($userIds, time() + 1);
        if (empty($activeUserIds)) {
            return [];
        }

        return self::getRealtimeCountsForUserIds($activeUserIds);
    }

    private static function filterActiveRealtimeUserIds(array $userIds, int $minExpiresAt): array
    {
        $normalizedIds = self::normalizeRedisUserIds($userIds);
        if (empty($normalizedIds)) {
            return [];
        }

        $activeLookup = array_fill_keys(self::getActiveRealtimeUserIds($minExpiresAt), true);
        if (empty($activeLookup)) {
            return [];
        }

        return array_values(array_filter(
            $normalizedIds,
            fn(int $userId): bool => isset($activeLookup[$userId])
        ));
    }

    private static function getRealtimeCount(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $expiresAt = self::redisCommand('zscore', [
            self::realtimeActiveUsersKey(),
            (string) $userId,
        ]);
        if (!is_numeric($expiresAt) || (float) $expiresAt < (time() + 1)) {
            return null;
        }

        $value = self::redisCommand('hget', [
            self::realtimeActiveCountsKey(),
            (string) $userId,
        ]);

        if ($value === null || $value === false) {
            return null;
        }

        return max(0, (int) $value);
    }

    private static function mergeOnlineCounts(array $userIds, array ...$sources): array
    {
        $counts = [];
        foreach ($userIds as $userId) {
            $normalizedUserId = (int) $userId;
            if ($normalizedUserId <= 0) {
                continue;
            }
            $counts[$normalizedUserId] = 0;
        }

        foreach ($sources as $source) {
            foreach ($source as $userId => $count) {
                $normalizedUserId = (int) $userId;
                if ($normalizedUserId <= 0) {
                    continue;
                }
                $counts[$normalizedUserId] = max(
                    $counts[$normalizedUserId] ?? 0,
                    max(0, (int) $count)
                );
            }
        }

        return $counts;
    }
}

<?php


namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class UserOnlineService
{
    /**
     * 缓存相关常量
     */
    private const CACHE_PREFIX = 'ALIVE_IP_USER_';

    /**
     * 获取所有限制设备用户的在线数量
     */
    public function getAliveList(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $keyToUserId = [];
        foreach ($userIds as $userId) {
            $cacheKey = self::CACHE_PREFIX . (int) $userId;
            $keyToUserId[$cacheKey] = (int) $userId;
        }

        $alive = [];
        foreach (array_chunk($keyToUserId, 1000, true) as $keyBatch) {
            foreach (cache()->many(array_keys($keyBatch)) as $cacheKey => $data) {
                if (!is_array($data) || !isset($data['alive_ip'])) {
                    continue;
                }
                $alive[$keyBatch[$cacheKey]] = (int) $data['alive_ip'];
            }
        }

        return $alive;
    }

    /**
     * 获取指定用户的在线设备信息
     */
    public static function getUserDevices(int $userId): array
    {
        $data = cache()->get(self::CACHE_PREFIX . $userId, []);
        if (empty($data)) {
            return ['total_count' => 0, 'devices' => []];
        }

        $devices = collect($data)
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
            'total_count' => $data['alive_ip'] ?? 0,
            'devices' => $devices
        ];
    }

    /**
     * 获取指定用户的在线 IP 列表（按设备数限制模式去重）
     */
    public static function getUserDeviceIps(int $userId): array
    {
        $mode = (int) admin_setting('device_limit_mode', 0);
        $data = cache()->get(self::CACHE_PREFIX . $userId, []);
        if (empty($data)) {
            return ['mode' => $mode, 'total_count' => 0, 'ips' => []];
        }

        $ips = collect($data)
            ->filter(fn(mixed $item): bool => is_array($item) && isset($item['aliveips']))
            ->flatMap(fn(array $nodeData): array => collect($nodeData['aliveips'])
                ->map(fn(string $ipNodeId): string => Str::before($ipNodeId, '_'))
                ->all())
            ->when($mode === 1, fn(Collection $collection): Collection => $collection->unique())
            ->values()
            ->all();

        return [
            'mode' => $mode,
            'total_count' => $data['alive_ip'] ?? count($ips),
            'ips' => $ips
        ];
    }


    /**
     * 批量获取用户在线设备数
     */
    public function getOnlineCounts(array $userIds): array
    {
        $cacheKeys = collect($userIds)
            ->map(fn(int $id): string => self::CACHE_PREFIX . $id)
            ->all();

        return collect(cache()->many($cacheKeys))
            ->filter()
            ->map(fn(array $data): int => $data['alive_ip'] ?? 0)
            ->all();
    }

    /**
     * 获取用户在线设备数
     */
    public function getOnlineCount(int $userId): int
    {
        $data = cache()->get(self::CACHE_PREFIX . $userId, []);
        return $data['alive_ip'] ?? 0;
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
}

<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerRoute;
use App\Models\User;
use App\Models\UserSyncState;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ServerService
{
    private const AVAILABLE_USER_IDS_CACHE_PREFIX = 'server:available-user-ids:';
    private const AVAILABLE_USER_IDS_CACHE_TTL = 75;
    private const DEFAULT_AVAILABLE_USER_CHUNK_SIZE = 5000;
    private static ?bool $hasUserSyncStatesTable = null;
    private static bool $userSyncStatesReadDisabled = false;

    /**
     * 获取所有服务器列表
     * @return Collection
     */
    public static function getAllServers(): Collection
    {
        $query = Server::orderBy('sort', 'ASC');

        return $query->get()->append([
            'last_check_at',
            'last_push_at',
            'online',
            'is_online',
            'available_status',
            'cache_key',
            'load_status'
        ]);
    }

    /**
     * 获取指定用户可用的服务器列表
     * @param User $user
     * @return array
     */
    public static function getAvailableServers(User $user): array
    {
        $servers = Server::whereJsonContains('group_ids', (string) $user->group_id)
            ->where('show', true)
            ->orderBy('sort', 'ASC')
            ->get()
            ->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'cache_key', 'server_key']);

        $servers = collect($servers)->map(function ($server) use ($user) {
            // 判断动态端口
            if (str_contains($server->port, '-')) {
                $port = $server->port;
                $server->port = (int) Helper::randomPort($port);
                $server->ports = $port;
            } else {
                $server->port = (int) $server->port;
            }
            $server->password = $server->generateServerPassword($user);
            $server->rate = $server->getCurrentRate();
            return $server;
        })->toArray();

        return $servers;
    }

    /**
     * 获取节点可用用户列表
     */
    public static function getAvailableUsers(Server $node, bool $onlyDeviceLimited = false): Collection
    {
        $groupIds = self::normalizeGroupIds($node->group_ids ?? []);
        if (empty($groupIds)) {
            return collect();
        }

        $query = self::availableUsersPreferredQuery($groupIds);

        if ($onlyDeviceLimited) {
            if ($query) {
                $query->where('device_limit', '>', 0);
            }
        }

        if ($query) {
            $query->selectRaw('user_id as id, uuid, speed_limit, device_limit');
            try {
                $users = $query->get();
                $users = HookManager::filter('server.users.get', $users, $node);
                return collect($users)->sortBy('id')->values();
            } catch (\Throwable) {
                self::disableUserSyncStatesRead();
            }
        }

        $fallbackQuery = self::availableUsersBaseQuery($groupIds)->select([
            'id',
            'uuid',
            'speed_limit',
            'device_limit'
        ]);
        if ($onlyDeviceLimited) {
            $fallbackQuery->where('device_limit', '>', 0);
        }

        $users = $fallbackQuery->get();
        $users = HookManager::filter('server.users.get', $users, $node);
        return collect($users)->sortBy('id')->values();
    }

    /**
     * Iterate available node users in bounded chunks.
     *
     * If a plugin filters server users, keep the legacy full collection path so
     * plugins that expect the complete list continue to work as before.
     */
    public static function eachAvailableUser(
        Server $node,
        callable $callback,
        bool $onlyDeviceLimited = false,
        ?int $chunkSize = null
    ): void {
        if (HookManager::hasHook('server.users.get')) {
            foreach (self::getAvailableUsers($node, $onlyDeviceLimited) as $user) {
                $callback($user);
            }
            return;
        }

        $groupIds = self::normalizeGroupIds($node->group_ids ?? []);
        if (empty($groupIds)) {
            return;
        }

        $chunkSize = self::normalizeAvailableUserChunkSize($chunkSize);
        $query = self::availableUsersPreferredQuery($groupIds);

        if ($query) {
            $query->selectRaw('user_id as id, uuid, speed_limit, device_limit');
            if ($onlyDeviceLimited) {
                $query->where('device_limit', '>', 0);
            }

            try {
                self::eachAvailableUserById($query, 'user_id', $chunkSize, $callback);
                return;
            } catch (\Throwable) {
                self::disableUserSyncStatesRead();
            }
        }

        $fallbackQuery = self::availableUsersBaseQuery($groupIds)->select([
            'id',
            'uuid',
            'speed_limit',
            'device_limit'
        ]);
        if ($onlyDeviceLimited) {
            $fallbackQuery->where('device_limit', '>', 0);
        }

        self::eachAvailableUserById($fallbackQuery, 'id', $chunkSize, $callback);
    }

    /**
     * 获取节点可用用户 ID 列表
     */
    public static function getAvailableUserIds(Server $node, bool $onlyDeviceLimited = false): array
    {
        $groupIds = self::normalizeGroupIds($node->group_ids ?? []);
        if (empty($groupIds)) {
            return [];
        }

        if (!$onlyDeviceLimited) {
            return self::queryAvailableUserIds($groupIds, false);
        }

        return Cache::remember(
            self::availableUserIdsCacheKey($groupIds, true),
            now()->addSeconds(self::AVAILABLE_USER_IDS_CACHE_TTL),
            fn(): array => self::queryAvailableUserIds($groupIds, true)
        );
    }

    private static function queryAvailableUserIds(array $groupIds, bool $onlyDeviceLimited): array
    {
        $query = self::availableUsersPreferredQuery($groupIds);
        if ($query) {
            $query->selectRaw('user_id as id');
            if ($onlyDeviceLimited) {
                $query->where('device_limit', '>', 0);
            }

            try {
                return $query->pluck('id')
                    ->map(fn($id): int => (int) $id)
                    ->all();
            } catch (\Throwable) {
                self::disableUserSyncStatesRead();
            }
        }

        $query = self::availableUsersBaseQuery($groupIds)->select(['id']);
        if ($onlyDeviceLimited) {
            $query->where('device_limit', '>', 0);
        }

        return $query->pluck('id')
            ->map(fn($id): int => (int) $id)
            ->all();
    }

    private static function eachAvailableUserById(
        QueryBuilder $query,
        string $idColumn,
        int $chunkSize,
        callable $callback
    ): void {
        $lastId = 0;

        do {
            $chunk = (clone $query)
                ->where($idColumn, '>', $lastId)
                ->limit($chunkSize)
                ->get();

            $count = $chunk->count();
            foreach ($chunk as $user) {
                $rowId = (int) data_get($user, 'id', data_get($user, $idColumn, 0));
                if ($rowId <= $lastId) {
                    continue;
                }

                $lastId = $rowId;
                $callback($user);
            }

            unset($chunk);
        } while ($count === $chunkSize && $lastId > 0);
    }

    private static function normalizeAvailableUserChunkSize(?int $chunkSize): int
    {
        if ($chunkSize === null) {
            $chunkSize = (int) config('server_api_cache.user_chunk_size', self::DEFAULT_AVAILABLE_USER_CHUNK_SIZE);
        }

        return max(500, min($chunkSize, 20000));
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        $routeIds = array_values(array_unique(array_map('intval', $routeIds)));
        if (empty($routeIds)) {
            return collect();
        }

        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])
            ->whereIn('id', $routeIds)
            ->get();

        return self::orderByIdSequence($routes, $routeIds);
    }

    public static function orderByIdSequence(Collection $records, array $ids): Collection
    {
        $positions = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $index => $id) {
            $positions[$id] = $index;
        }

        return $records
            ->sortBy(fn ($record) => $positions[(int) data_get($record, 'id')] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * 根据协议类型和标识获取服务器
     * @param int $serverId
     * @param string $serverType
     * @return Server|null
     */
    public static function getServer($serverId, ?string $serverType)
    {
        return Server::query()
            ->when($serverType, function ($query) use ($serverType) {
                $query->where('type', Server::normalizeType($serverType));
            })
            ->where(function ($query) use ($serverId) {
                $query->where('code', $serverId)
                    ->orWhere('id', $serverId);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$serverId])
            ->first();
    }

    private static function availableUsersBaseQuery(array $groupIds): QueryBuilder
    {
        return User::toBase()
            ->whereIn('group_id', $groupIds)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->orderBy('id', 'asc');
    }

    private static function availableUsersPreferredQuery(array $groupIds): ?QueryBuilder
    {
        if (self::$userSyncStatesReadDisabled) {
            return null;
        }

        if (!self::shouldUseUserSyncStatesForServerUsers()) {
            return null;
        }

        if (!self::hasUserSyncStatesTable()) {
            return null;
        }

        try {
            return UserSyncState::query()
                ->toBase()
                ->whereIn('group_id', $groupIds)
                ->where('available', 1)
                ->orderBy('user_id', 'asc');
        } catch (\Throwable $e) {
            self::disableUserSyncStatesRead();
            return null;
        }
    }

    private static function shouldUseUserSyncStatesForServerUsers(): bool
    {
        return (bool) config('user_sync.use_state_table_for_server_users', true);
    }

    private static function hasUserSyncStatesTable(): bool
    {
        if (self::$hasUserSyncStatesTable !== null) {
            return self::$hasUserSyncStatesTable;
        }

        try {
            self::$hasUserSyncStatesTable = Schema::hasTable('user_sync_states');
        } catch (\Throwable) {
            self::$hasUserSyncStatesTable = false;
        }

        return self::$hasUserSyncStatesTable;
    }

    private static function disableUserSyncStatesRead(): void
    {
        if (self::$userSyncStatesReadDisabled) {
            return;
        }

        self::$userSyncStatesReadDisabled = true;
    }

    private static function availableUserIdsCacheKey(array $groupIds, bool $onlyDeviceLimited): string
    {
        return self::AVAILABLE_USER_IDS_CACHE_PREFIX
            . ($onlyDeviceLimited ? 'device:' : 'all:')
            . md5(implode(',', $groupIds));
    }

    private static function normalizeGroupIds(array $groupIds): array
    {
        $normalized = array_values(array_unique(array_map('intval', $groupIds)));
        sort($normalized);

        return $normalized;
    }
}

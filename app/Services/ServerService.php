<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerRoute;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class ServerService
{

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
        $query = self::availableUsersBaseQuery($node)
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ]);

        if ($onlyDeviceLimited) {
            $query->where('device_limit', '>', 0);
        }

        $users = $query->get();
        $users = HookManager::filter('server.users.get', $users, $node);
        return collect($users)->sortBy('id')->values();
    }

    /**
     * 获取节点可用用户 ID 列表
     */
    public static function getAvailableUserIds(Server $node, bool $onlyDeviceLimited = false): array
    {
        $query = self::availableUsersBaseQuery($node)->select(['id']);

        if ($onlyDeviceLimited) {
            $query->where('device_limit', '>', 0);
        }

        return $query->pluck('id')
            ->map(fn($id): int => (int) $id)
            ->all();
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

    private static function availableUsersBaseQuery(Server $node): QueryBuilder
    {
        return User::toBase()
            ->whereIn('group_id', $node->group_ids)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->orderBy('id', 'asc');
    }
}

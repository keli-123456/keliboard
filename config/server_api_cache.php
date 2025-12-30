<?php

return [
    // 缓存使用的 store（config/cache.php 里的 stores key）
    'store' => (string) env('SERVER_API_CACHE_STORE', 'redis-cache'),

    // 节点拉取用户列表的缓存 TTL（秒）；设为 0 关闭缓存
    'user_ttl' => (int) env('SERVER_API_USER_CACHE_TTL', 30),

    // 节点拉取配置的缓存 TTL（秒）；设为 0 关闭缓存
    'config_ttl' => (int) env('SERVER_API_CONFIG_CACHE_TTL', 10),

    // 防止缓存击穿的锁设置
    'lock_ttl' => (int) env('SERVER_API_CACHE_LOCK_TTL', 10),
    'lock_wait' => (int) env('SERVER_API_CACHE_LOCK_WAIT', 3),
];

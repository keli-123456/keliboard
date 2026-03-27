<?php

return [
    'enabled' => env('NODE_REALTIME_ENABLED', true),
    'host' => env('NODE_REALTIME_HOST', '0.0.0.0'),
    'port' => (int) env('NODE_REALTIME_PORT', 7002),
    'path' => env('NODE_REALTIME_PATH', '/ws/node'),
    'public_url' => env('NODE_REALTIME_PUBLIC_URL', ''),
    'public_port' => (int) env('NODE_REALTIME_PUBLIC_PORT', env('NODE_REALTIME_PORT', 7002)),
    'ping_interval' => max(5, (int) env('NODE_REALTIME_PING_INTERVAL', 30)),
    'redis' => [
        'connection' => env('NODE_REALTIME_REDIS_CONNECTION', 'default'),
        'queue' => env('NODE_REALTIME_REDIS_QUEUE', 'xboard:node_realtime:events'),
        'max_length' => max(100, (int) env('NODE_REALTIME_REDIS_MAX_LENGTH', 10000)),
    ],
];

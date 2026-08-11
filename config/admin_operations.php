<?php

return [
    'connection' => env('ADMIN_OPERATION_QUEUE_CONNECTION', 'redis'),
    'queue' => env('ADMIN_OPERATION_QUEUE', 'admin_operations'),
    'max_items' => max(1, (int) env('ADMIN_OPERATION_MAX_ITEMS', 5000)),
    'history_limit' => max(10, (int) env('ADMIN_OPERATION_HISTORY_LIMIT', 50)),
    'stale_after_seconds' => max(300, (int) env('ADMIN_OPERATION_STALE_AFTER_SECONDS', 1800)),
];

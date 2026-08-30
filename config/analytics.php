<?php

return [
    'retention' => [
        'visitor_days' => max(7, (int) env('ANALYTICS_VISITOR_RETENTION_DAYS', 35)),
        'invite_click_days' => max(30, (int) env('ANALYTICS_INVITE_CLICK_RETENTION_DAYS', 180)),
        'domain_metric_days' => max(90, (int) env('ANALYTICS_DOMAIN_METRIC_RETENTION_DAYS', 400)),
    ],
    'cleanup_batch_size' => max(100, (int) env('ANALYTICS_CLEANUP_BATCH_SIZE', 1000)),
];

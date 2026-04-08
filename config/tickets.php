<?php

return [
    'retention_days' => (int) env('TICKET_RETENTION_DAYS', 90),
    'auto_close' => [
        'waiting_user_hours' => (int) env('TICKET_AUTO_CLOSE_WAITING_USER_HOURS', 24),
        'waiting_admin_hours' => (int) env('TICKET_AUTO_CLOSE_WAITING_ADMIN_HOURS', 48),
    ],
    // 员工（is_staff）在用户未回复前最多可连续回复的次数，0 表示不限制
    'staff_reply_limit' => (int) env('TICKET_STAFF_REPLY_LIMIT', 2),
    'attachments' => [
        'disk' => env('TICKET_ATTACHMENTS_DISK', 'local'),
        'base_dir' => env('TICKET_ATTACHMENTS_DIR', 'ticket_attachments'),
        'max_images' => (int) env('TICKET_ATTACHMENTS_MAX_IMAGES', 3),
        'max_kb' => (int) env('TICKET_ATTACHMENTS_MAX_KB', 5120),
        'max_dimension' => (int) env('TICKET_ATTACHMENTS_MAX_DIMENSION', 1920),
        'webp_quality' => (int) env('TICKET_ATTACHMENTS_WEBP_QUALITY', 80),
        'thumbnail_max_dimension' => (int) env('TICKET_ATTACHMENTS_THUMB_MAX_DIMENSION', 360),
        'thumbnail_webp_quality' => (int) env('TICKET_ATTACHMENTS_THUMB_WEBP_QUALITY', 72),
        'prewarm_thumbnails' => (bool) env('TICKET_ATTACHMENTS_PREWARM_THUMBNAILS', true),
        'prewarm_schedule' => (bool) env('TICKET_ATTACHMENTS_PREWARM_SCHEDULE', false),
        'prewarm_schedule_chunk' => (int) env('TICKET_ATTACHMENTS_PREWARM_SCHEDULE_CHUNK', 200),
        'prewarm_schedule_limit' => (int) env('TICKET_ATTACHMENTS_PREWARM_SCHEDULE_LIMIT', 500),
        'preview_ttl' => (int) env('TICKET_ATTACHMENTS_PREVIEW_TTL', 15),
    ],
];

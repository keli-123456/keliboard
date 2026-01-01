<?php

return [
    'retention_days' => (int) env('TICKET_RETENTION_DAYS', 90),
    'attachments' => [
        'disk' => env('TICKET_ATTACHMENTS_DISK', 'local'),
        'base_dir' => env('TICKET_ATTACHMENTS_DIR', 'ticket_attachments'),
        'max_images' => (int) env('TICKET_ATTACHMENTS_MAX_IMAGES', 3),
        'max_kb' => (int) env('TICKET_ATTACHMENTS_MAX_KB', 5120),
        'max_dimension' => (int) env('TICKET_ATTACHMENTS_MAX_DIMENSION', 1920),
        'webp_quality' => (int) env('TICKET_ATTACHMENTS_WEBP_QUALITY', 80),
    ],
];

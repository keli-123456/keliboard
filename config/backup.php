<?php

return [
    'job_timeout_seconds' => (int) env('BACKUP_JOB_TIMEOUT_SECONDS', 21600),
    'stale_after_seconds' => (int) env('BACKUP_STALE_AFTER_SECONDS', 25200),

    'encryption_enabled' => env('BACKUP_ENCRYPTION_ENABLED', true),
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY', env('APP_KEY')),
    'encryption_key_source' => trim((string) env('BACKUP_ENCRYPTION_KEY', '')) !== '' ? 'backup_key' : 'app_key',
    'resource_max_files' => (int) env('BACKUP_RESOURCE_MAX_FILES', 20000),
    'resource_max_bytes' => (int) env('BACKUP_RESOURCE_MAX_BYTES', 5368709120),
    'keep_local_after_upload' => env('BACKUP_KEEP_LOCAL_AFTER_UPLOAD', true),

    /*
    |--------------------------------------------------------------------------
    | Recovery Metadata
    |--------------------------------------------------------------------------
    |
    | Database backups prepend a small SQL-comment metadata block before the
    | dump. Keep these files small: they are meant to help rebuild a panel on a
    | new host, not to replace the database dump or storage volume backups.
    |
    */

    'recovery_environment_file' => env('BACKUP_RECOVERY_ENV_FILE'),

    'recovery_files' => [
        'docker-compose.yml',
        'docker-compose.yaml',
        'compose.yml',
        'compose.yaml',
    ],

    'recovery_file_max_bytes' => 524288,

    'resource_sets' => [
        'ticket_attachments' => [
            'path' => storage_path('app' . DIRECTORY_SEPARATOR . trim(
                (string) env('TICKET_ATTACHMENTS_DIR', 'ticket_attachments'), '/\\'
            )),
            'label' => 'Ticket attachments',
        ],
        'themes' => [
            'path' => 'storage/theme',
            'label' => 'Uploaded themes',
        ],
        'plugins' => [
            'path' => 'plugins',
            'label' => 'Installed plugins',
        ],
    ],

    'default_resource_sets' => ['ticket_attachments', 'themes', 'plugins'],
];

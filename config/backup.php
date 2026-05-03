<?php

return [
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
];

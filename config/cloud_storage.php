<?php

return [

    'google_cloud' => [
        'key_file' => env('GOOGLE_CLOUD_KEY_FILE') ? base_path(env('GOOGLE_CLOUD_KEY_FILE')) : null,
        'storage_bucket' => env('GOOGLE_CLOUD_STORAGE_BUCKET'),
    ],

    'ftp' => [
        'host' => env('BACKUP_FTP_HOST'),
        'port' => (int) env('BACKUP_FTP_PORT', 21),
        'username' => env('BACKUP_FTP_USERNAME'),
        'password' => env('BACKUP_FTP_PASSWORD', ''),
        'root' => env('BACKUP_FTP_ROOT', 'backup'),
        'ssl' => filter_var(env('BACKUP_FTP_SSL', false), FILTER_VALIDATE_BOOLEAN),
        'passive' => filter_var(env('BACKUP_FTP_PASSIVE', true), FILTER_VALIDATE_BOOLEAN),
        'timeout' => (int) env('BACKUP_FTP_TIMEOUT', 30),
    ],

];

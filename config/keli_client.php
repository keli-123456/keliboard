<?php

return [
    'discovery' => [
        'enabled' => env('KELI_CLIENT_DISCOVERY_ENABLED', true),
        'api_base' => env('KELI_CLIENT_API_BASE'),
        'api_prefix' => env('KELI_CLIENT_API_PREFIX', '/api/v1'),
        'backup_api_bases' => env('KELI_CLIENT_BACKUP_API_BASES', ''),
        'bootstrap_urls' => env('KELI_CLIENT_BOOTSTRAP_URLS', ''),
        'ttl' => env('KELI_CLIENT_DISCOVERY_TTL', 3600),
        'ed25519_private_key' => env('KELI_CLIENT_DISCOVERY_ED25519_PRIVATE_KEY'),
    ],
];

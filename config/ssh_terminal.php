<?php

return [
    'enabled' => env('SSH_TERMINAL_ENABLED', false),
    'cache_store' => env('SSH_TERMINAL_CACHE_STORE', env('CACHE_DRIVER', 'file')),
    // Private networks require an explicit operator allowlist, never a browser override.
    'allowed_private_hosts' => array_values(array_filter(explode(',', env('SSH_TERMINAL_PRIVATE_HOSTS', '')))),
    'session_seconds' => 1200,
    'idle_seconds' => 600,
];

<?php

return [
    'enabled' => env('AGENT_TERMINAL_ENABLED', false),
    'cache_store' => env('AGENT_TERMINAL_CACHE_STORE', env('CACHE_DRIVER', 'file')),
];

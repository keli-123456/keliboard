<?php

return [
    // How long to keep user sync events (days). Nodes that fall behind this window
    // will automatically receive a full user list snapshot.
    'retention_days' => (int) env('USER_SYNC_RETENTION_DAYS', 30),

    // Max events returned per user_delta request.
    'delta_limit' => (int) env('USER_SYNC_DELTA_LIMIT', 5000),
];


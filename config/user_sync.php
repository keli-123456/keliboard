<?php

return [
    // How long to keep user sync events (days). Nodes that fall behind this window
    // will automatically receive a full user list snapshot.
    'retention_days' => (int) env('USER_SYNC_RETENTION_DAYS', 30),

    // Max events returned per user_delta request.
    'delta_limit' => (int) env('USER_SYNC_DELTA_LIMIT', 5000),

    // Cleanup command chunk size for deleting old user_sync_events.
    'cleanup_batch_size' => (int) env('USER_SYNC_CLEANUP_BATCH_SIZE', 5000),

    // Optional pause between cleanup batches to smooth DB load.
    'cleanup_sleep_ms' => (int) env('USER_SYNC_CLEANUP_SLEEP_MS', 0),

    // Use index-friendly UNION query strategy for user_delta.
    // Disable this to force legacy OR predicate query.
    'use_union_query_for_delta' => (bool) env('USER_SYNC_USE_UNION_QUERY_FOR_DELTA', true),

    // Prefer reading node user list from user_sync_states (precomputed availability).
    // Turn this off to quickly rollback to legacy v2_user query path.
    'use_state_table_for_server_users' => (bool) env('USER_SYNC_USE_STATE_TABLE_FOR_SERVER_USERS', true),
];

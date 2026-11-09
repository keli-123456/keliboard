<?php

return [
    // Background cache-only forecasts write audit records, never native execution intents.
    'preview_enabled' => filter_var(env('MACHINE_FAILOVER_PREVIEW_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // Observation writes local evidence only; it cannot enable provisioning or DNS.
    'observe_enabled' => false,
    // Recovers existing native intents only; the separate hard gate still rejects all execution.
    'runner_enabled' => false,
    // Internal, revision-pinned admission only; the execution gate still rejects it.
    'admission_enabled' => false,
    'admission_authorizations' => [],
    // Separate authorization is required; collecting a plan never enables creation.
    'allow_replacement' => false,
    'allowed_replacement_policy_ids' => [],
    // A first create authorization never implicitly permits another credential attempt.
    'allow_rotation' => false,
    'allowed_rotation_policy_ids' => [],
    'sample_history_limit' => 500,
    'plan_history_limit' => 100,
    'history_days' => 7,
];

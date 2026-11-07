<?php

return [
    // This gate is deliberately not exposed as an environment or browser toggle yet.
    'allow_live' => false,
    'allowed_test_machine_ids' => [],
    'supported_regions' => ['sg-sin-2', 'jp-tyo-3'],
    'supported_sizes' => ['g6-standard-4'],
    'supported_images' => ['linode/debian12'],
    'timeout_seconds' => 1200,
    'poll_seconds' => 20,
    'daily_limit_per_account' => 2,
];

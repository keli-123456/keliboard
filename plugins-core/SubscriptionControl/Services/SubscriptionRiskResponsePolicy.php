<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

/** Behavioral suspicion cannot authorize account-wide credential rotation. */
final class SubscriptionRiskResponsePolicy
{
    public static function forEvent(string $code, string $requested = ''): string
    {
        if (in_array($code, ['source_ip_denylist', 'ua_blacklist', 'ua_block_only', 'credential_rotation_failed'], true)) return 'block';
        if ($code === 'one_time') return $requested === 'block' ? 'block' : 'reset_token_uuid';
        if ($code === 'client_ua_not_allowed') return strtolower(trim($requested)) === 'block' ? 'block' : 'observe';
        if ($code === 'subscription_leak_guard' && $requested === 'observe') return 'observe';
        if (in_array($code, ['source_batch_pull', 'subscription_leak_guard', 'ip_limit', 'rate_limit'], true)) return 'throttle';
        return 'observe';
    }

    public static function forConfig(string $key): string
    {
        return match ($key) {
            'source_ip_deny_action' => 'block',
            'source_batch_action', 'leak_guard_action', 'leak_guard_escalate_action', 'ip_limit_action' => 'throttle',
            default => 'observe',
        };
    }
}

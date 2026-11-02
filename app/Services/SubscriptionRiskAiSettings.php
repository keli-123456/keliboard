<?php

declare(strict_types=1);

namespace App\Services;

/** Risk model overrides share only the existing connection and encrypted credential. */
class SubscriptionRiskAiSettings
{
    public static function publicSettings(): array
    {
        return [
            'risk_ai_model' => trim((string) admin_setting('risk_ai_model', '')),
            'risk_ai_reasoning_effort' => (string) admin_setting('risk_ai_reasoning_effort', 'low'),
            'risk_ai_timeout' => max(5, min(45, (int) admin_setting('risk_ai_timeout', 30))),
            'risk_ai_max_tokens' => max(128, min(4096, (int) admin_setting('risk_ai_max_tokens', 4096))),
        ];
    }

    public static function forProvider(array $shared): array
    {
        $settings = self::publicSettings();
        $model = $settings['risk_ai_model'] !== '' ? $settings['risk_ai_model'] : (string) ($shared['model'] ?? '');
        return array_merge($shared, [
            'model' => $model,
            'reasoning_effort' => TicketAiProviderClient::reasoningEffort($model, $settings['risk_ai_reasoning_effort']),
            'timeout' => $settings['risk_ai_timeout'],
            'max_tokens' => $settings['risk_ai_max_tokens'],
            'json_mode' => true,
            'temperature' => 0.1,
        ]);
    }

    public static function forPublicProvider(array $ticket): array
    {
        return self::forProvider([
            'enabled' => (bool) ($ticket['ticket_ai_enable'] ?? false),
            'base_url' => (string) ($ticket['ticket_ai_base_url'] ?? ''),
            'model' => (string) ($ticket['ticket_ai_model'] ?? ''),
            'key_configured' => (bool) ($ticket['ticket_ai_api_key_set'] ?? false),
        ]);
    }

    public static function ready(array $public): bool
    {
        return ($public['enabled'] ?? false) && ($public['key_configured'] ?? false)
            && trim((string) ($public['base_url'] ?? '')) !== '' && trim((string) ($public['model'] ?? '')) !== '';
    }
}

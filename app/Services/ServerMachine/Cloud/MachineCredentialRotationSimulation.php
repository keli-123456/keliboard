<?php
namespace App\Services\ServerMachine\Cloud;

class MachineCredentialRotationSimulation
{
    public const SCENARIOS = ['credential_auth_rotate', 'credential_permission_rotate', 'credential_billing_rotate', 'credential_quota_same_account',
        'credential_rate_limited', 'credential_template_error', 'credential_unknown_no_rotate', 'credential_pool_exhausted', 'credential_identity_unknown', 'credential_duplicate_token'];

    public function create(array $context, string $scenario, string $operation): array
    {
        $provider = $context['provider'] ?? 'linode'; $pool = [];
        foreach ([1, 2, 3] as $id) $pool[] = ['id' => $id, 'revision' => 1, 'group_id' => 1, 'provider' => $provider, 'credential_key' => hash('sha256', 'synthetic-token-' . $id),
            'identity_key' => hash('sha256', 'synthetic-account-' . ($id === 3 ? 2 : 1)), 'eligible' => true, 'budget_available' => true];
        if ($scenario === 'credential_identity_unknown') $pool[0]['identity_key'] = null;
        if ($scenario === 'credential_duplicate_token') $pool[1]['credential_key'] = $pool[0]['credential_key'];
        if ($scenario === 'credential_quota_same_account') $pool[2]['identity_key'] = $pool[0]['identity_key'];
        if ($scenario === 'credential_pool_exhausted') { $pool[1]['eligible'] = false; $pool[2]['eligible'] = false; }
        $status = match ($scenario) {
            'credential_auth_rotate', 'credential_duplicate_token', 'credential_pool_exhausted' => 401,
            'credential_permission_rotate' => 403, 'credential_billing_rotate', 'credential_identity_unknown' => 402,
            'credential_quota_same_account', 'credential_template_error' => 422, 'credential_rate_limited' => 429,
            'credential_unknown_no_rotate' => 503, default => throw new CloudException('invalid_simulation'),
        };
        $body = match ($provider) {
            'linode' => ['errors' => [['reason' => $scenario === 'credential_template_error' ? 'Invalid image' : 'Instance limit reached',
                'field' => $scenario === 'credential_template_error' ? 'image' : null]]],
            'digitalocean' => ['message' => $scenario === 'credential_template_error' ? 'Invalid image' : 'Droplet limit reached'],
            'vultr' => ['error' => $scenario === 'credential_template_error' ? 'Invalid image' : 'Instance limit reached'],
            default => throw new CloudException('invalid_simulation'),
        };
        $limit = max(1, min(3, (int) ($context['credential_attempt_limit'] ?? 1)));
        $current = $pool[0]; $tried = []; $attempts = [];
        while (count($tried) < $limit) {
            $tried[] = $current['id']; $ordinal = count($tried);
            if ($ordinal > 1) {
                $attempts[] = ['ordinal' => $ordinal, 'credential' => chr(64 + $current['id']), 'kind' => 'accepted', 'action' => 'accept',
                    'reason' => 'synthetic_create_confirmed', 'http_status' => 200, 'intent' => hash('sha256', $operation . ':' . $ordinal)];
                return ['status' => 'ok', 'credential_attempts' => $attempts];
            }
            $failure = app(CloudFailurePolicy::class)->response($provider, 'POST', $status, $body);
            $decision = app(MachineCredentialRotationPlanner::class)->decide($failure, $current, $pool, $limit - count($tried), $tried);
            $attempts[] = ['ordinal' => $ordinal, 'credential' => chr(64 + $current['id']), 'kind' => $failure['kind'], 'action' => $decision['action'],
                'reason' => $decision['reason'], 'http_status' => $status, 'intent' => hash('sha256', $operation . ':' . $ordinal)];
            if ($decision['action'] !== 'rotate') return ['status' => $decision['action'] === 'reconcile' ? 'unknown' : 'failed', 'credential_attempts' => $attempts];
            $current = $pool[array_search($decision['next_account_id'], array_column($pool, 'id'), true)];
        }
        return ['status' => 'failed', 'credential_attempts' => $attempts];
    }
}

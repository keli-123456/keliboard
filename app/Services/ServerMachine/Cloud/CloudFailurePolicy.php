<?php
namespace App\Services\ServerMachine\Cloud;

class CloudFailurePolicy
{
    public function accountRestriction(string $provider, array $body): ?array
    {
        if ($provider !== 'digitalocean' || ($body['account']['status'] ?? null) !== 'locked') return null;
        return array_replace($this->result($provider, 'billing_locked', 200), ['outcome' => 'not_attempted', 'rotation_candidate' => false]);
    }
    public function transport(string $provider, string $method): array
    {
        return $this->result($provider, strtoupper($method) === 'GET' ? 'provider_unavailable' : 'outcome_unknown', null);
    }

    public function response(string $provider, string $method, int $status, mixed $body = null, array $headers = [], ?int $now = null): array
    {
        $write = strtoupper($method) !== 'GET'; $now ??= time();
        if ($write && ($status >= 500 || in_array($status, [408, 409], true) || $status < 200 || ($status >= 300 && $status < 400))) {
            return $this->result($provider, 'outcome_unknown', $status);
        }
        if ($status >= 200 && $status < 300) return $this->result($provider, $write ? 'outcome_unknown' : 'response_invalid', $status);
        $kind = match ($status) {
            401 => 'auth_invalid', 403 => 'permission_denied', 402 => 'billing_locked', 404 => 'resource_missing',
            429 => 'rate_limited', default => $status >= 500 ? 'provider_unavailable' : 'request_rejected',
        };
        if (in_array($status, [400, 422], true)) {
            $reasons = $this->reasons($provider, $body);
            $matches = [];
            foreach ($reasons as $reason) {
                $text = strtolower($reason['text']); $field = strtolower($reason['field']);
                if (preg_match('/\b(insufficient balance|payment required|billing (?:locked|disabled)|account (?:is )?(?:locked|suspended)|past due)\b/', $text)) $matches[] = 'billing_locked';
                elseif (preg_match('/\b(quota (?:exceeded|exhausted)|(?:droplet|instance|resource) limit (?:exceeded|reached)|maximum number of (?:droplets|instances))\b/', $text)) $matches[] = 'quota_exhausted';
                elseif (preg_match('/\b(out of stock|insufficient capacity|no capacity available|capacity unavailable)\b/', $text)) $matches[] = 'capacity_unavailable';
                elseif (in_array($field, ['region', 'type', 'image', 'size', 'plan', 'os_id'], true)
                    || preg_match('/\b(invalid (?:region|size|image|plan|type|os)|(?:image|plan|size) (?:is )?not available in (?:this |the )?region)\b/', $text)) $matches[] = 'template_invalid';
                else $matches[] = 'request_rejected';
            }
            $matches = array_values(array_unique($matches));
            // Mixed or unrecognized reasons must not authorize token hopping.
            if (count($matches) === 1) $kind = $matches[0];
        }
        $result = $this->result($provider, $kind, $status);
        if ($kind === 'rate_limited') {
            $headers = array_change_key_case($headers, CASE_LOWER);
            $after = $headers['retry-after'] ?? null;
            if (is_array($after)) $after = count($after) === 1 ? $after[0] : null;
            $seconds = is_string($after) && preg_match('/^\d{1,8}$/D', $after) ? (int) $after : null;
            if ($seconds === null && is_string($after) && strlen($after) < 80 && preg_match('/GMT$/D', $after)) {
                $at = strtotime($after); if ($at !== false) $seconds = $at - $now;
            }
            if ($seconds === null && $provider === 'digitalocean') {
                $reset = $headers['ratelimit-reset'] ?? null;
                if (is_array($reset)) $reset = count($reset) === 1 ? $reset[0] : null;
                if (is_string($reset) && preg_match('/^\d{9,11}$/D', $reset)) $seconds = (int) $reset - $now;
            }
            if ($seconds !== null && $seconds >= 0 && $seconds <= 86400) $result['cooldown_seconds'] = max($result['cooldown_seconds'], $seconds);
            elseif ($seconds !== null && $seconds > 86400) $result['requires_review'] = true;
        }
        return $result;
    }

    private function reasons(string $provider, mixed $body): array
    {
        if (!is_array($body)) return [];
        $values = match ($provider) {
            'linode' => is_array($body['errors'] ?? null) && array_is_list($body['errors']) && count($body['errors']) <= 20 ? $body['errors'] : [],
            'digitalocean' => [['reason' => $body['message'] ?? null]],
            'vultr' => [['reason' => $body['error'] ?? null]], default => [],
        };
        $result = [];
        foreach ($values as $row) {
            if (!is_array($row) || !is_string($row['reason'] ?? null) || strlen($row['reason']) > 2048
                || (isset($row['field']) && (!is_string($row['field']) || strlen($row['field']) > 80))) return [];
            $result[] = ['text' => $row['reason'], 'field' => $row['field'] ?? ''];
        }
        return $result;
    }

    private function result(string $provider, string $kind, ?int $status): array
    {
        $scope = match ($kind) {
            'auth_invalid', 'permission_denied' => 'credential', 'billing_locked', 'quota_exhausted' => 'account',
            'template_invalid', 'request_rejected' => 'template', 'capacity_unavailable' => 'region',
            'rate_limited', 'provider_unavailable' => 'provider', default => 'intent',
        };
        $cooldown = match ($kind) { 'auth_invalid', 'permission_denied' => 1800, 'billing_locked' => 3600,
            'quota_exhausted', 'capacity_unavailable' => 300, 'rate_limited' => 120, 'provider_unavailable' => 20, default => 0 };
        return ['provider' => $provider, 'kind' => $kind, 'scope' => $scope, 'http_status' => $status,
            'outcome' => $kind === 'outcome_unknown' ? 'unknown' : 'rejected',
            'code' => match ($kind) { 'auth_invalid', 'permission_denied' => 'provider_forbidden', 'rate_limited' => 'provider_rate_limited',
                'outcome_unknown' => 'outcome_uncertain', 'provider_unavailable' => 'provider_unavailable', 'resource_missing' => 'resource_missing',
                'response_invalid' => 'provider_invalid', default => 'provider_rejected' },
            'cooldown_seconds' => $cooldown, 'requires_review' => in_array($kind, ['outcome_unknown', 'response_invalid', 'request_rejected', 'template_invalid'], true),
            'rotation_candidate' => in_array($kind, ['auth_invalid', 'permission_denied', 'billing_locked', 'quota_exhausted'], true)];
    }
}

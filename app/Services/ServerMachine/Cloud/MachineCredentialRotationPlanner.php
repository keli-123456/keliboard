<?php
namespace App\Services\ServerMachine\Cloud;

// Produces a plan only. It never mutates task identity or dispatches a create request.
class MachineCredentialRotationPlanner
{
    public function decide(array $failure, array $current, array $candidates, int $remaining, array $attempted = []): array
    {
        $base = ['automatic' => false, 'dispatch_allowed' => false, 'next_account_id' => null, 'next_account_revision' => null];
        $finish = fn ($action, $reason) => $base + compact('action', 'reason');
        if (($failure['outcome'] ?? null) === 'unknown') return $finish('reconcile', 'original_intent_only');
        $kind = $failure['kind'] ?? null;
        if (in_array($kind, ['rate_limited', 'provider_unavailable'], true)) return $finish('wait', 'provider_backoff');
        if (!in_array($kind, ['auth_invalid', 'permission_denied', 'billing_locked', 'quota_exhausted'], true)
            || ($failure['outcome'] ?? null) !== 'rejected' || ($failure['rotation_candidate'] ?? null) !== true
            || ($failure['requires_review'] ?? null) !== false) return $finish('stop', 'plan_review_required');
        if (!is_int($current['id'] ?? null) || !is_int($current['group_id'] ?? null) || $current['group_id'] < 1
            || !is_string($current['credential_key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $current['credential_key'])
            || ($current['provider'] ?? null) !== ($failure['provider'] ?? null)) return $finish('stop', 'credential_context_invalid');
        if (!array_is_list($attempted) || count(array_filter($attempted, fn ($id) => !is_int($id) || $id < 1))) return $finish('stop', 'credential_context_invalid');
        if ($remaining <= 0 || count(array_unique($attempted)) >= 20) return $finish('stop', 'rotation_budget_exhausted');
        $accountFailure = in_array($kind, ['billing_locked', 'quota_exhausted'], true);
        if ($accountFailure && !$this->identity($current['identity_key'] ?? null)) return $finish('stop', 'account_identity_required');
        $triedKeys = [$current['credential_key']];
        foreach ($candidates as $candidate) if (in_array($candidate['id'] ?? null, $attempted, true)) $triedKeys[] = $candidate['credential_key'] ?? null;
        $budgetBlocked = false;
        foreach ($candidates as $candidate) {
            if (!is_int($candidate['id'] ?? null) || !is_int($candidate['revision'] ?? null) || $candidate['revision'] < 1
                || $candidate['id'] === $current['id'] || in_array($candidate['id'], $attempted, true)
                || ($candidate['provider'] ?? null) !== $current['provider'] || ($candidate['group_id'] ?? null) !== $current['group_id']
                || !is_string($candidate['credential_key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $candidate['credential_key'])
                || in_array($candidate['credential_key'], $triedKeys, true) || ($candidate['eligible'] ?? null) !== true) continue;
            if ($accountFailure && (!$this->identity($candidate['identity_key'] ?? null) || $candidate['identity_key'] === $current['identity_key'])) continue;
            if (($candidate['budget_available'] ?? null) !== true) { $budgetBlocked = true; continue; }
            return array_replace($finish('rotate', 'definite_rejection_next_credential'), ['next_account_id' => $candidate['id'], 'next_account_revision' => $candidate['revision']]);
        }
        return $finish('stop', $budgetBlocked ? 'rotation_budget_exhausted' : 'credential_pool_exhausted');
    }
    private function identity(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
}

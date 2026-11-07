<?php
namespace App\Services\ServerMachine\Cloud;

class MachineFailoverStateMachine
{
    public const TERMINAL = ['healthy', 'completed', 'recovered', 'retained', 'failed', 'blocked', 'needs_review', 'cancelled'];
    public const ACTIONS = ['creating' => 'create', 'create_unknown' => 'reconcile_create', 'waiting_initialization' => 'initialize',
        'waiting_reports' => 'reports', 'checking_ipv4' => 'ipv4', 'verifying' => 'forwarding',
        'preparing_dns' => 'prepare_dns', 'switching_dns' => 'switch_dns', 'checking_dns' => 'verify_dns', 'dns_unknown' => 'reconcile_dns'];

    public function initial(): array
    {
        return ['phase' => 'observing', 'reason' => null, 'failures' => 0, 'baseline_at' => null, 'last_sample_at' => 0,
            'create_dispatched' => false, 'dns_dispatched' => false, 'source_recovered' => false, 'candidate' => null];
    }

    public function terminal(array $state): bool { return in_array($state['phase'], self::TERMINAL, true); }
    private function move(array $state, string $phase, ?string $reason = null): array { return array_replace($state, compact('phase', 'reason')); }

    public function source(array $state, array $fact, array $context, int $now): array
    {
        if ($this->terminal($state)) return $state;
        if (!$this->fresh($fact, $context, $now) || ($fact['installation_id'] ?? null) !== $context['source_installation_id']
            || ($fact['ip'] ?? null) !== $context['source_ip']) return $this->unverified($state, $fact);
        if (($fact['at'] ?? 0) <= $state['last_sample_at']) return $state;
        $probe = $this->probe($fact['targets'] ?? null);
        $state['last_sample_at'] = $fact['at'];
        if ($probe === 'unknown') return $this->unverified($state, $fact);
        if ($probe === 'reachable') {
            $hadFailure = $state['failures'] > 0; $state['failures'] = 0; $state['baseline_at'] = $fact['at'];
            if ($state['phase'] === 'queued' || ($state['phase'] === 'creating' && !$state['create_dispatched'])) return $this->move($state, 'recovered', 'source_recovered');
            if ($state['create_dispatched']) {
                $state['source_recovered'] = true;
                if ($state['candidate'] === null) return $this->move($state, 'needs_review', 'create_outcome_unknown');
                if (!$state['dns_dispatched']) return $this->move($state, 'retained', 'source_recovered_after_create');
            }
            if ($state['phase'] === 'observing' && ($fact['window_complete'] ?? false)) return $this->move($state, $hadFailure ? 'recovered' : 'healthy');
            return $state;
        }
        if ($state['phase'] !== 'observing') return $state;
        if ($state['baseline_at'] === null || $now - $state['baseline_at'] > $context['stale_after_seconds']) {
            return ($fact['window_complete'] ?? false) ? $this->move($state, 'blocked', 'healthy_baseline_required') : $state;
        }
        $state['failures']++;
        return $state['failures'] >= $context['failure_threshold'] ? $this->move($state, 'queued') : $state;
    }

    private function unverified(array $state, array $fact): array
    {
        if ($state['phase'] !== 'observing') return $this->move($state, $state['create_dispatched'] ? 'needs_review' : 'blocked', 'source_unverified');
        $state['failures'] = 0;
        return ($fact['window_complete'] ?? false) ? $this->move($state, 'blocked', 'source_unverified') : $state;
    }

    public function reserve(array $state, bool $allowed, ?string $reason = null): array
    {
        if ($state['phase'] !== 'queued') throw new CloudException('invalid_transition');
        return $this->move($state, $allowed ? 'creating' : 'blocked', $allowed ? null : $reason);
    }

    public function dispatched(array $state, string $kind): array
    {
        if ((self::ACTIONS[$state['phase']] ?? null) !== $kind) throw new CloudException('invalid_transition');
        if ($kind === 'create') $state['create_dispatched'] = true;
        if ($kind === 'switch_dns') $state['dns_dispatched'] = true;
        return $state;
    }

    public function receipt(array $state, string $kind, array $fact, array $context, string $operation, int $now): array
    {
        if ($this->terminal($state)) return $state;
        if ((self::ACTIONS[$state['phase']] ?? null) !== $kind) throw new CloudException('invalid_transition');
        if (!$this->fresh($fact, $context, $now) || ($fact['operation_id'] ?? null) !== $operation) return $this->move($state, 'needs_review', 'receipt_invalid');
        $status = $fact['status'] ?? null;
        if ($kind === 'create' && $status === 'unknown') return $this->move($state, 'create_unknown', 'create_outcome_unknown');
        if ($kind === 'switch_dns' && $status === 'unknown') return $this->move($state, 'dns_unknown', 'dns_outcome_unknown');
        if ($status !== 'ok') return $this->move($state, in_array($kind, ['reconcile_create', 'reconcile_dns', 'verify_dns'], true) ? 'needs_review' : 'failed',
            match ($kind) { 'create', 'reconcile_create' => 'create_unresolved', 'initialize' => 'initialization_failed',
                'reports' => 'reports_not_ready', 'ipv4' => 'candidate_ipv4_unreachable', 'forwarding' => 'forwarding_failed',
                default => 'dns_not_verified' });
        if (in_array($kind, ['create', 'reconcile_create'], true)) {
            $candidate = $fact['candidate'] ?? null;
            if (!is_array($candidate) || ($candidate['installation_id'] ?? null) !== $context['candidate_installation_id']
                || ($candidate['binary_sha256'] ?? null) !== $context['binary_sha256'] || ($candidate['ip'] ?? null) !== $context['candidate_ip']
                || ($candidate['id'] ?? null) !== $context['candidate_id']) return $this->move($state, 'needs_review', 'candidate_identity_changed');
            $state['candidate'] = $candidate;
            return $this->move($state, 'waiting_initialization');
        }
        if ($kind !== 'prepare_dns' && !$this->candidateMatches($fact, $state, $context)) return $this->move($state, 'needs_review', 'candidate_identity_changed');
        return match ($kind) {
            'initialize' => $this->move($state, ($fact['installed'] ?? false) === true ? 'waiting_reports' : 'failed', ($fact['installed'] ?? false) === true ? null : 'initialization_failed'),
            'reports' => $this->move($state, ($fact['agent'] ?? false) === true && ($fact['nodes'] ?? false) === true
                && ($fact['configuration_sha256'] ?? null) === $context['configuration_sha256'] ? 'checking_ipv4' : 'failed',
                ($fact['agent'] ?? false) === true && ($fact['nodes'] ?? false) === true && ($fact['configuration_sha256'] ?? null) === $context['configuration_sha256'] ? null : 'reports_not_ready'),
            'ipv4' => $this->move($state, $this->probe($fact['targets'] ?? null) === 'reachable' ? 'verifying' : 'failed', $this->probe($fact['targets'] ?? null) === 'reachable' ? null : 'candidate_ipv4_unreachable'),
            'forwarding' => $this->move($state, $this->forwarding($fact, $context) ? 'preparing_dns' : 'failed', $this->forwarding($fact, $context) ? null : 'forwarding_failed'),
            'prepare_dns' => $this->move($state, $this->dnsMatches($fact, $context, false) ? 'switching_dns' : 'needs_review', $this->dnsMatches($fact, $context, false) ? null : 'dns_ownership_changed'),
            'switch_dns' => $this->move($state, 'checking_dns'),
            'verify_dns', 'reconcile_dns' => $this->move($state, $this->dnsMatches($fact, $context, true) ? 'completed' : 'needs_review',
                $this->dnsMatches($fact, $context, true) ? ($state['source_recovered'] ? 'source_recovered_after_dns' : null) : 'dns_not_verified'),
            default => throw new CloudException('invalid_transition'),
        };
    }

    private function fresh(array $fact, array $context, int $now): bool
    {
        return ($fact['origin'] ?? null) === 'synthetic' && ($fact['context_hash'] ?? null) === $context['context_hash']
            && is_int($fact['at'] ?? null) && $fact['at'] <= $now && $fact['at'] >= $now - 30;
    }
    private function candidateMatches(array $fact, array $state, array $context): bool
    {
        return ($fact['candidate_id'] ?? null) === ($state['candidate']['id'] ?? null)
            && ($fact['installation_id'] ?? null) === $context['candidate_installation_id'] && ($fact['ip'] ?? null) === $context['candidate_ip']
            && ($fact['binary_sha256'] ?? null) === $context['binary_sha256'];
    }
    private function forwarding(array $fact, array $context): bool
    {
        if (($fact['cleanup_complete'] ?? null) !== true) return false;
        foreach (['tcp', 'udp'] as $protocol) {
            $sample = $fact[$protocol] ?? [];
            if (($sample['sent'] ?? null) !== 3 || ($sample['received'] ?? null) !== 3 || ($sample['missing'] ?? null) !== 0 || ($sample['duplicates'] ?? null) !== 0
                || ($sample['sha256'] ?? null) !== $context['echo_sha256']) return false;
        }
        return true;
    }
    private function dnsMatches(array $fact, array $context, bool $applied): bool
    {
        return ($fact['owned'] ?? null) === true && ($fact['hostname'] ?? null) === $context['dns_hostname']
            && ($fact['credential_revision'] ?? null) === $context['dns_credential_revision']
            && ($fact['current_ip'] ?? null) === $context[$applied ? 'candidate_ip' : 'source_ip'];
    }
    private function probe(mixed $targets): string
    {
        if (!is_array($targets) || !array_is_list($targets) || !$targets || count($targets) > 20) return 'unknown';
        foreach ($targets as $target) if (!is_string($target) || !in_array($target, ['reachable', 'unreachable', 'unknown', 'error'], true)) return 'unknown';
        if (in_array('reachable', $targets, true)) return 'reachable';
        return !array_diff($targets, ['unreachable']) ? 'unreachable' : 'unknown';
    }
}

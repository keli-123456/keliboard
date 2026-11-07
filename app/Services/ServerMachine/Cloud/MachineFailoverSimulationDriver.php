<?php
namespace App\Services\ServerMachine\Cloud;

// Synthetic receipts only. This adapter has no cloud, DNS, SSH, queue or notification transport.
class MachineFailoverSimulationDriver
{
    public const SCENARIOS = ['healthy', 'replace', 'one_target_reachable', 'no_baseline', 'unknown_probe', 'stale_probe',
        'recover_before_create', 'recover_after_create', 'create_rejected', 'create_unknown_found', 'create_ambiguous',
        'initialization_timeout', 'reports_mismatch', 'ipv4_unreachable', 'forwarding_failed', 'dns_conflict', 'dns_unknown_applied', 'dns_unknown_unresolved',
        ...MachineCredentialRotationSimulation::SCENARIOS];

    public function source(array $context, array $state, string $scenario, int $round, int $now): array
    {
        $healthy = in_array($scenario, ['healthy', 'one_target_reachable'], true) || ($round === 0 && $scenario !== 'no_baseline')
            || ($scenario === 'recover_before_create' && in_array($state['phase'], ['queued', 'creating'], true))
            || ($scenario === 'recover_after_create' && $state['create_dispatched']);
        return ['origin' => 'synthetic', 'context_hash' => $context['context_hash'], 'at' => $scenario === 'stale_probe' ? $now - 60 : $now,
            'installation_id' => $context['source_installation_id'], 'ip' => $context['source_ip'],
            'targets' => $scenario === 'unknown_probe' ? ['error', 'unknown'] : [$healthy ? 'reachable' : 'unreachable', 'unreachable'],
            'window_complete' => $round >= $context['failure_threshold']];
    }

    public function execute(string $kind, array $context, array $state, string $scenario, string $operation, int $now): array
    {
        $candidate = ['id' => $context['candidate_id'], 'installation_id' => $context['candidate_installation_id'],
            'ip' => $context['candidate_ip'], 'binary_sha256' => $context['binary_sha256']];
        $sample = ['sent' => 3, 'received' => 3, 'missing' => 0, 'duplicates' => 0, 'sha256' => $context['echo_sha256']];
        $fact = ['origin' => 'synthetic', 'context_hash' => $context['context_hash'], 'operation_id' => $operation, 'at' => $now,
            'status' => 'ok', 'candidate' => $candidate, 'candidate_id' => $candidate['id'], 'installation_id' => $candidate['installation_id'],
            'ip' => $candidate['ip'], 'binary_sha256' => $candidate['binary_sha256'], 'installed' => true, 'agent' => true, 'nodes' => true,
            'configuration_sha256' => $context['configuration_sha256'], 'targets' => ['reachable', 'unreachable'],
            'tcp' => $sample, 'udp' => $sample, 'cleanup_complete' => true, 'owned' => true, 'hostname' => $context['dns_hostname'],
            'credential_revision' => $context['dns_credential_revision'], 'current_ip' => $kind === 'prepare_dns' ? $context['source_ip'] : $context['candidate_ip']];
        if ($kind === 'create' && in_array($scenario, ['create_unknown_found', 'create_ambiguous'], true)) $fact['status'] = 'unknown';
        if ($kind === 'create' && $scenario === 'create_rejected') $fact['status'] = 'failed';
        if ($kind === 'reconcile_create' && $scenario === 'create_ambiguous') $fact['status'] = 'unknown';
        if ($kind === 'initialize' && $scenario === 'initialization_timeout') $fact['status'] = 'failed';
        if ($kind === 'reports' && $scenario === 'reports_mismatch') $fact['configuration_sha256'] = str_repeat('0', 64);
        if ($kind === 'ipv4' && $scenario === 'ipv4_unreachable') $fact['targets'] = ['unreachable', 'unreachable'];
        if ($kind === 'forwarding' && $scenario === 'forwarding_failed') $fact['udp']['missing'] = 1;
        if ($kind === 'prepare_dns' && $scenario === 'dns_conflict') $fact['owned'] = false;
        if ($kind === 'switch_dns' && in_array($scenario, ['dns_unknown_applied', 'dns_unknown_unresolved'], true)) $fact['status'] = 'unknown';
        if ($kind === 'reconcile_dns' && $scenario === 'dns_unknown_unresolved') $fact['current_ip'] = '192.0.2.254';
        if (in_array($scenario, MachineCredentialRotationSimulation::SCENARIOS, true)) {
            if ($kind === 'create') $fact = array_replace($fact, app(MachineCredentialRotationSimulation::class)->create($context, $scenario, $operation));
            elseif ($kind === 'reconcile_create') $fact['status'] = 'unknown';
        }
        return $fact;
    }
}

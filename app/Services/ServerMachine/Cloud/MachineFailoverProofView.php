<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineAcceptanceCheck;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask;

class MachineFailoverProofView
{
    public function forAttempt(Attempt $attempt): array
    {
        $instance = $attempt->task_id ? MachineOwnedInstance::query()->where('task_id', $attempt->task_id)->first() : null;
        $check = $instance ? MachineAcceptanceCheck::query()->where('instance_id', $instance->id)->where('state', 'passed')
            ->where('receipt_sha256', $instance->verification['receipt_sha256'] ?? '')->latest('issued_at')->first() : null;
        $forward = isset($attempt->payload['forwarding_receipt']);
        $dns = isset($attempt->payload['dns_receipt']);
        $transition = isset($attempt->payload['transition_id']) ? MachineDnsTransition::query()->find($attempt->payload['transition_id']) : null;
        $task = $attempt->task_id ? MachineProvisionTask::query()->find($attempt->task_id) : null;
        $ready = $task?->payload['node_readiness'] ?? null;
        $readiness = isset($attempt->payload['readiness_receipt']) || ($ready !== null && !empty($attempt->payload['address_proofs']));
        $readinessState = 'not_checked';
        if ($readiness && $instance) {
            try {
                $ready = app(MachineInstanceReadiness::class)->record($instance, \App\Models\MachineFailoverPolicy::query()->findOrFail($instance->policy_id), $task, true);
                $readinessState = $ready['expires_at'] <= time() ? 'expired' : 'historical';
            } catch (\Throwable) { $readinessState = 'unverified'; }
        }
        $nativeDns = isset($attempt->payload['native_completion']) && $task
            && app(MachineNativeDnsAdmission::class)->completionCurrent($attempt, $task);
        $dns = $dns || $nativeDns;
        // These are historical receipts, not a new client test or a fresh DNS provider read.
        return ['readiness_proof' => ['state' => $readinessState,
                'checked_at' => $readiness ? ($ready['issued_at'] ?? null) : null,
                'valid_until' => $readiness ? ($ready['expires_at'] ?? null) : null,
                'scope' => 'agent_config_ipv4', 'protocol_verified' => false],
            'forwarding_proof' => ['state' => !$forward ? 'not_checked' : (!$check || !$instance ? 'unverified'
                : ($instance->verified_until <= time() ? 'expired' : 'historical')),
                'checked_at' => $forward ? $check?->issued_at : null, 'valid_until' => $forward ? $instance?->verified_until : null,
                'scope' => 'isolated_forwarding'],
            'dns_proof' => ['state' => !$dns ? 'not_checked' : ($nativeDns || $transition?->state === 'verified' ? 'historical' : 'unverified'),
                'checked_at' => $nativeDns ? ($attempt->payload['native_completion']['completed_at'] ?? null)
                    : ($dns && $transition?->state === 'verified' ? ($transition->confirmed_at ?? $transition->updated_at) : null),
                'valid_until' => null, 'scope' => 'provider_readback', 'propagation_verified' => false,
                'readback_attempts' => (int) ($transition?->readback_attempts ?? 0), 'readback_limit' => MachineOwnedDnsService::READBACK_LIMIT,
                'next_check_at' => $transition?->next_check_at],
            'rotation_proof' => ['from_attempt_id' => $attempt->payload['rotation']['from_attempt_id'] ?? null,
                'failure_kind' => $attempt->payload['rotation']['failure_kind'] ?? null,
                'reason' => $attempt->payload['rotation']['reason'] ?? null]];
    }
}

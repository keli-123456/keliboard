<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineAcceptanceCheck;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineOwnedInstance;

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
        // These are historical receipts, not a new client test or a fresh DNS provider read.
        return ['forwarding_proof' => ['state' => !$forward ? 'not_checked' : (!$check || !$instance ? 'unverified'
                : ($instance->verified_until <= time() ? 'expired' : 'historical')),
                'checked_at' => $forward ? $check?->issued_at : null, 'valid_until' => $forward ? $instance?->verified_until : null,
                'scope' => 'isolated_forwarding'],
            'dns_proof' => ['state' => !$dns ? 'not_checked' : ($transition?->state === 'verified' ? 'historical' : 'unverified'),
                'checked_at' => $dns && $transition?->state === 'verified' ? $transition->updated_at : null,
                'valid_until' => null, 'scope' => 'provider_readback']];
    }
}

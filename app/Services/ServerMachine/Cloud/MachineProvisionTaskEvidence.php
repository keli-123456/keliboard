<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineProvisionTask as Task;
use Illuminate\Support\Facades\DB;

// Read-only cleanup proof. Absence of a provider ID alone never proves that POST was not dispatched.
class MachineProvisionTaskEvidence
{
    public static function snapshot(Task $task): array
    {
        return DB::transaction(function () use ($task) {
            $task = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $row = $task->only(['id', 'marker', 'external_id', 'provider_created_at', 'dispatched_at', 'status', 'reason']);
            $row['label'] = $task->payload['request']['label'] ?? null;
            $steps = DB::table('v2_machine_provision_step')->where('task_id', $task->id)->orderBy('id')->limit(201)->get();
            $binding = false;
            $id = $task->payload['coordinated_attempt'] ?? null;
            if (is_string($id) && ($attempt = Attempt::query()->find($id))) {
                $member = Member::query()->find($attempt->execution_member_id);
                $run = $member ? Execution::query()->find($member->execution_id) : null;
                $policy = $member ? Policy::query()->find($member->policy_id) : null;
                $binding = $member && $run && $policy && $member->attempt_id === $attempt->id
                    && $run->admin_id === $task->admin_id && $policy->machine_id === $task->machine_id
                    && $policy->slot === $task->slot && app(MachineFailoverNativeBridge::class)->bindingCurrent($attempt, $task);
            }
            $row['dispatch_evidence'] = ['schema' => 1, 'task_sha256' => hash('sha256', json_encode(
                array_map(fn ($key) => $row[$key], ['id', 'label', 'marker', 'status', 'external_id', 'provider_created_at', 'dispatched_at']), JSON_THROW_ON_ERROR)),
                'checks' => ['task_terminal' => in_array($task->status, ['failed', 'cancelled'], true),
                    'dispatch_unset' => $task->dispatched_at === null,
                    'provider_identity_unset' => $task->external_id === null && $task->provider_created_at === null && !($task->public_ips ?? []),
                    'bootstrap_unset' => $task->bootstrap_at === null && $task->bootstrap_state === null,
                    'step_history_complete' => $steps->count() <= 200,
                    'queued_recorded' => $steps->contains('state', 'queued'),
                    'terminal_recorded' => $steps->contains(fn ($step) => $step->state === $task->status && $step->reason === ($task->reason ?? '')),
                    'no_dispatch_steps' => $steps->every(fn ($step) => in_array($step->state, ['queued', 'failed', 'cancelled'], true)),
                    'native_binding' => $binding]];
            $row['replacement_diagnostic'] = CloudException::safeReplacementDiagnostic($task->evidence['replacement_diagnostic'] ?? null);
            return $row;
        });
    }
}

<?php
declare(strict_types=1);

use App\Models\MachineFailoverAttempt;
use App\Models\MachineFailoverExecution;
use App\Models\MachineFailoverExecutionMember;
use App\Models\MachineFailoverPolicy;
use App\Models\MachineProvisionTask;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineProvisionService;

// A native-advance invocation with an explicit task only reads back that existing candidate.
function keli_acceptance_candidate_readback(array $ids, string $taskId): array
{
    $scope = KeliLiveScope::assertManual(['native-advance']);
    if (KeliLiveScope::$mode !== 'native' || ($GLOBALS['argv'][2] ?? null) !== $taskId
        || !Illuminate\Support\Str::isUuid($taskId)) throw new CloudException('isolated_candidate_reference_required');
    $task = MachineProvisionTask::query()->findOrFail($taskId);
    $attemptId = $task->payload['coordinated_attempt'] ?? null;
    $attempt = is_string($attemptId) ? MachineFailoverAttempt::query()->find($attemptId) : null;
    $member = $attempt ? MachineFailoverExecutionMember::query()->find($attempt->execution_member_id) : null;
    $run = $member ? MachineFailoverExecution::query()->find($member->execution_id) : null;
    $policy = $member ? MachineFailoverPolicy::query()->find($member->policy_id) : null;
    if (!$attempt || !$member || !$run || !$policy || ($ids['execution_id'] ?? null) !== $run->id
        || ($ids['policy_id'] ?? null) !== $policy->id || ($ids['admin_id'] ?? null) !== $run->admin_id
        || $task->admin_id !== $run->admin_id || $task->machine_id !== $policy->machine_id
        || $task->account_id !== $attempt->account_id || $attempt->task_id !== $taskId || $member->attempt_id !== $attemptId
        || ($task->payload['request']['label'] ?? null) !== $scope['run_id'] . '-candidate'
        || !$task->dispatched_at || !$task->external_id || !$task->provider_created_at
        || $run->status !== 'running' || $run->deadline_at <= time()) throw new CloudException('isolated_candidate_binding_required');
    app(MachineFailoverNativeBridge::class)->assertTask($task);
    $callback = keli_live_consume_callback($task);
    app(MachineProvisionService::class)->readback($taskId);
    return ['candidate_process_only' => true, 'candidate_task_id' => $taskId,
        'callback_consumed' => $callback, 'native_phase_advanced' => false, 'dns_cutover_dispatched' => false];
}

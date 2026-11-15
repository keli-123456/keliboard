<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverAttempt;
use App\Models\MachineFailoverExecution;
use App\Models\MachineFailoverExecutionMember;
use App\Models\MachineProvisionTask;

final class MachineFailoverWorkflow
{
    public const READINESS = 'agent_readiness_v1';
    public const PROTOCOL = 'protocol_verification_v1';

    public static function configured(): string
    {
        return self::known(config('machine_failover.workflow', self::READINESS));
    }

    public static function forRun(MachineFailoverExecution $run): string
    {
        // A configuration change cannot lower the contract of an existing execution.
        return self::known($run->payload['workflow'] ?? self::PROTOCOL);
    }

    public static function forTask(MachineProvisionTask $task): string
    {
        $id = $task->payload['coordinated_attempt'] ?? null;
        if ($id === null) return self::PROTOCOL;
        $attempt = is_string($id) ? MachineFailoverAttempt::query()->find($id) : null;
        $member = $attempt ? MachineFailoverExecutionMember::query()->find($attempt->execution_member_id) : null;
        $run = $member ? MachineFailoverExecution::query()->find($member->execution_id) : null;
        if (!$attempt || !$run || $attempt->task_id !== $task->id || $run->admin_id !== $task->admin_id)
            throw new CloudException('native_lineage_changed');
        return self::forRun($run);
    }

    private static function known(mixed $value): string
    {
        if (!in_array($value, [self::READINESS, self::PROTOCOL], true)) throw new CloudException('invalid_native_workflow');
        return $value;
    }
}

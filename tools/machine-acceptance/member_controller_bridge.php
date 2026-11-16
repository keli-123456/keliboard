<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineCandidateCleanupClient;

/** Narrow RPC surface. All authority, durable journaling and task pins are local to the controller. */
final class MemberControllerBridge
{
    public function __construct(private array $scope, private MemberSourceRegistry $sources,
        private \Closure $guard, private \Closure $load, private \Closure $save) {}

    public function dispatch(array $request): array
    {
        $id = $request['request_id'] ?? null;
        $role = $request['role'] ?? null;
        $operation = $request['operation'] ?? null;
        if (array_diff(array_keys($request), ['schema', 'run_id', 'request_id', 'role', 'operation'])
            || ($request['schema'] ?? null) !== 1 || ($request['run_id'] ?? null) !== $this->scope['run_id']
            || !is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
            || !in_array($role, ['source-a', 'source-b', 'candidate-a1', 'candidate-a2'], true)
            || !in_array($operation, ['source-observe', 'source-register', 'task-inspect', 'task-delete'], true))
            throw new \RuntimeException('member_rpc_scope_invalid');
        ($this->guard)($operation, $role);
        $result = match ($operation) {
            'source-observe' => $this->sources->observe($role),
            'source-register' => $this->sources->register($role),
            'task-inspect' => $this->inspect($role),
            'task-delete' => $this->delete($role),
        };
        return ['schema' => 1, 'run_id' => $this->scope['run_id'], 'request_id' => $id,
            'role' => $role, 'operation' => $operation, 'ok' => true, 'result' => $result];
    }

    private function task(string $role): Task
    {
        $pin = $this->scope['tasks'][$role] ?? $this->scope['sources'][$role] ?? null;
        if (!$pin) throw new \RuntimeException('member_task_pin_missing');
        $task = Task::query()->findOrFail($pin['task_id']);
        foreach (['external_id', 'installation_id', 'account_revision', 'provider_created_at', 'marker', 'machine_id'] as $key)
            if (!isset($pin[$key]) || $task->{$key} !== $pin[$key]) throw new \RuntimeException('member_task_pin_changed');
        if ($task->account_id !== $this->scope['account_id']
            || in_array($task->external_id, $this->scope['baseline_ids'], true)) throw new \RuntimeException('member_task_outside_scope');
        return $task;
    }

    private function inspect(string $role): array
    {
        $task = $this->task($role);
        $remote = app(MachineCandidateCleanupClient::class)->inspect($task);
        return ['task_id' => $task->id, 'external_id' => $task->external_id,
            'installation_id' => $task->installation_id, 'absent' => $remote === null];
    }

    private function delete(string $role): array
    {
        $task = $this->task($role);
        $key = 'delete-' . $role;
        $identity = ['task_id' => $task->id, 'external_id' => $task->external_id, 'marker' => $task->marker];
        $intent = ($this->load)($key);
        if ($intent !== null && $intent !== $identity) throw new \RuntimeException('member_delete_intent_changed');
        $current = $this->inspect($role);
        if ($current['absent']) return $current + ['delete_dispatched' => false];
        // Any uncertain previous dispatch is read-only from here on, never repeated.
        if ($intent !== null) return $current + ['delete_dispatched' => false, 'reconciliation_required' => true];
        ($this->save)($key, $identity);
        app(MachineCandidateCleanupClient::class)->delete($task, fn () => ($this->guard)('task-delete', $role));
        return $current + ['delete_dispatched' => true];
    }
}

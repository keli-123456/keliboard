<?php
namespace App\Services\ServerMachine\Cloud;

use App\Jobs\MachineFailoverRunJob;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\MachineOperationNotifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineFailoverRunner
{
    private const EVENTS = 'v2_machine_failover_runtime_event';
    private const REASONS = ['cancelled_by_admin', 'permission_revoked', 'deadline', 'native_context_changed', 'config_changed',
        'native_lease_lost', 'native_stop_requested', 'runner_disabled', 'queue_unavailable', 'worker_interrupted', 'worker_busy', 'worker_failed', 'orchestration_disabled'];

    public function available(): bool
    {
        foreach (['v2_machine_failover_runtime', self::EVENTS, 'v2_machine_failover_execution', 'v2_machine_failover_execution_member',
            'v2_machine_failover_attempt', 'v2_machine_provision_task', 'v2_machine_dns_transition'] as $table) if (!Schema::hasTable($table)) return false;
        return true;
    }
    private function enabled(): bool
    {
        if (config('machine_failover.runner_enabled') !== true || !$this->available()) return false;
        try { app(MachineFailoverExecutionGate::class)->assertAllowed('runner'); return true; }
        catch (CloudException) { return false; }
    }
    public function enroll(string $id): void
    {
        if (!$this->enabled()) return;
        $run = Execution::query()->findOrFail($id); app(MachineFailoverServiceRegistry::class)->actor($run->admin_id);
        if ($run->status !== 'running') return;
        Runtime::query()->firstOrCreate(['execution_id' => $id]); $this->dispatch($id);
    }
    public function managed(Task $task): ?string
    {
        foreach (['v2_machine_failover_runtime', 'v2_machine_failover_attempt', 'v2_machine_failover_execution_member'] as $table)
            if (!Schema::hasTable($table)) return null;
        $attempt = Attempt::query()->where('task_id', $task->id)->first();
        if (!$attempt && isset($task->payload['coordinated_attempt'])) $attempt = Attempt::query()->find($task->payload['coordinated_attempt']);
        $id = $attempt ? Member::query()->find($attempt->execution_member_id)?->execution_id : null;
        return $id && Runtime::query()->whereKey($id)->exists() ? $id : null;
    }
    public function guard(string $id, bool $requireLease = true): void
    {
        if (!Schema::hasTable('v2_machine_failover_runtime')) return;
        $query = Runtime::query()->whereKey($id);
        $row = (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first();
        if (!$row) return;
        if (!$this->available()) throw new CloudException('runtime_migration_required');
        if ($row->stop_requested_at) throw new CloudException('native_stop_requested');
        if (!$requireLease) return;
        if (config('machine_failover.runner_enabled') !== true) throw new CloudException('runner_disabled');
        $lease = app()->bound(MachineFailoverLease::class) ? app(MachineFailoverLease::class) : null;
        if (!$lease || $lease->execution !== $id || !$this->owns($row, $lease->generation, $lease->ticket)) throw new CloudException('native_lease_lost');
    }
    public function workerOwns(Task $task): bool
    {
        $id = $this->managed($task); if (!$id) return true;
        if (!isset($task->payload['coordinated_attempt']) || !Attempt::query()->whereKey($task->payload['coordinated_attempt'])->where('task_id', $task->id)->exists()) return false;
        try { $this->guard($id); return true; } catch (CloudException) { return false; }
    }
    public function wakeTask(Task $task): bool
    {
        $id = $this->managed($task); if (!$id) return false;
        Runtime::query()->whereKey($id)->whereNull('stop_requested_at')->update(['next_check_at' => time()]);
        return true;
    }
    private function dispatch(string $id, bool $scheduled = false): bool
    {
        if (!$this->enabled()) return false;
        $ticket = bin2hex(random_bytes(24));
        $row = DB::transaction(function () use ($id, $ticket, $scheduled) {
            $row = Runtime::query()->whereKey($id)->lockForUpdate()->first(); $run = Execution::query()->find($id);
            if (!$row || !$run || $run->status !== 'running' || $row->stop_requested_at || !$scheduled && $row->next_check_at > time()
                || $row->state === 'processing' && $row->lease_until > time() || $row->state === 'queued' && $row->next_dispatch_at > time()) return null;
            $recover = in_array($row->state, ['processing', 'queued'], true);
            $row->fill(['state' => 'queued', 'generation' => $row->generation + 1, 'revision' => $row->revision + 1,
                'ticket_hash' => hash('sha256', $ticket), 'lease_until' => null, 'next_dispatch_at' => max(time(), $row->next_check_at) + 60,
                'reason' => $recover ? 'worker_interrupted' : null])->save();
            $this->event($row, $recover ? 'recovered' : 'queued', $row->reason); return $row;
        });
        if (!$row) return false;
        try { MachineFailoverRunJob::dispatch($id, $row->generation, $ticket)->delay(now()->addSeconds(max(0, $row->next_check_at - time())))->afterCommit(); }
        catch (\Throwable) {
            if (Runtime::query()->whereKey($id)->where('generation', $row->generation)->where('state', 'queued')->update(['reason' => 'queue_unavailable']))
                $this->event($row, 'queue_failed', 'queue_unavailable');
        }
        return true;
    }
    public function process(string $id, int $generation, string $ticket): void
    {
        if (!$this->enabled()) return;
        $lock = Cache::lock('machine-native:run:' . $id, 120);
        if (!$lock->get()) return;
        $previous = app()->bound(MachineFailoverLease::class) ? app(MachineFailoverLease::class) : null;
        try {
            $row = DB::transaction(function () use ($id, $generation, $ticket) {
                $row = Runtime::query()->whereKey($id)->lockForUpdate()->first(); $run = Execution::query()->find($id);
                if (!$row || !$run || $run->status !== 'running' || $row->stop_requested_at || $row->state !== 'queued' || $row->next_check_at > time()
                    || $row->generation !== $generation || !hash_equals($row->ticket_hash ?? '', hash('sha256', $ticket))) return null;
                $row->fill(['state' => 'processing', 'lease_until' => time() + 120, 'revision' => $row->revision + 1])->save();
                $this->event($row, 'claimed'); return $row;
            });
            if (!$row) return;
            app()->instance(MachineFailoverLease::class, new MachineFailoverLease($id, $generation, $ticket));
            $run = Execution::query()->findOrFail($id);
            $waiting = null;
            try {
                app(MachineFailoverServiceRegistry::class)->actor($run->admin_id);
                if ($run->deadline_at <= time()) throw new CloudException('deadline');
                app(MachineFailoverNativeBridge::class)->advance($id, $run->admin_id, true);
            } catch (LockTimeoutException) { $waiting = 'worker_busy'; }
            catch (CloudException $error) {
                if (!in_array($error->getMessage(), ['native_lease_lost', 'native_stop_requested'], true)) $this->requestStop($id, $this->reason($error->getMessage()), $generation, $ticket);
            } catch (\Throwable) { $this->requestStop($id, 'worker_failed', $generation, $ticket); }
            $current = Runtime::query()->find($id);
            if ($current?->stop_requested_at) $this->finishStop($id);
            elseif ($current && $this->owns($current, $generation, $ticket)) {
                $run->refresh();
                $state = $run->status === 'running' ? 'idle' : 'finished';
                DB::transaction(function () use ($id, $generation, $ticket, $state, $waiting) {
                    $row = Runtime::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                    if (!$this->owns($row, $generation, $ticket)) return;
                    $urgent = Member::query()->where('execution_id', $id)->where('phase', 'retry_ready')->exists()
                        || Member::query()->where('execution_id', $id)->where('phase', 'awaiting_dns')->get()->contains(function ($member) {
                            $attempt = $member->attempt_id ? Attempt::query()->find($member->attempt_id) : null;
                            $transition = isset($attempt?->payload['transition_id']) ? MachineDnsTransition::query()->find($attempt->payload['transition_id']) : null;
                            return !$transition || $transition->next_check_at <= time();
                        });
                    $row->fill(['state' => $state, 'ticket_hash' => null, 'lease_until' => null, 'revision' => $row->revision + 1,
                        'next_check_at' => time() + ($urgent && !$waiting ? 1 : 20), 'next_dispatch_at' => 0, 'reason' => $waiting])->save();
                    $this->event($row, $state === 'finished' ? 'finished' : 'yielded', $waiting);
                });
                if ($state === 'idle') $this->dispatch($id, true);
            }
        } finally {
            if ($previous) app()->instance(MachineFailoverLease::class, $previous); else app()->forgetInstance(MachineFailoverLease::class);
            $lock->release();
            if (Runtime::query()->whereKey($id)->whereNotNull('stop_requested_at')->where('state', '!=', 'stopped')->exists()) $this->settleStop($id);
        }
    }
    public function cursor(string $id, int $member): void
    {
        DB::transaction(function () use ($id, $member) {
            $this->guard($id);
            Runtime::query()->whereKey($id)->update(['last_member_id' => $member]);
        });
    }
    public function order(string $id, $members)
    {
        $last = $this->available() ? Runtime::query()->whereKey($id)->value('last_member_id') : 0;
        $ordered = $members->sortBy(fn ($m) => [$m->payload['priority'], $m->id])->values();
        $index = $ordered->search(fn ($m) => $m->id === (int) $last);
        $rotated = $index === false ? $ordered : $ordered->slice($index + 1)->concat($ordered->take($index + 1));
        return $rotated->sortBy(fn ($m) => in_array($m->phase, ['awaiting_dns', 'reconciling_dns'], true) ? 0 : 1);
    }
    public function advance(bool $stopsOnly = false): array
    {
        $result = ['enabled' => false, 'queued' => 0, 'stopped' => 0];
        if (!$this->available()) return $result;
        // Stopping local work is allowed even when every execution gate is closed.
        foreach (Runtime::query()->whereNotNull('stop_requested_at')->where('state', '!=', 'stopped')->orderBy('updated_at')->limit(20)->get() as $row)
            if ($this->settleStop($row->execution_id)) $result['stopped']++;
        if ($stopsOnly || !$this->enabled()) return $result;
        $result['enabled'] = true;
        foreach (Runtime::query()->whereNull('stop_requested_at')->whereIn('state', ['idle', 'queued', 'processing'])
            ->where('next_check_at', '<=', time())->orderBy('next_check_at')->orderBy('execution_id')->limit(20)->get() as $row) {
            $run = Execution::query()->find($row->execution_id);
            if (!$run || $run->status !== 'running') {
                // A crash after the run's terminal commit must not clog the due-ticket sweep.
                DB::transaction(function () use ($row) {
                    $current = Runtime::query()->whereKey($row->execution_id)->lockForUpdate()->first();
                    $run = Execution::query()->find($row->execution_id);
                    if (!$current || $current->stop_requested_at || !in_array($current->state, ['idle', 'queued', 'processing'], true)
                        || $run?->status === 'running') return;
                    $current->fill(['state' => 'finished', 'ticket_hash' => null, 'lease_until' => null,
                        'revision' => $current->revision + 1, 'reason' => $run ? null : 'native_context_changed'])->save();
                    $this->event($current, 'finished', $current->reason);
                });
                continue;
            }
            try { app(MachineFailoverServiceRegistry::class)->actor($run->admin_id); if ($run->deadline_at <= time()) throw new CloudException('deadline'); }
            catch (CloudException $e) { $this->requestStop($run->id, $this->reason($e->getMessage())); $this->settleStop($run->id); continue; }
            if ($this->dispatch($row->execution_id)) $result['queued']++;
        }
        return $result;
    }
    public function stop(string $id, int $revision, string $request, int $admin): array
    {
        app(MachineFailoverServiceRegistry::class)->actor($admin);
        if (!$this->available()) throw new CloudException('runtime_migration_required');
        if (!preg_match('/^[a-f0-9]{32}$/D', $request)) throw new CloudException('invalid_runtime_request');
        $key = hash('sha256', $admin . ':' . $request); $intent = hash('sha256', $id . ':' . $revision . ':stop');
        DB::transaction(function () use ($id, $revision, $admin, $key, $intent) {
            $run = Execution::query()->findOrFail($id);
            $prior = DB::table(self::EVENTS)->where('request_key', $key)->first();
            if ($prior) { if (!hash_equals($prior->intent_hash, $intent)) throw new CloudException('request_conflict'); return; }
            Runtime::query()->firstOrCreate(['execution_id' => $id]); $row = Runtime::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            // A consistent read could retain the snapshot from before the row-lock wait.
            $prior = DB::table(self::EVENTS)->where('request_key', $key)->lockForUpdate()->first();
            if ($prior) { if (!hash_equals($prior->intent_hash, $intent)) throw new CloudException('request_conflict'); return; }
            if ($row->revision !== $revision) throw new CloudException('runtime_changed');
            if ($run->status !== 'running' && !$row->stop_requested_at) throw new CloudException('runtime_not_running');
            if (!$row->stop_requested_at) {
                $row->fill(['state' => 'stopping', 'stop_requested_at' => time(), 'stop_requested_by' => $admin, 'reason' => 'cancelled_by_admin',
                    'revision' => $row->revision + 1, 'generation' => $row->generation + 1, 'ticket_hash' => null])->save();
            }
            $this->event($row, 'stop_requested', $row->reason, $admin, $key, $intent);
        });
        $this->settleStop($id); return $this->view(Execution::query()->findOrFail($id));
    }
    private function requestStop(string $id, string $reason, ?int $generation = null, ?string $ticket = null): void
    {
        DB::transaction(function () use ($id, $reason, $generation, $ticket) {
            $row = Runtime::query()->whereKey($id)->lockForUpdate()->firstOrFail(); if ($row->stop_requested_at) return;
            if ($generation !== null && !$this->owns($row, $generation, (string) $ticket)) return;
            $row->fill(['state' => 'stopping', 'stop_requested_at' => time(), 'reason' => $reason, 'revision' => $row->revision + 1,
                'generation' => $row->generation + 1, 'ticket_hash' => null])->save(); $this->event($row, 'stop_requested', $reason);
        });
    }
    private function settleStop(string $id): bool
    {
        $lock = Cache::lock('machine-native:run:' . $id, 120); if (!$lock->get()) return false;
        try { return $this->finishStop($id); } finally { $lock->release(); }
    }
    private function finishStop(string $id): bool
    {
        $done = DB::transaction(function () use ($id) {
            $runtime = Runtime::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (!$runtime->stop_requested_at || $runtime->state === 'stopped') return false;
            $run = Execution::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            foreach (Member::query()->where('execution_id', $id)->get() as $member) {
                if ($member->phase === 'completed') continue;
                $phase = 'cancelled';
                foreach (Attempt::query()->where('execution_member_id', $member->id)->get() as $attempt) {
                    $task = $attempt->task_id ? Task::query()->find($attempt->task_id) : null;
                    if ($task && !app(MachineFailoverNativeBridge::class)->bindingCurrent($attempt, $task)) { $phase = 'needs_review'; continue; }
                    if ($task && $attempt->status === 'rejected' && app(MachineFailoverNativeBridge::class)->definitelyRejected($task)) continue;
                    $dns = isset($attempt->payload['transition_id']) ? MachineDnsTransition::query()->find($attempt->payload['transition_id']) : null;
                    if ($dns && (!$task || !app(MachineFailoverNativeBridge::class)->transitionCurrent($attempt, $task, $dns))) { $phase = 'needs_review'; continue; }
                    if ($dns && !$dns->dispatched_at && $dns->state === 'prepared') $dns->fill(['state' => 'cancelled', 'reason' => 'native_stop_requested'])->save();
                    $uncertain = $dns?->dispatched_at || $task?->dispatched_at && !$task->external_id;
                    $held = $task !== null && $task->external_id !== null;
                    $next = $uncertain ? 'needs_review' : ($held ? 'retained' : 'cancelled');
                    if ($next === 'needs_review' || $phase !== 'needs_review' && $next === 'retained') $phase = $next;
                    if ($task && $task->status !== 'accepted') $task->fill(['status' => $next === 'cancelled' ? 'cancelled' : 'needs_review',
                        'reason' => 'native_stop_requested', 'finished_at' => time()])->save();
                    $attempt->fill(['status' => $next])->save();
                }
                $member->fill(['phase' => $phase, 'reason' => $runtime->reason])->save();
            }
            if ($run->status === 'running') $run->fill(['status' => 'stopped'])->save();
            $runtime->fill(['state' => 'stopped', 'lease_until' => null, 'ticket_hash' => null, 'revision' => $runtime->revision + 1])->save();
            $this->event($runtime, 'stopped', $runtime->reason); return true;
        });
        if ($done) app(MachineOperationNotifier::class)->failoverRun($id);
        return $done;
    }
    public function view(Execution $run): array
    {
        $available = $this->available(); $row = $available ? Runtime::query()->find($run->id) : null;
        return ['available' => $available, 'execution_id' => $run->id, 'revision' => $row?->revision ?? 0, 'state' => $row?->state ?? 'unmanaged',
            'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false, 'cloud_writes' => 0,
            'generation' => $row?->generation ?? 0, 'lease_until' => $row?->lease_until, 'next_check_at' => $row?->next_check_at,
            'stop_requested_at' => $row?->stop_requested_at, 'reason' => $row?->reason,
            'can_stop' => $available && $run->status === 'running' && !$row?->stop_requested_at,
            'events' => $available ? DB::table(self::EVENTS)->where('execution_id', $run->id)->orderByDesc('id')->limit(20)->get(['id', 'kind', 'reason', 'generation', 'created_at'])->all() : []];
    }
    private function owns(Runtime $row, int $generation, string $ticket): bool
    {
        return !$row->stop_requested_at && $row->state === 'processing' && $row->generation === $generation && $row->lease_until > time()
            && hash_equals($row->ticket_hash ?? '', hash('sha256', $ticket));
    }
    private function event(Runtime $row, string $kind, ?string $reason = null, ?int $admin = null, ?string $key = null, ?string $intent = null): void
    {
        DB::table(self::EVENTS)->insert(['execution_id' => $row->execution_id, 'generation' => $row->generation, 'kind' => $kind,
            'reason' => $reason, 'admin_id' => $admin, 'request_key' => $key, 'intent_hash' => $intent, 'created_at' => time()]);
    }
    private function reason(string $reason): string { return in_array($reason, self::REASONS, true) ? $reason : 'worker_failed'; }
}

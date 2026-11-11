<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverAction as Action;
use App\Models\MachineFailoverRun as Run;
use App\Models\MachineFailoverRunMember as RunMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MachineFailoverSimulation
{
    public function __construct(private MachineFailoverServiceRegistry $registry, private MachineFailoverStateMachine $machine,
        private MachineFailoverSimulationDriver $driver) {}

    public function startLive(): never { throw new CloudException('orchestration_disabled'); }

    public function start(int $service, int $revision, string $scenario, ?int $target, string $request, int $admin): array
    {
        $this->registry->actor($admin);
        if (!$this->registry->available()) throw new CloudException('migration_required');
        if (!in_array($scenario, MachineFailoverSimulationDriver::SCENARIOS, true) || !preg_match('/^[a-f0-9]{32}$/D', $request)) throw new CloudException('invalid_simulation');
        return $this->registry->locked(fn () => DB::transaction(function () use ($service, $revision, $scenario, $target, $request, $admin) {
            $this->registry->actor($admin); $key = hash('sha256', $admin . ':' . $request);
            $intent = hash('sha256', json_encode([$service, $revision, $scenario, $target], JSON_THROW_ON_ERROR));
            if ($prior = Run::query()->where('request_key', $key)->first()) {
                if (!hash_equals($prior->intent_hash, $intent)) throw new CloudException('request_conflict');
                return $this->report($prior->id, $admin);
            }
            $snapshot = $this->registry->snapshot($service, $revision);
            if (!empty($snapshot['dns_sets'])) throw new CloudException('dns_set_rehearsal_required');
            if ($target !== null && !in_array($target, array_column($snapshot['members'], 'id'), true)) throw new CloudException('member_missing');
            if (Run::query()->where('service_id', $service)->where('status', 'running')->exists()) throw new CloudException('run_active');
            if (Run::query()->count() >= 500 || Run::query()->where('service_id', $service)->count() >= 100) throw new CloudException('simulation_limit');
            $id = (string) Str::uuid(); $hash = $this->registry->fingerprint($snapshot);
            $run = Run::query()->create(['id' => $id, 'service_id' => $service, 'service_revision' => $revision, 'admin_id' => $admin,
                'request_key' => $key, 'intent_hash' => $intent, 'context_hash' => $hash, 'mode' => 'simulation', 'status' => 'running',
                'payload' => ['snapshot' => $snapshot, 'scenario' => $scenario, 'target' => $target]]);
            foreach ($snapshot['members'] as $index => $member) {
                $seed = hash('sha256', $id . ':' . $member['id']);
                $context = $member['policy'] + ['member_id' => $member['id'], 'provider' => $member['provider'], 'context_hash' => hash('sha256', $hash . ':' . $member['id']),
                    'source_installation_id' => substr(hash('sha256', 'source:' . $seed), 0, 32), 'source_ip' => '192.0.2.' . ($index + 1),
                    'candidate_id' => 'sim-' . substr($seed, 0, 24), 'candidate_installation_id' => substr($seed, 0, 32), 'candidate_ip' => '198.51.100.' . ($index + 1),
                    'binary_sha256' => hash('sha256', 'synthetic-binary:' . $seed), 'configuration_sha256' => hash('sha256', json_encode($member['policy'], JSON_THROW_ON_ERROR)),
                    'echo_sha256' => hash('sha256', 'synthetic-echo:' . $seed), 'dns_exists' => $member['dns_exists']];
                RunMember::query()->create(['run_id' => $run->id, 'member_id' => $member['id'], 'phase' => 'observing',
                    'payload' => ['context' => $context, 'name' => $member['name'], 'policy_id' => $member['policy_id'],
                        'state' => $this->machine->initial(), 'events' => [], 'round' => 0]]);
            }
            return $this->report($run->id, $admin);
        }));
    }

    public function advance(string $id, int $admin, int $ticks = 200): array
    {
        $this->registry->actor($admin);
        if ($ticks < 1 || $ticks > 200) throw new CloudException('invalid_simulation');
        return $this->registry->locked(function () use ($id, $admin, $ticks) {
            $run = Run::query()->findOrFail($id);
            if ($run->mode !== 'simulation') throw new CloudException('orchestration_disabled');
            for ($step = 0; $step < $ticks && $run->status === 'running'; $step++) {
                try {
                    $this->registry->actor($run->getRawOriginal('admin_id'));
                    $current = $this->registry->snapshot($run->service_id, $run->service_revision);
                    if (!hash_equals($run->context_hash, $this->registry->fingerprint($current))) throw new CloudException('context_changed');
                } catch (CloudException $error) { $this->invalidate($run, $error->getMessage()); break; }
                if ($run->tick >= 200) { $this->invalidate($run, 'simulation_deadline'); break; }
                $now = $run->created_at + $run->tick * 5;
                $rows = RunMember::query()->where('run_id', $id)->orderBy('id')->get();
                foreach ($rows as $row) $this->step($run, $row, $now);
                $run->tick++; $this->aggregate($run); $run->save();
            }
            return $this->report($id, $admin);
        });
    }

    private function step(Run $run, RunMember $row, int $now): void
    {
        $payload = $row->payload; $state = $payload['state']; $context = $payload['context'];
        if ($this->machine->terminal($state)) return;
        if (in_array($state['phase'], ['creating', 'switching_dns'], true)) {
            $priorIntent = Action::query()->where('run_id', $run->id)->where('member_id', $row->member_id)
                ->where('kind', MachineFailoverStateMachine::ACTIONS[$state['phase']])->exists();
            if ($priorIntent) $state = $this->machine->dispatched($state, MachineFailoverStateMachine::ACTIONS[$state['phase']]);
        }
        $scenario = $run->payload['target'] === null || $run->payload['target'] === $row->member_id ? $run->payload['scenario'] : 'healthy';
        try { $fact = $this->driver->source($context, $state, $scenario, $payload['round'], $now); }
        catch (\Throwable) { $fact = ['window_complete' => true]; }
        $state = $this->machine->source($state, $fact, $context, $now); $payload['round']++;
        if (!$this->machine->terminal($state) && $state['phase'] === 'queued') {
            $inflight = RunMember::query()->where('run_id', $run->id)->where('id', '!=', $row->id)
                ->whereIn('phase', array_keys(MachineFailoverStateMachine::ACTIONS))->count();
            if ($inflight < $run->payload['snapshot']['service']['max_parallel']) {
                $limits = $run->payload['snapshot']['service'];
                $reason = RunMember::query()->where('run_id', $run->id)->where('phase', 'needs_review')->exists() ? 'service_review_required'
                    : (!$context['isolation_confirmed'] ? 'isolation_confirmation_required'
                    : (!$context['dns_exists'] || !$context['dns_hostname'] ? 'dns_not_configured'
                    : ($run->attempts >= $limits['attempt_limit'] ? 'service_attempt_limit' : null)));
                DB::transaction(function () use ($reason, $run, $context, $row, $payload, $state, $now) {
                    if ($reason === null) { $run->attempts++; $run->save(); }
                    $next = $this->machine->reserve($state, $reason === null, $reason);
                    $this->saveMember($row, $payload, $next, $now);
                });
                return;
            }
        } elseif (!$this->machine->terminal($state) && isset(MachineFailoverStateMachine::ACTIONS[$state['phase']])) {
            $kind = MachineFailoverStateMachine::ACTIONS[$state['phase']];
            $action = Action::query()->where('run_id', $run->id)->where('member_id', $row->member_id)->where('kind', $kind)->first();
            if ($action && !$action->receipt && in_array($kind, ['create', 'switch_dns'], true)) {
                $state = $this->machine->dispatched($state, $kind);
                $fact = $this->unknown($context, $action->id, $now);
            } else {
                if (!$action) {
                    $action = Action::query()->create(['id' => (string) Str::uuid(), 'run_id' => $run->id,
                        'member_id' => $row->member_id, 'kind' => $kind, 'status' => 'dispatched', 'virtual_at' => $now]);
                    $state = $this->machine->dispatched($state, $kind);
                    $this->saveMember($row, $payload, $state, $now);
                }
                $fact = $action->receipt;
                if (!$fact) {
                    $limits = $run->payload['snapshot']['service'];
                    $remaining = 1 + $limits['attempt_limit'] - $run->attempts;
                    try { $fact = $this->driver->execute($kind, $context + ['credential_attempt_limit' => max(1, min(3, $remaining))], $state, $scenario, $action->id, $now); }
                    catch (\Throwable) { $fact = $this->unknown($context, $action->id, $now); }
                    $extra = $kind === 'create' ? max(0, count(is_array($fact['credential_attempts'] ?? null) ? $fact['credential_attempts'] : []) - 1) : 0;
                    if ($extra >= max(1, $remaining)) { $fact = $this->unknown($context, $action->id, $now); $extra = 0; }
                    DB::transaction(function () use ($action, $fact, $extra, $run, $context) {
                        if ($extra) { $run->attempts += $extra; $run->save(); }
                        $action->fill(['receipt' => $fact, 'status' => ($fact['status'] ?? null) === 'ok' ? 'confirmed' : 'uncertain'])->save();
                    });
                }
            }
            $state = $this->machine->receipt($state, $kind, $fact, $context, $action->id, $now);
        }
        $this->saveMember($row, $payload, $state, $now);
    }

    private function unknown(array $context, string $operation, int $now): array
    {
        return ['origin' => 'synthetic', 'context_hash' => $context['context_hash'], 'operation_id' => $operation, 'at' => $now, 'status' => 'unknown'];
    }
    private function saveMember(RunMember $row, array $payload, array $state, int $now): void
    {
        $prior = $row->payload['state'];
        if ($prior['phase'] !== $state['phase'] || $prior['reason'] !== $state['reason'] || $prior['failures'] !== $state['failures'] || $prior['baseline_at'] !== $state['baseline_at']) {
            $payload['events'] = array_slice([...$payload['events'], ['phase' => $state['phase'], 'reason' => $state['reason'],
                'failures' => $state['failures'], 'virtual_at' => $now]], -64);
        }
        $payload['state'] = $state;
        $row->fill(['phase' => $state['phase'], 'reason' => $state['reason'], 'payload' => $payload])->save();
    }
    private function invalidate(Run $run, string $reason): void
    {
        foreach (RunMember::query()->where('run_id', $run->id)->get() as $row) {
            $payload = $row->payload; $state = $payload['state'];
            if ($this->machine->terminal($state)) continue;
            $written = $state['create_dispatched'] || Action::query()->where('run_id', $run->id)->where('member_id', $row->member_id)
                ->whereIn('kind', ['create', 'switch_dns'])->exists();
            $state['phase'] = $written ? 'needs_review' : 'blocked'; $state['reason'] = $reason;
            $this->saveMember($row, $payload, $state, $run->created_at + $run->tick * 5);
        }
        $run->reason = $reason; $this->aggregate($run); $run->save();
    }
    private function aggregate(Run $run): void
    {
        $phases = RunMember::query()->where('run_id', $run->id)->pluck('phase')->all();
        if (array_diff($phases, MachineFailoverStateMachine::TERMINAL)) return;
        $run->status = in_array('needs_review', $phases, true) ? 'needs_review'
            : (array_intersect($phases, ['failed', 'blocked']) ? (in_array('completed', $phases, true) ? 'partial' : 'failed') : 'completed');
        $run->finished_at = time();
    }
    public function cancel(string $id, int $admin): array
    {
        $this->registry->actor($admin);
        return $this->registry->locked(function () use ($id, $admin) {
            $run = Run::query()->findOrFail($id);
            if ($run->mode !== 'simulation') throw new CloudException('orchestration_disabled');
            if ($run->status === 'running') {
                $this->invalidate($run, 'simulation_cancelled');
                if ($run->status !== 'needs_review') $run->fill(['status' => 'cancelled'])->save();
            }
            return $this->report($id, $admin);
        });
    }
    public function report(string $id, int $admin): array
    {
        $this->registry->actor($admin); $run = Run::query()->findOrFail($id);
        if ($run->mode !== 'simulation') throw new CloudException('orchestration_disabled');
        $attempts = Action::query()->where('run_id', $id)->where('kind', 'create')->get()->keyBy('member_id');
        $members = RunMember::query()->where('run_id', $id)->orderBy('id')->get()->map(function ($row) use ($attempts) {
            $payload = $row->payload; $state = $payload['state'];
            return ['member_id' => $row->member_id, 'name' => $payload['name'], 'policy_id' => $payload['policy_id'],
                'phase' => $row->phase, 'reason' => $row->reason, 'failures' => $state['failures'], 'events' => $payload['events'],
                'source_recovered' => $state['source_recovered'], 'synthetic_resource_retained' => $state['candidate'] !== null,
                'synthetic_candidate_ip' => $state['candidate']['ip'] ?? null,
                'credential_attempts' => array_map(fn ($attempt) => array_intersect_key($attempt, array_flip(['ordinal', 'credential', 'kind', 'action', 'reason', 'http_status'])),
                    array_slice($attempts->get($row->member_id)?->receipt['credential_attempts'] ?? [], 0, 3))];
        })->all();
        return $run->toArray() + ['simulation_only' => true, 'evidence_origin' => 'synthetic', 'execution_enabled' => false, 'dns_cutover' => false,
            'business_verified' => false, 'scenario' => $run->payload['scenario'], 'members' => $members,
            'actions' => Action::query()->where('run_id', $id)->orderBy('virtual_at')->orderBy('id')->limit(200)->get(['id', 'member_id', 'kind', 'status', 'virtual_at'])->toArray()];
    }

    public function resolve(int $service, string $request, int $admin): ?array
    {
        $this->registry->actor($admin);
        if (!preg_match('/^[a-f0-9]{32}$/D', $request)) throw new CloudException('invalid_simulation');
        $run = Run::query()->where('request_key', hash('sha256', $admin . ':' . $request))->first();
        if (!$run) return null;
        if ($run->service_id !== $service) throw new CloudException('request_conflict');
        return $this->report($run->id, $admin);
    }
}

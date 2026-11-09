<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as ExecutionMember;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineFailoverTriggerPreview as Preview;
use App\Models\ServerMachineRelease;
use Illuminate\Support\Facades\Cache;

// Preview is a proposal, not authority. No cloud/DNS transport or new queue lives here.
class MachineFailoverAdmission
{
    private const REASONS = ['orchestration_disabled', 'admission_disabled', 'admission_scope_invalid', 'admission_revoked',
        'admission_expired', 'config_changed', 'release_changed', 'permission_revoked', 'admission_unavailable',
        'native_run_active', 'native_stop_requested', 'native_intent_expired', 'admission_evidence_changed',
        'admission_budget_required', 'admission_provider_unsupported', 'admission_no_proposal', 'admission_busy'];

    public function __construct(private MachineFailoverServiceRegistry $registry, private MachineFailoverShadow $shadow,
        private MachineFailoverBudget $budget, private MachineFailoverTriggerPreview $preview) {}

    public function advance(int $limit = 5): array
    {
        $result = ['enabled' => config('machine_failover.admission_enabled') === true, 'checked' => 0,
            'started' => 0, 'resumed' => 0, 'blocked' => 0, 'errors' => 0, 'reason' => null, 'services' => []];
        if (!$result['enabled']) return $result;
        try {
            $this->gates(); $pins = $this->pins();
            if (!$this->preview->available() || !app(MachineFailoverNativeBridge::class)->available()
                || !app(MachineFailoverRunner::class)->available()) throw new CloudException('admission_unavailable');
        } catch (\Throwable $error) { $result['reason'] = $this->reason($error); return $result; }
        $lock = Cache::lock('machine-failover:admission-sweep', 60);
        if (!$lock->get()) { $result['reason'] = 'admission_busy'; return $result; }
        try {
            $cursor = (int) Cache::get('machine-failover:admission-cursor', 0);
            usort($pins, fn ($a, $b) => $a['service_id'] <=> $b['service_id']);
            $rows = [...array_filter($pins, fn ($p) => $p['service_id'] > $cursor), ...array_filter($pins, fn ($p) => $p['service_id'] <= $cursor)];
            $until = microtime(true) + 5;
            foreach (array_slice($rows, 0, max(1, min(10, $limit))) as $pin) {
                if (microtime(true) > $until) break;
                $serviceLock = Cache::lock('machine-failover:admission:' . $pin['service_id'], 120);
                try {
                    $outcome = $serviceLock->get(fn () => $this->admit($pin));
                    if (!is_array($outcome)) $outcome = ['state' => 'blocked', 'reason' => 'admission_busy'];
                } catch (\Throwable $error) {
                    $outcome = ['state' => 'blocked', 'reason' => $this->reason($error)];
                    if (!$error instanceof CloudException) $result['errors']++;
                }
                $result[$outcome['state']]++; $result['checked']++;
                $result['services'][] = ['service_id' => $pin['service_id']] + $outcome;
                Cache::put('machine-failover:admission-cursor', $pin['service_id'], 3600);
            }
        } finally { $lock->release(); }
        return $result;
    }

    private function admit(array $pin): array
    {
        $this->scope($pin);
        $active = Execution::query()->where('service_id', $pin['service_id'])->where('status', 'running')->limit(2)->get();
        if ($active->isNotEmpty()) {
            $run = $active->first();
            if ($active->count() !== 1 || !MachineIntentJson::same($run->payload['admission']['authorization'] ?? null, $pin))
                throw new CloudException('native_run_active');
            $this->assertRun($run);
            if ($run->deadline_at <= now()->getTimestamp()) throw new CloudException('native_intent_expired');
            $runtime = Runtime::query()->find($run->id);
            if ($runtime && ($runtime->stop_requested_at || !in_array($runtime->state, ['idle', 'queued', 'processing'], true)))
                throw new CloudException('native_stop_requested');
            // A commit followed by a crash/notification failure must recover this execution, not a fresh intent.
            app(MachineFailoverRunner::class)->enroll($run->id);
            return ['state' => 'resumed', 'execution_id' => $run->id];
        }
        $report = $this->shadow->report($pin['service_id'], $pin['service_revision'], $pin['admin_id']);
        $this->fresh($report); $proposals = [];
        foreach ($report['members'] as $member) {
            if ($member['decision'] !== 'would_replace' || $member['blockers'] !== []) continue;
            if (($member['candidate']['provider'] ?? null) !== 'linode') throw new CloudException('admission_provider_unsupported');
            $plan = $this->preview->qualifiedPlan($member);
            if (!$plan || ExecutionMember::query()->where('episode_key', $plan->evidence['episode_key'])->exists()) continue;
            $row = Preview::query()->where('service_id', $pin['service_id'])->where('member_id', $member['member_id'])
                ->where('episode_key', $plan->evidence['episode_key'])->whereNull('closed_at')->first();
            if (!$row) continue;
            $this->proposal($row, $pin, $member, $plan->id);
            $proposals[] = ['preview_id' => $row->id, 'preview_revision' => $row->revision,
                'plan_id' => $plan->id, 'episode_key' => $row->episode_key, 'planned_cents' => $row->planned_cents];
        }
        if (!$proposals) throw new CloudException('admission_no_proposal');
        $this->capacity($pin, $proposals);
        $admission = ['authorization' => $pin, 'proposals' => $proposals];
        $request = substr(hash_hmac('sha256', MachineIntentJson::encode(['automatic-admission-v1', $admission]), (string) config('app.key')), 0, 32);
        $result = app(MachineFailoverNativeBridge::class)->start($pin['service_id'], $pin['service_revision'],
            array_column($proposals, 'plan_id'), $pin['release']['id'], $request, $pin['admin_id'], $admission);
        return ['state' => 'started', 'execution_id' => $result['id']];
    }

    public function assertStart(array $admission, array $snapshot, array $release, array $episodes): void
    {
        $pin = $this->authorization($admission);
        if (!MachineIntentJson::same($snapshot, $this->scope($pin)) || !MachineIntentJson::same($release, $pin['release']))
            throw new CloudException('config_changed');
        $report = $this->shadow->report($pin['service_id'], $pin['service_revision'], $pin['admin_id']); $this->fresh($report);
        $actual = [];
        foreach ($admission['proposals'] as $proposal) {
            $row = Preview::query()->find($proposal['preview_id']);
            $member = collect($report['members'])->firstWhere('member_id', $row?->member_id);
            if (!$row || !$member || $row->revision !== $proposal['preview_revision'] || $row->planned_cents !== $proposal['planned_cents']
                || $row->episode_key !== $proposal['episode_key'] || $member['decision'] !== 'would_replace' || $member['blockers'] !== [])
                throw new CloudException('admission_evidence_changed');
            $this->proposal($row, $pin, $member, $proposal['plan_id']);
            $actual[$proposal['plan_id']] = $proposal['episode_key'];
        }
        if (!MachineIntentJson::same($actual, $episodes)) throw new CloudException('admission_evidence_changed');
        $this->capacity($pin, $admission['proposals']);
        $this->scope($pin);
    }

    public function assertRun(Execution $run): void
    {
        $admission = $run->payload['admission'] ?? null;
        if (!is_array($admission)) throw new CloudException('admission_revoked');
        $pin = $this->authorization($admission); $snapshot = $this->scope($pin);
        if ($run->service_id !== $pin['service_id'] || $run->service_revision !== $pin['service_revision'] || $run->admin_id !== $pin['admin_id']
            || !hash_equals($run->context_hash, $this->registry->fingerprint($snapshot)) || !MachineIntentJson::same($run->payload['release'], $pin['release']))
            throw new CloudException('config_changed');
        $episodes = ExecutionMember::query()->where('execution_id', $run->id)->pluck('episode_key', 'plan_id')->all();
        $expected = array_column($admission['proposals'], 'episode_key', 'plan_id');
        if (!MachineIntentJson::same($episodes, $expected)) throw new CloudException('admission_evidence_changed');
    }

    private function authorization(array $admission): array
    {
        if (count($admission) !== 2 || array_diff(array_keys($admission), ['authorization', 'proposals']) || !is_array($admission['authorization'] ?? null)
            || !is_array($admission['proposals']) || !array_is_list($admission['proposals'])
            || count($admission['proposals']) < 1 || count($admission['proposals']) > 10) throw new CloudException('admission_scope_invalid');
        $ids = []; $plans = []; $episodes = [];
        foreach ($admission['proposals'] as $row) {
            if (!is_array($row) || count($row) !== 5) throw new CloudException('admission_scope_invalid');
            foreach (['preview_id', 'preview_revision', 'plan_id', 'planned_cents'] as $key)
                if (!is_int($row[$key] ?? null) || $row[$key] < 1) throw new CloudException('admission_scope_invalid');
            if (!is_string($row['episode_key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $row['episode_key'])
                || isset($ids[$row['preview_id']]) || isset($plans[$row['plan_id']]) || isset($episodes[$row['episode_key']])) throw new CloudException('admission_scope_invalid');
            $ids[$row['preview_id']] = $plans[$row['plan_id']] = $episodes[$row['episode_key']] = true;
        }
        return $admission['authorization'];
    }

    private function scope(array $pin): array
    {
        $this->gates(); $pins = $this->pins();
        $stored = collect($pins)->firstWhere('service_id', $pin['service_id'] ?? null);
        if (!$stored || !MachineIntentJson::same($stored, $pin)) throw new CloudException('admission_revoked');
        $now = now()->getTimestamp();
        if ($pin['issued_at'] > $now || $pin['expires_at'] <= $now) throw new CloudException('admission_expired');
        $this->registry->actor($pin['admin_id']); $snapshot = $this->registry->snapshot($pin['service_id'], $pin['service_revision']);
        if (!hash_equals($pin['context_hash'], $this->registry->fingerprint($snapshot))) throw new CloudException('config_changed');
        foreach ($snapshot['members'] as $member) if ($member['provider'] !== 'linode') throw new CloudException('admission_provider_unsupported');
        $release = ServerMachineRelease::query()->find($pin['release']['id']);
        if (!$release || $release->status !== 'active' || !MachineIntentJson::same($release->only(array_keys($pin['release'])), $pin['release']))
            throw new CloudException('release_changed');
        return $snapshot;
    }

    private function proposal(Preview $row, array $pin, array $member, int $planId): void
    {
        $now = now()->getTimestamp(); $plan = $this->preview->qualifiedPlan($member);
        if ($row->service_id !== $pin['service_id'] || $row->service_revision !== $pin['service_revision']
            || $row->policy_id !== $member['policy_id'] || $row->policy_revision !== $member['policy_revision'] || $row->machine_id !== $member['machine_id']
            || $row->decision !== 'would_start' || $row->reasons !== [] || $row->closed_at !== null
            || $row->checked_at > $now || $row->checked_at < $now - 30 || $row->valid_until <= $now
            || !hash_equals($row->context_hash, $pin['context_hash']) || $row->source_checked_at !== $member['source']['checked_at']
            || !hash_equals($row->source_hash, hash('sha256', json_encode([$member['source']['installation_id'], $member['source']['ipv4']], JSON_THROW_ON_ERROR)))
            || !$plan || $plan->id !== $planId || $row->episode_key !== $plan->evidence['episode_key']
            || $row->planned_cents !== (int) round($member['budget']['monthly_reservation_usd'] * 100) || $row->planned_cents < 1)
            throw new CloudException('admission_evidence_changed');
    }

    private function capacity(array $pin, array $proposals): void
    {
        $cost = $this->budget->forecast($pin['service_id'], $pin['service_revision'], $pin['admin_id']);
        $now = now()->getTimestamp();
        if (($cost['state'] ?? null) !== 'verified' || !is_int($cost['available_cents'] ?? null)
            || !is_int($cost['checked_at'] ?? null) || $cost['checked_at'] > $now || $cost['checked_at'] < $now - 300
            || !is_int($cost['valid_until'] ?? null) || $cost['valid_until'] <= $now || $cost['valid_until'] > $cost['checked_at'] + 300
            || array_sum(array_column($proposals, 'planned_cents')) > $cost['available_cents']) throw new CloudException('admission_budget_required');
    }
    private function fresh(array $report): void
    {
        $now = now()->getTimestamp();
        if (($report['evidence_origin'] ?? null) !== 'panel_cache' || !is_int($report['checked_at'] ?? null)
            || $report['checked_at'] > $now || $report['checked_at'] < $now - 30 || ($report['valid_until'] ?? 0) <= $now)
            throw new CloudException('admission_evidence_changed');
    }
    private function gates(): void
    {
        if (config('machine_failover.admission_enabled') !== true) throw new CloudException('admission_disabled');
        app(MachineFailoverExecutionGate::class)->assertAllowed('admission');
        foreach (['machine_failover.observe_enabled', 'machine_failover.preview_enabled', 'machine_failover.runner_enabled',
            'machine_failover.allow_replacement', 'machine_provision.allow_live', 'machine_acceptance.allow_verification', 'machine_acceptance.allow_dns'] as $key)
            if (config($key) !== true) throw new CloudException('admission_disabled');
        if (!is_string(config('app.key')) || config('app.key') === '') throw new CloudException('admission_scope_invalid');
    }
    private function pins(): array
    {
        $pins = config('machine_failover.admission_authorizations', []); $seen = [];
        if (!is_array($pins) || !array_is_list($pins) || count($pins) > 50) throw new CloudException('admission_scope_invalid');
        foreach ($pins as $pin) {
            if (!is_array($pin) || count($pin) !== 7) throw new CloudException('admission_scope_invalid');
            foreach (['service_id', 'service_revision', 'admin_id', 'issued_at', 'expires_at'] as $key)
                if (!is_int($pin[$key] ?? null) || $pin[$key] < 1) throw new CloudException('admission_scope_invalid');
            if (isset($seen[$pin['service_id']]) || $pin['expires_at'] <= $pin['issued_at'] || $pin['expires_at'] - $pin['issued_at'] > 86400
                || !is_string($pin['context_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $pin['context_hash'])
                || !is_array($pin['release'] ?? null) || count($pin['release']) !== 6) throw new CloudException('admission_scope_invalid');
            $release = $pin['release'];
            if (!is_int($release['id'] ?? null) || $release['id'] < 1 || ($release['component'] ?? null) !== 'kelinode-rs'
                || ($release['platform'] ?? null) !== 'linux-x86_64' || !is_string($release['version'] ?? null) || trim($release['version']) === ''
                || strlen($release['version']) > 120) throw new CloudException('admission_scope_invalid');
            foreach (['sha256', 'binary_sha256'] as $key) if (!is_string($release[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $release[$key]))
                throw new CloudException('admission_scope_invalid');
            $seen[$pin['service_id']] = true;
        }
        return $pins;
    }
    private function reason(\Throwable $error): string
    {
        return $error instanceof CloudException && in_array($error->getMessage(), self::REASONS, true) ? $error->getMessage() : 'admission_unavailable';
    }
}

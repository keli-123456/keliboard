<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverBudgetRelease as Release;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverReviewAction as Action;
use App\Models\MachineFailoverReviewCheck as Check;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Review records metadata only. No method dispatches a task, powers a VM or writes DNS.
class MachineFailoverReview
{
    private bool $ready = false;
    public function __construct(private MachineFailoverServiceRegistry $registry, private LinodeFailoverCostClient $cloud) {}

    public function available(): bool
    {
        if ($this->ready) return true;
        foreach (['review_check', 'review_action', 'budget_release', 'execution_member', 'attempt'] as $name)
            if (!Schema::hasTable('v2_machine_failover_' . $name)) return false;
        return $this->ready = true;
    }
    private function subject(?string $attemptId, ?int $memberId, int $admin): array
    {
        $this->registry->actor($admin);
        if (!$this->available()) throw new CloudException('review_migration_required');
        if (($attemptId === null) === ($memberId === null)) throw new CloudException('invalid_review_subject');
        $attempt = $attemptId ? Attempt::query()->findOrFail($attemptId) : null;
        $member = Member::query()->findOrFail($attempt?->execution_member_id ?? $memberId);
        $run = Execution::query()->findOrFail($member->execution_id);
        if (!in_array($run->status, ['needs_review', 'retained', 'cancelled', 'completed', 'stopped'], true)
            || !in_array($member->phase, ['needs_review', 'retained', 'cancelled', 'completed'], true)) throw new CloudException('review_not_stopped');
        if (!$attempt && Attempt::query()->where('execution_member_id', $member->id)->exists()) throw new CloudException('invalid_review_subject');
        $task = $attempt?->task_id ? Task::query()->find($attempt->task_id) : null;
        if ($attempt?->task_id && (!$task || !app(MachineFailoverNativeBridge::class)->bindingCurrent($attempt, $task))) throw new CloudException('native_lineage_changed');
        return [$member, $attempt, $task, $run];
    }
    private function context(array $subject): string
    {
        [$member, $attempt, $task, $run] = $subject;
        $account = $task ? Account::query()->find($task->account_id) : null;
        $machine = $task ? ServerMachine::query()->find($task->machine_id) : null;
        $health = app(MachineCloudCredentialHealth::class);
        return $this->hash([$member->only(['id', 'phase', 'attempt_id', 'policy_revision']), $run->only(['id', 'status', 'service_revision']),
            $attempt?->only(['id', 'status', 'reserved_cents', 'account_revision']), $task ? app(MachineFailoverBudget::class)->taskIdentity($task) : null,
            $task?->only(['status', 'dispatched_at', 'bootstrap_state', 'evidence']), $machine ? hash('sha256', $machine->token) : null,
            $account?->revision, $account ? $health->credentialKey($account) : null, $account ? $health->identityKey($account) : null,
            $attempt ? Release::query()->where('attempt_id', $attempt->id)->value('id') : null,
            $this->actions($member->id, $attempt?->id)->max('id')]);
    }
    private function actions(int $member, ?string $attempt)
    {
        return Action::query()->where('member_id', $member)->where('attempt_id', $attempt);
    }
    public function summary(int $member, ?string $attempt): array
    {
        if (!$this->available()) return ['available' => false, 'closed' => false, 'archived' => false, 'closed_at' => null];
        $close = $this->actions($member, $attempt)->where('operation', 'close')->first();
        $archive = $this->actions($member, $attempt)->whereIn('operation', ['archive', 'restore'])->latest('id')->first();
        return ['available' => true, 'closed' => $close !== null, 'closed_at' => $close?->created_at,
            'resolution' => $close?->payload['resolution'] ?? null, 'archived' => $archive?->operation === 'archive'];
    }
    public function read(?string $attemptId, ?int $memberId, int $admin): array
    {
        $subject = $this->subject($attemptId, $memberId, $admin); $context = $this->context($subject); [, , $task] = $subject;
        $result = $task ? Cache::lock('machine-cloud:account:' . $task->account_id, 120)->block(2, fn () => $this->inspect($subject))
            : ['state' => $subject[1] ? 'not_created' : 'no_intent', 'candidate' => null];
        return $this->registry->locked(function () use ($attemptId, $memberId, $admin, $context, $result) {
            $subject = $this->subject($attemptId, $memberId, $admin);
            if (!hash_equals($context, $this->context($subject))) throw new CloudException('review_context_changed');
            [$member, $attempt] = $subject;
            $check = Check::query()->create(['id' => (string) Str::uuid(), 'member_id' => $member->id, 'attempt_id' => $attempt?->id,
                'admin_id' => $admin, 'context_hash' => $context, 'payload' => $result, 'checked_at' => time(), 'valid_until' => time() + 120]);
            return $this->view($check, $subject);
        });
    }
    private function inspect(array $subject): array
    {
        [, $attempt, $task] = $subject;
        if (!$task || (!$task->dispatched_at && !$task->external_id && $task->bootstrap_state === null)
            || app(MachineFailoverNativeBridge::class)->definitelyRejected($task)) return ['state' => 'not_created', 'candidate' => null];
        $account = Account::query()->find($task->account_id);
        if (!$account || $account->provider !== 'linode' || $account->revision !== $task->account_revision) throw new CloudException('account_changed');
        if (!$this->cloud->authority($account)) throw new CloudException('cost_scope_unknown');
        $inventory = $this->cloud->completeInventory($account); $request = $task->payload['request']; $found = [];
        foreach ($inventory as $row) {
            if ($task->external_id ? $row['external_id'] !== $task->external_id
                : ($row['label'] !== ($request['label'] ?? null) || !in_array($task->marker, $row['tags'], true))) continue;
            $created = strtotime($row['provider_created_at']);
            if ($row['label'] !== ($request['label'] ?? null) || !in_array($task->marker, $row['tags'], true)
                || $row['region'] !== ($request['region'] ?? null) || $row['size'] !== ($request['type'] ?? null)
                || $row['image'] !== ($request['image'] ?? null) || $created < $task->dispatched_at - 30 || $created > $task->dispatched_at + 1230
                || ($task->provider_created_at && $task->provider_created_at !== $row['provider_created_at'])) throw new CloudException('review_identity_mismatch');
            $found[] = array_intersect_key($row, array_flip(['external_id', 'provider_created_at', 'public_ips', 'label', 'region', 'size', 'image']));
        }
        if (!$this->cloud->authority($account) || $account->fresh()?->revision !== $account->revision) throw new CloudException('cost_scope_unknown');
        if (count($found) > 1) throw new CloudException('duplicate_candidates');
        return ['state' => $found ? 'found' : ($task->external_id ? 'absent' : 'unresolved'), 'candidate' => $found[0] ?? null];
    }
    private function view(Check $check, array $subject): array
    {
        [$member, $attempt, $task] = $subject; $state = $check->payload['state'];
        $released = $attempt ? Release::query()->where('attempt_id', $attempt->id)->exists() : false;
        $claimed = $state === 'found' && $task?->external_id === $check->payload['candidate']['external_id'];
        $summary = $this->summary($member->id, $attempt?->id);
        return ['check_id' => $check->id, 'member_id' => $member->id, 'attempt_id' => $attempt?->id, 'state' => $state,
            'external_id' => $check->payload['candidate']['external_id'] ?? $task?->external_id, 'checked_at' => $check->checked_at,
            'valid_until' => $check->valid_until, 'can_claim' => !$summary['closed'] && !$released && $state === 'found' && !$claimed,
            'can_close' => !$summary['closed'] && ($state === 'no_intent' || $claimed || ($released && in_array($state, ['not_created', 'absent'], true))),
            'closed' => $summary['closed'], 'archived' => $summary['archived'],
            'cloud_writes' => 0, 'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false];
    }
    public function act(string $checkId, string $operation, string $request, int $admin): array
    {
        $this->registry->actor($admin);
        if (!$this->available() || !in_array($operation, ['claim', 'close', 'restore'], true) || !preg_match('/^[a-f0-9]{32}$/D', $request)) throw new CloudException('invalid_review_request');
        $initial = Check::query()->findOrFail($checkId);
        $task = $initial->attempt_id ? Task::query()->find(Attempt::query()->findOrFail($initial->attempt_id)->task_id) : null;
        return $this->registry->locked(fn () => Cache::lock('machine-cloud:quota:linode', 120)->block(2,
            fn () => Cache::lock('machine-cloud:account:' . ($task?->account_id ?? 'review-empty'), 120)->block(2,
                fn () => DB::transaction(function () use ($checkId, $operation, $request, $admin) {
                    $this->registry->actor($admin); $check = Check::query()->whereKey($checkId)->lockForUpdate()->firstOrFail();
                    $key = hash('sha256', $admin . ':' . $request); $intent = $this->hash([$checkId, $operation]);
                    if ($prior = Action::query()->where('request_key', $key)->first()) {
                        if (($prior->payload['intent'] ?? null) !== $intent) throw new CloudException('request_conflict');
                        return $prior->payload['result'];
                    }
                    $subject = $this->subject($check->attempt_id, $check->attempt_id ? null : $check->member_id, $admin);
                    [$member, $attempt, $task] = $subject;
                    $latest = Check::query()->where('member_id', $member->id)->where('attempt_id', $attempt?->id)->orderByDesc('sequence')->first();
                    if ($check->admin_id !== $admin || $check->valid_until <= time() || $latest?->id !== $checkId
                        || !hash_equals($check->context_hash, $this->context($subject))) throw new CloudException('review_proof_expired');
                    $view = $this->view($check, $subject); $resolution = null;
                    if ($operation === 'restore') {
                        if (!$view['archived']) throw new CloudException('review_not_archived');
                    } else {
                        if (!$view['can_' . $operation]) throw new CloudException('review_action_blocked');
                        if ($check->payload['state'] === 'found' || $check->payload['state'] === 'absent') {
                            $again = $this->inspect($subject);
                            if ($this->hash($again) !== $this->hash($check->payload) || !hash_equals($check->context_hash, $this->context($subject))) throw new CloudException('review_context_changed');
                        }
                        if ($check->fresh()?->valid_until <= time()) throw new CloudException('review_proof_expired');
                        $this->registry->actor($admin);
                        if ($operation === 'claim') {
                            $candidate = $check->payload['candidate'];
                            $account = Account::query()->findOrFail($task->account_id);
                            $aliases = app(MachineCloudCredentialHealth::class)->sameAccountIds($account);
                            if (Task::query()->whereIn('account_id', $aliases)->where('external_id', $candidate['external_id'])->where('id', '!=', $task->id)->exists()) throw new CloudException('duplicate_candidates');
                            $task->fill(['external_id' => $candidate['external_id'], 'provider_created_at' => $candidate['provider_created_at'],
                                'public_ips' => $candidate['public_ips'], 'status' => 'needs_review', 'reason' => 'review_claimed', 'finished_at' => time()])->save();
                        } else {
                            $resolution = $check->payload['state'] === 'found' ? 'retained' : ($attempt ? 'released' : 'no_intent');
                            if ($resolution === 'released' && $task) app(MachineFailoverBudget::class)->requireUnused($task);
                        }
                    }
                    $result = ['operation' => $operation, 'attempt_id' => $attempt?->id, 'member_id' => $member->id,
                        'resolution' => $resolution, 'cloud_writes' => 0, 'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false];
                    Action::query()->create(['member_id' => $member->id, 'attempt_id' => $attempt?->id, 'admin_id' => $admin, 'operation' => $operation,
                        'request_key' => $key, 'payload' => ['intent' => $intent, 'check_id' => $checkId, 'result' => $result, 'resolution' => $resolution]]);
                    if ($operation === 'close' && $resolution === 'released' && $task) {
                        $release = Release::query()->where('attempt_id', $attempt->id)->firstOrFail();
                        Action::query()->create(['member_id' => $member->id, 'attempt_id' => $attempt->id, 'admin_id' => $admin, 'operation' => 'archive',
                            'request_key' => hash('sha256', $key . ':archive'), 'payload' => ['task_identity' => app(MachineFailoverBudget::class)->taskIdentity($task),
                                'release_id' => $release->id, 'proof_kind' => $release->proof_kind]]);
                    }
                    return $result;
                }))));
    }
    private function hash(mixed $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
}

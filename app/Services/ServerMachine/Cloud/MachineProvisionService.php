<?php
namespace App\Services\ServerMachine\Cloud;

use App\Jobs\MachineProvisionJob;
use App\Models\MachineCloudAccount;
use App\Models\MachineCloudResource;
use App\Models\MachineProvisionTask as Task;
use App\Models\MachineReplacementTask;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MachineProvisionService
{
    private const REASONS = ['provisioning_disabled', 'test_machine_not_allowed', 'budget_required', 'permission_revoked',
        'source_changed', 'account_changed', 'provider_mismatch', 'provider_forbidden', 'provider_rate_limited', 'provider_rejected',
        'provider_unavailable', 'provider_invalid', 'bootstrap_unsupported', 'account_backups_enabled', 'budget_exceeded', 'cost_price_unverified',
        'outcome_uncertain', 'reconcile_incomplete', 'duplicate_candidates', 'identity_changed', 'initialization_failed',
        'reports_timeout', 'create_not_resolved', 'deadline', 'queue_unavailable', 'cancelled_by_admin', 'internal_failure',
        'panel_origin_changed', 'resource_missing', 'replacement_disabled', 'replacement_not_allowed',
        'replacement_evidence_changed', 'replacement_guard_failed', 'replacement_cooldown', 'source_recovered',
        'replacement_episode_used', 'ownership_mismatch', 'config_changed', 'daily_budget', 'task_active',
        'release_changed', 'isolated_context_changed', 'isolated_scope_changed', 'isolation_confirmation_required', 'credential_group_changed',
        'credential_health_blocked', 'credential_health_unavailable', 'credential_identity_changed',
        'orchestration_disabled', 'rotation_disabled', 'native_lineage_changed', 'native_context_changed', 'native_intent_expired', 'budget_released', 'review_hold',
        'bootstrap_too_large', 'invalid_bootstrap_identity', 'bootstrap_compression_unavailable'];

    public function __construct(private MachineProvisionProviders $provider, private ProvisionBootstrap $bootstrap,
        private MachineCloudHealth $health, private MachineAddressInventory $addresses) {}

    public function available(): bool
    {
        return Schema::hasTable('v2_machine_provision_task') && Schema::hasTable('v2_machine_provision_step');
    }

    public function overview(): array
    {
        return ['available' => $this->available(), 'live_enabled' => config('machine_provision.allow_live') === true,
            'automatic' => false, 'dns_cutover' => false, 'delete_strategy' => 'keep',
            'tasks' => $this->available() ? Task::query()->orderByDesc('created_at')->limit(100)->get()->map(fn ($task) => $this->present($task)) : []];
    }

    public function preview(array $input): array
    {
        $plan = ProvisionPlan::normalize($input);
        $this->origin($plan);
        if (isset($plan['credential_pool'])) app(MachineFailoverCredentials::class)->assertPlan($plan);
        $machine = ServerMachine::query()->findOrFail($plan['machine_id']);
        $account = MachineCloudAccount::query()->findOrFail($plan['account_id']);
        if (!$machine->is_active) throw new CloudException('invalid_plan');
        $provider = $this->provider->client($account, $plan); $id = (string) Str::uuid();
        $data = $this->bootstrap->build($machine, $plan['panel_origin'], $id, bin2hex(random_bytes(16)), bin2hex(random_bytes(32)));
        $request = $provider->buildRequest($plan, $id, 'keli-create-' . bin2hex(random_bytes(12)), $data);
        if ($account->provider !== 'linode') $data = $account->provider === 'vultr' ? base64_decode($request['user_data'], true) : $request['user_data'];
        return ['plan' => ProvisionPlan::summary($plan), 'bootstrap_bytes' => strlen($data),
            'live_enabled' => config('machine_provision.allow_live') === true, 'provider_checked' => false,
            'monetary_gate' => false, 'script_exported' => false, 'takeover_performed' => false];
    }

    public function queue(array $input, string $requestId, int $admin): array
    {
        return $this->enqueue($input, $requestId, $admin);
    }

    public function queueReplacement(array $input, string $requestId, int $admin, int $planId): array
    {
        return $this->enqueue($input, $requestId, $admin, $planId);
    }

    public function queueIsolated(array $input, string $requestId, int $admin, array $intent): array
    {
        return $this->enqueue($input, $requestId, $admin, null, $intent);
    }

    public function queueCoordinated(array $input, string $requestId, int $admin, string $attemptId): array
    {
        app(MachineFailoverExecutionGate::class)->assertAllowed('queue');
        return $this->enqueue($input, $requestId, $admin, null, null, $attemptId);
    }

    private function enqueue(array $input, string $requestId, int $admin, ?int $replacementPlan = null, ?array $isolated = null, ?string $attemptId = null): array
    {
        $this->gate();
        if (!$this->available()) throw new CloudException('migration_required');
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) throw new CloudException('invalid_request_id');
        $plan = ProvisionPlan::normalize($input);
        $this->origin($plan);
        if ($plan['source_ref']) throw new CloudException('source_review_required');
        if (isset($plan['credential_pool']) && $replacementPlan === null && $isolated === null && $attemptId === null) throw new CloudException('invalid_plan');
        return Cache::lock('machine-provision:slot:' . hash('sha256', $plan['machine_id'] . ':' . $plan['slot']), 120)
            ->block(2, fn () => $this->quotaLocked($plan['provider'] ?? 'linode', fn () => $this->locked($plan['account_id'], function () use ($plan, $requestId, $admin, $replacementPlan, $isolated, $attemptId) {
            $this->actor($admin);
            $this->testMachine($plan['machine_id']);
            $key = hash('sha256', $admin . ':' . $requestId);
            $hash = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
            if ($isolated !== null) $hash = hash('sha256', $hash . ':' . json_encode($isolated, JSON_THROW_ON_ERROR));
            $attempt = $attemptId === null ? null : \App\Models\MachineFailoverAttempt::query()->findOrFail($attemptId);
            if ($attempt) {
                app(MachineFailoverNativeBridge::class)->assertAttempt($attempt);
                $member = \App\Models\MachineFailoverExecutionMember::query()->findOrFail($attempt->execution_member_id);
                $execution = \App\Models\MachineFailoverExecution::query()->findOrFail($member->execution_id);
                if ($attempt->request_id !== $requestId || !MachineIntentJson::same(ProvisionPlan::normalize($attempt->payload['plan']), $plan) || $execution->admin_id !== $admin) throw new CloudException('native_lineage_changed');
                $hash = hash('sha256', MachineIntentJson::encode($plan) . ':' . $attempt->id);
            }
            if ($replacementPlan !== null) {
                $source = \App\Models\MachineFailoverPlan::query()->findOrFail($replacementPlan);
                app(MachineReplacementService::class)->gate($source->policy_id);
                $hash = hash('sha256', $hash . ':' . $replacementPlan);
            }
            if ($existing = Task::query()->where('request_key', $key)->first()) {
                if (!hash_equals($existing->plan_hash, $hash)) throw new CloudException('request_conflict');
                return $this->present($existing);
            }
            $account = MachineCloudAccount::query()->findOrFail($plan['account_id']);
            $health = app(MachineCloudCredentialHealth::class); $health->assertEligible($account, $plan);
            $accountIds = $health->sameAccountIds($account);
            $machine = ServerMachine::query()->findOrFail($plan['machine_id']);
            if ($account->provider !== ($plan['provider'] ?? 'linode') || !$machine->is_active) throw new CloudException('invalid_plan');
            $provider = $this->provider->client($account, $plan);
            if (isset($plan['credential_pool'])) app(MachineFailoverCredentials::class)->assertPlan($plan);
            $replacement = $replacementPlan === null ? null : app(MachineReplacementService::class)->validate($replacementPlan, $plan);
            if ($attempt) app(MachineReplacementService::class)->validate($member->plan_id, $plan, null, $attempt);
            if ($replacementPlan === null && $attempt === null && Schema::hasTable('v2_machine_replacement_task')
                && \App\Models\MachineFailoverPolicy::query()->where('slot', $plan['slot'])
                    ->whereIn('id', \App\Models\MachineOwnedInstance::query()->whereNotNull('active_policy_id')->select('active_policy_id'))->exists()) {
                throw new CloudException('replacement_required');
            }
            if (Task::query()->where('machine_id', $machine->id)->where('slot', $plan['slot'])
                ->whereNotIn('status', ['accepted', 'failed', 'cancelled'])->exists()) throw new CloudException('task_active');
            if (Task::query()->whereIn('account_id', $accountIds)->whereNotIn('status', ['accepted', 'failed', 'cancelled'])->exists()
                || \App\Models\MachineCloudTask::query()->whereIn('resource_id', MachineCloudResource::query()->whereIn('account_id', $accountIds)->select('id'))
                    ->whereNotIn('status', \App\Models\MachineCloudTask::TERMINAL)->exists()) throw new CloudException('task_active');
            if (Task::query()->whereIn('account_id', $accountIds)->where('created_at', '>=', time() - 86400)->count()
                >= max(1, min(2, (int) config('machine_provision.daily_limit_per_account', 2)))) throw new CloudException('daily_budget');
            $id = (string) Str::uuid(); $installation = bin2hex(random_bytes(16)); $secret = bin2hex(random_bytes(32));
            $marker = 'keli-create-' . bin2hex(random_bytes(12));
            $data = $this->bootstrap->build($machine, $plan['panel_origin'], $id, $installation, $secret);
            $request = $provider->buildRequest($plan, $id, $marker, $data);
            $task = DB::transaction(function () use ($id, $key, $admin, $account, $machine, $plan, $hash, $installation, $secret, $marker, $request, $replacement, $isolated, $attempt) {
                $replacementId = $replacement === null ? null : MachineReplacementTask::query()->create($replacement + [
                    'task_id' => $id, 'status' => 'queued', 'notice_event' => (string) Str::uuid(),
                    'steps' => [['state' => 'queued', 'reason' => null, 'created_at' => time()]],
                ])->id;
                $attributes = ['id' => $id, 'request_key' => $key, 'admin_id' => $admin,
                    'account_id' => $account->id, 'account_revision' => $account->revision, 'machine_id' => $machine->id,
                    'machine_authority' => hash('sha256', (string) $machine->token), 'plan_hash' => $hash, 'slot' => $plan['slot'],
                    'installation_id' => $installation, 'callback_hash' => hash('sha256', $secret), 'marker' => $marker,
                    'payload' => ['plan' => $plan, 'request' => $request] + ($isolated === null ? [] : ['isolated_acceptance' => $isolated])
                        + ($attempt === null ? [] : ['coordinated_attempt' => $attempt->id]), 'status' => 'queued',
                    'deadline_at' => time() + max(120, min(1200, (int) config('machine_provision.timeout_seconds', 1200)))];
                if ($replacementId !== null) $attributes['replacement_id'] = $replacementId;
                $task = Task::query()->create($attributes);
                if (!$task->exists) throw new CloudException('internal_failure');
                if ($isolated !== null) app(MachineIsolatedAcceptance::class)->assertTask($task);
                if ($attempt) app(MachineFailoverNativeBridge::class)->bind($attempt, $task);
                $this->move($task, 'queued');
                return $task;
            });
            $this->dispatch($task);
            return $this->present($task->fresh());
        })));
    }

    public function process(string $id): void
    {
        if (!$this->available() || !($initial = Task::query()->find($id)) || in_array($initial->status, Task::TERMINAL, true)) return;
        if (!app(MachineFailoverRunner::class)->workerOwns($initial)) return;
        $this->quotaLocked($initial->payload['plan']['provider'] ?? 'linode', fn () => $this->locked($initial->account_id, function () use ($id) {
            $task = Task::query()->find($id);
            if (!$task || in_array($task->status, Task::TERMINAL, true) || $task->next_check_at > time()) return;
            try {
                $this->gate(); $this->actor($task->admin_id); $this->testMachine($task->machine_id);
                if (isset($task->payload['coordinated_attempt'])) app(MachineFailoverNativeBridge::class)->assertTask($task);
                if ($task->replacement_id) app(MachineReplacementService::class)->assertDispatch($task);
                $account = MachineCloudAccount::query()->find($task->account_id);
                $machine = ServerMachine::query()->find($task->machine_id);
                if (!$account || $account->revision !== $task->account_revision) throw new CloudException('account_changed');
                if (!$machine || !$machine->is_active || !hash_equals($task->machine_authority, hash('sha256', (string) $machine->token))) throw new CloudException('source_changed');
                if ($task->deadline_at <= time()) {
                    $reason = !$task->dispatched_at ? 'deadline' : ($task->external_id ? 'reports_timeout' : 'create_not_resolved');
                    $this->move($task, $task->dispatched_at ? 'needs_review' : 'failed', $reason); return;
                }
                $payload = $task->payload;
                $provider = $this->provider->client($account, $payload['plan']);
                $health = app(MachineCloudCredentialHealth::class);
                $credentialState = $health->status($account, $payload['plan'], $task->dispatched_at === null ? null : $task->id);
                if (!$credentialState['eligible']) {
                    if ($credentialState['state'] === 'cooldown') { $this->wait($task, $credentialState['retry_at']); return; }
                    throw new CloudException('credential_health_blocked');
                }
                $this->origin($payload['plan']);
                if (isset($payload['isolated_acceptance'])) app(MachineIsolatedAcceptance::class)->assertTask($task);
                if (!$task->dispatched_at) {
                    // Old queued payloads must fail locally, never be silently rewritten or posted again.
                    if ($account->provider === 'linode') LinodeProvisionClient::assertMetadataSize($payload['request']['metadata']['user_data'] ?? null);
                    if (isset($payload['plan']['credential_pool'])) app(MachineFailoverCredentials::class)->assertPlan($payload['plan']);
                    $provider->preflight($account, $payload['plan']);
                    $health->authenticatedRead($account, 'preflight');
                    $this->gate(); $this->actor($task->admin_id); $this->testMachine($task->machine_id);
                    if ($account->fresh()?->revision !== $task->account_revision) throw new CloudException('account_changed');
                    $current = $machine->fresh();
                    if (!$current || !$current->is_active || !hash_equals($task->machine_authority, hash('sha256', (string) $current->token))) throw new CloudException('source_changed');
                    $this->origin($payload['plan']);
                    if (isset($payload['plan']['credential_pool'])) app(MachineFailoverCredentials::class)->assertPlan($payload['plan']);
                    if (isset($payload['isolated_acceptance'])) app(MachineIsolatedAcceptance::class)->assertTask($task);
                    if ($task->replacement_id) app(MachineReplacementService::class)->assertDispatch($task);
                    if (isset($task->payload['coordinated_attempt'])) app(MachineFailoverNativeBridge::class)->assertTask($task);
                    $health->assertEligible($account, $payload['plan']);
                    $dispatchAt = time();
                    if ($task->deadline_at <= $dispatchAt) throw new CloudException('deadline');
                    // Persist the boundary before POST; retries can only perform reconciliation.
                    DB::transaction(function () use ($task, $dispatchAt) {
                        if (isset($task->payload['coordinated_attempt'])) app(MachineFailoverNativeBridge::class)->assertTask($task);
                        $task->dispatched_at = $dispatchAt; $this->move($task, 'dispatching');
                    });
                    try {
                        $snapshot = $provider->create($account, $payload['request'], $task->dispatched_at);
                        $this->acceptSnapshot($task, $snapshot);
                        if (!app(MachineFailoverRunner::class)->workerOwns($task)) return;
                        $health->resolveIntent($account, $task->id);
                    } catch (CloudException $error) {
                        if ($error->getMessage() !== 'outcome_uncertain') throw $error;
                        if (!app(MachineFailoverRunner::class)->workerOwns($task)) return;
                        $this->captureFailure($task, $account, $error, 'create');
                        $this->move($task, 'outcome_unknown', 'outcome_uncertain'); $this->wait($task); return;
                    }
                } elseif (!$task->external_id) {
                    $snapshot = $provider->reconcile($account, $payload['request'], $task->dispatched_at);
                    if (!app(MachineFailoverRunner::class)->workerOwns($task)) return;
                    if (!$snapshot) { $this->move($task, 'outcome_unknown', 'outcome_uncertain'); $this->wait($task); return; }
                    $this->acceptSnapshot($task, $snapshot);
                    $health->resolveIntent($account, $task->id);
                }
                if (!app(MachineFailoverRunner::class)->workerOwns($task)) return;
                $snapshot = $provider->instance($account, $task->external_id);
                $this->acceptSnapshot($task, $snapshot);
                if (!app(MachineFailoverRunner::class)->workerOwns($task)) return;
                if ($task->bootstrap_state === 'failed') { $this->move($task, 'needs_review', 'initialization_failed'); return; }
                if ($snapshot['status'] !== 'running') { $this->move($task, 'waiting_provider'); $this->wait($task); return; }
                if ($task->bootstrap_state !== 'succeeded') { $this->move($task, 'waiting_initialization'); $this->wait($task); return; }
                $resource = new MachineCloudResource(['machine_id' => $machine->id, 'installation_id' => $task->installation_id,
                    'machine_authority' => $task->machine_authority, 'public_ips' => $task->public_ips]);
                $health = $this->health->snapshot($resource, $task->dispatched_at);
                if (!$health['ready'] || $health['nodes'] !== 'fresh') {
                    $this->move($task, $health['agent'] === 'fresh' ? 'waiting_node_reports' : 'waiting_agent', null, ['reports' => $health]);
                    $this->wait($task); return;
                }
                $rows = $this->addresses->snapshots([$machine])[$machine->id] ?? [];
                $probe = $this->addresses->probes($rows, time())[$task->installation_id] ?? [];
                $passed = ($probe['status'] ?? null) === 'reachable' && ($probe['checked_at'] ?? 0) > $task->dispatched_at
                    && ($probe['address_installation_id'] ?? null) === $task->installation_id;
                $ips = array_filter($task->public_ips ?? [], fn ($ip) => !str_contains($ip, ':') && ($probe['addresses'][$ip]['status'] ?? null) === 'reachable');
                if (!$passed || !$ips) { $this->move($task, 'waiting_ipv4_probe', null, ['reports' => $health]); $this->wait($task); return; }
                $this->move($task, 'awaiting_acceptance', null, ['reports' => $health, 'ipv4_probe' => 'reachable',
                    'forwarding_verified' => false, 'dns_changed' => false, 'old_resources_changed' => false]);
            } catch (CloudException $error) {
                if (!app(MachineFailoverRunner::class)->workerOwns($task)) return;
                if (isset($account) && $account) $this->captureFailure($task, $account, $error, $task->dispatched_at === null ? 'preflight' : ($task->external_id ? 'readback' : 'create'));
                $reason = $this->reason($error->getMessage());
                if (in_array($reason, ['provider_unavailable', 'provider_rate_limited', 'reconcile_incomplete'], true)) {
                    $this->move($task, $task->dispatched_at && !$task->external_id ? 'outcome_unknown' : $task->status, $reason);
                    $this->wait($task, $error instanceof CloudRequestFailure ? time() + $error->decision['cooldown_seconds'] : null); return;
                }
                $rejected = isset($task->payload['coordinated_attempt']) && app(MachineFailoverNativeBridge::class)->mayFinishRejected($task, $error);
                $this->move($task, $task->dispatched_at && !$rejected ? 'needs_review' : ($reason === 'source_recovered' ? 'cancelled' : 'failed'), $reason);
            } catch (\Throwable) {
                if (!app(MachineFailoverRunner::class)->workerOwns($task)) return;
                $this->move($task, $task->dispatched_at ? 'needs_review' : 'failed', 'internal_failure');
            }
        }));
    }

    public function callback(string $id, string $installation, string $secret, string $state): bool
    {
        if (config('machine_provision.allow_live') !== true || !$this->available() || !Str::isUuid($id)
            || !preg_match('/^[a-f0-9]{64}$/D', $secret) || !preg_match('/^[a-f0-9]{32}$/D', $installation)
            || !in_array($state, ['succeeded', 'failed'], true)) throw new CloudException('invalid_callback');
        $task = Task::query()->find($id);
        if (!$task || !hash_equals($task->callback_hash, hash('sha256', $secret)) || !hash_equals($task->installation_id, $installation)
            || !$task->dispatched_at || $task->deadline_at < time()) throw new CloudException('invalid_callback');
        return $this->locked($task->account_id, fn () => DB::transaction(function () use ($id, $installation, $secret, $state) {
            $task = Task::query()->findOrFail($id);
            if (config('machine_provision.allow_live') !== true || !hash_equals($task->callback_hash, hash('sha256', $secret))
                || !hash_equals($task->installation_id, $installation) || !$task->dispatched_at
                || $task->deadline_at <= time()) throw new CloudException('invalid_callback');
            if (in_array($task->status, Task::TERMINAL, true)) return false;
            if (isset($task->payload['coordinated_attempt'])) app(MachineFailoverNativeBridge::class)->assertTask($task, false);
            if ($task->bootstrap_state !== null) {
                if ($task->bootstrap_state !== $state) throw new CloudException('bootstrap_conflict');
                return true;
            }
            $task->fill(['bootstrap_state' => $state, 'bootstrap_at' => time(), 'next_check_at' => 0])->save();
            DB::table('v2_machine_provision_step')->insertOrIgnore(['task_id' => $task->id, 'state' => 'bootstrap_' . $state, 'reason' => '', 'created_at' => time()]);
            $this->dispatch($task);
            return true;
        }));
    }

    public function cancel(string $id, int $admin): array
    {
        $this->actor($admin);
        $task = Task::query()->findOrFail($id);
        return $this->locked($task->account_id, function () use ($id) {
            $task = Task::query()->findOrFail($id);
            if (!in_array($task->status, Task::TERMINAL, true)) $this->move($task, $task->dispatched_at ? 'needs_review' : 'cancelled', 'cancelled_by_admin');
            return $this->present($task);
        });
    }

    public function advance(): array
    {
        if (config('machine_provision.allow_live') !== true || !$this->available()) return ['queued' => 0];
        $rows = Task::query()->whereNotIn('status', Task::TERMINAL)->where('next_check_at', '<=', time())->orderBy('created_at')->limit(10)->get();
        foreach ($rows as $task) $this->dispatch($task);
        return ['queued' => $rows->count()];
    }

    private function acceptSnapshot(Task $task, array $snapshot): void
    {
        DB::transaction(function () use ($task, $snapshot) {
            $row = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($row->external_id !== null && ($row->external_id !== $snapshot['external_id'] || $row->provider_created_at !== $snapshot['provider_created_at'])) throw new CloudException('identity_changed');
            $row->fill(['external_id' => $snapshot['external_id'], 'provider_created_at' => $snapshot['provider_created_at'], 'public_ips' => $snapshot['public_ips']])->save();
        });
        $task->refresh();
    }
    private function gate(): void { if (config('machine_provision.allow_live') !== true) throw new CloudException('provisioning_disabled'); }
    private function origin(array $plan): void
    {
        if (!hash_equals($this->bootstrap->configuredOrigin(), $plan['panel_origin'])) throw new CloudException('panel_origin_changed');
    }
    private function testMachine(int $id): void
    {
        if (!in_array($id, config('machine_provision.allowed_test_machine_ids', []), true)) throw new CloudException('test_machine_not_allowed');
    }
    private function actor(int $id): void
    {
        $admin = User::query()->find($id);
        if (!$admin || !$admin->is_admin || $admin->banned) throw new CloudException('permission_revoked');
    }
    private function locked(int $account, callable $work): mixed { return Cache::lock('machine-cloud:account:' . $account, 120)->block(2, $work); }
    private function quotaLocked(string $provider, callable $work): mixed
    {
        return app(MachineCloudCredentialHealth::class)->available() ? Cache::lock('machine-cloud:quota:' . $provider, 120)->block(2, $work) : $work();
    }
    private function reason(string $reason): string { return in_array($reason, self::REASONS, true) ? $reason : 'internal_failure'; }
    private function move(Task $task, string $state, ?string $reason = null, ?array $evidence = null): void
    {
        DB::transaction(function () use ($task, $state, $reason, $evidence) {
            if ($id = app(MachineFailoverRunner::class)->managed($task)) app(MachineFailoverRunner::class)->guard($id);
            $task->fill(['status' => $state, 'reason' => $reason]);
            if ($evidence !== null) $task->evidence = $evidence;
            if (in_array($state, Task::TERMINAL, true)) $task->finished_at = time();
            $task->save();
            DB::table('v2_machine_provision_step')->insertOrIgnore(['task_id' => $task->id, 'state' => $state, 'reason' => $reason ?? '', 'created_at' => time()]);
        });
        if ($task->replacement_id) app(MachineReplacementService::class)->sync($task);
    }
    private function wait(Task $task, ?int $notBefore = null): void
    {
        $seconds = max(10, min(60, (int) config('machine_provision.poll_seconds', 20)));
        $at = min($task->deadline_at, max(time() + $seconds, $notBefore ?? 0));
        DB::transaction(function () use ($task, $at) {
            if ($id = app(MachineFailoverRunner::class)->managed($task)) app(MachineFailoverRunner::class)->guard($id);
            $task->next_check_at = $at; $task->save();
        });
        $this->dispatch($task, max(1, $at - time()));
    }
    private function captureFailure(Task $task, MachineCloudAccount $account, CloudException $error, string $operation): void
    {
        if (!$error instanceof CloudRequestFailure) return;
        app(MachineCloudCredentialHealth::class)->record($account, $error, $operation, $task->id . ':' . ($task->next_check_at ?? 0), $task->payload['plan'], $task->id);
        $evidence = $task->evidence ?? []; $evidence['credential_failure'] = $error->decision;
        $evidence['credential_failure_operation'] = $operation;
        $evidence['credential_rotation'] = ['automatic' => false, 'dispatch_allowed' => false, 'action' => $error->decision['outcome'] === 'unknown' ? 'reconcile' : 'stop',
            'reason' => $error->decision['outcome'] === 'unknown' ? 'original_intent_only' : 'single_credential', 'next_account_id' => null, 'next_account_revision' => null];
        if ($task->replacement_id) {
            $row = MachineReplacementTask::query()->find($task->replacement_id);
            $policy = $row ? \App\Models\MachineFailoverPolicy::query()->find($row->policy_id) : null;
            if ($policy) {
                try { $evidence['credential_rotation'] = app(MachineFailoverCredentials::class)->rotationPlan($policy, $task, $error->decision); }
                catch (CloudException) { $evidence['credential_rotation']['reason'] = 'credential_context_invalid'; }
            }
        }
        $task->evidence = $evidence; $task->save();
    }
    private function dispatch(Task $task, int $delay = 0): void
    {
        if (app(MachineFailoverRunner::class)->wakeTask($task)) return;
        try { MachineProvisionJob::dispatch($task->id)->delay(now()->addSeconds($delay))->afterCommit(); }
        catch (\Throwable) {
            $task->fill(['reason' => 'queue_unavailable'])->save();
            if ($task->replacement_id) app(MachineReplacementService::class)->sync($task);
        }
    }
    private function present(Task $task): array
    {
        return $task->toArray() + ['installation_prefix' => substr($task->installation_id, 0, 8),
            'plan' => ProvisionPlan::summary($task->payload['plan']), 'resource_may_exist' => $task->dispatched_at !== null,
            'steps' => DB::table('v2_machine_provision_step')->where('task_id', $task->id)->orderBy('id')->limit(100)->get()];
    }
}

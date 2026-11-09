<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudGroup;
use App\Models\MachineCloudResource;
use App\Models\MachineCloudTask;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineProvisionTask as Task;
use Illuminate\Support\Facades\Schema;

class MachineFailoverCredentials
{
    public function available(): bool
    {
        return app(MachineCloudGroupService::class)->available() && Schema::hasColumn('v2_machine_failover_policy', 'credential_group_id');
    }

    public function grouped(Policy $policy): bool { return $policy->credential_group_id !== null; }
    public function provider(Policy $policy): ?string
    {
        return $this->grouped($policy) ? $policy->provider : Account::query()->whereKey($policy->account_id)->value('provider');
    }

    public function pool(int $group, string $provider): array
    {
        if (!$this->available()) throw new CloudException('groups_migration_required');
        if (!in_array($provider, ['linode', 'digitalocean', 'vultr'], true)) throw new CloudException('unsupported_provider');
        $row = MachineCloudGroup::query()->find($group);
        if (!$row) throw new CloudException('group_missing');
        $accounts = Account::query()->where('group_id', $group)->where('provider', $provider)->orderBy('id')->limit(51)->get(['id', 'name', 'provider', 'revision', 'group_id', 'group_revision']);
        if ($accounts->isEmpty()) throw new CloudException('credential_group_empty');
        if ($accounts->count() > 50) throw new CloudException('limit_reached');
        $fingerprint = hash('sha256', json_encode([$group, $provider, $accounts->map(fn ($account) => $account->only(['id', 'revision', 'group_revision']))->all()], JSON_THROW_ON_ERROR));
        return ['id' => $group, 'name' => $row->name, 'provider' => $provider, 'accounts' => $accounts, 'fingerprint' => $fingerprint];
    }

    public function ids(Policy $policy): array
    {
        return $this->grouped($policy) ? $this->pool($policy->credential_group_id, $policy->provider)['accounts']->pluck('id')->all() : [$policy->account_id];
    }

    public function selection(Policy $policy): array
    {
        if (!$this->grouped($policy)) return ['account_id' => $policy->account_id];
        if (!Schema::hasTable('v2_machine_provision_task') || !Schema::hasTable('v2_machine_cloud_task')) throw new CloudException('budget_history_unavailable');
        if (Task::query()->where('machine_id', $policy->machine_id)->where('slot', $policy->slot)->where('created_at', '>=', time() - 86400)->count() >= $policy->daily_limit) throw new CloudException('credential_group_daily_budget');
        $pool = $this->pool($policy->credential_group_id, $policy->provider);
        $limit = min($policy->daily_limit, max(1, min(2, (int) config('machine_provision.daily_limit_per_account', 2))));
        $candidates = [];
        $health = app(MachineCloudCredentialHealth::class);
        foreach ($pool['accounts'] as $account) {
            $full = Account::query()->findOrFail($account->id);
            if (!$health->status($full, $policy->only(['region', 'size', 'image']))['eligible']) continue;
            $ids = $health->sameAccountIds($full);
            $history = Task::query()->whereIn('account_id', $ids);
            $attempts = (clone $history)->where('created_at', '>=', time() - 86400)->count();
            $last = (clone $history)->max('created_at');
            if ($attempts >= $limit || ($last !== null && $last + $policy->cooldown_seconds > time())
                || (clone $history)->whereNotIn('status', ['accepted', 'failed', 'cancelled'])->exists()
                || MachineCloudTask::query()->whereIn('resource_id', MachineCloudResource::query()->whereIn('account_id', $ids)->select('id'))
                    ->whereNotIn('status', MachineCloudTask::TERMINAL)->exists()) continue;
            $candidates[] = ['account' => $account, 'attempts' => $attempts, 'last' => $last ?? 0];
        }
        usort($candidates, fn ($a, $b) => [$a['attempts'], $a['last'], $a['account']->id] <=> [$b['attempts'], $b['last'], $b['account']->id]);
        $chosen = $candidates[0]['account'] ?? null;
        if (!$chosen) throw new CloudException('credential_group_exhausted');
        return ['account_id' => $chosen->id, 'provider' => $policy->provider, 'credential_pool' => ['group_id' => $pool['id'],
            'provider' => $policy->provider, 'fingerprint' => $pool['fingerprint'], 'account_revision' => $chosen->revision]];
    }

    public function context(Policy $policy, ?Task $task = null): array
    {
        if ($task === null) return $this->selection($policy);
        if (!$this->owns($policy, $task)) throw new CloudException('ownership_mismatch');
        $plan = $task->payload['plan'];
        if (!$this->grouped($policy)) return ['account_id' => $policy->account_id];
        $this->assertPlan($plan, $task->dispatched_at === null, $task->dispatched_at === null);
        return ['account_id' => $plan['account_id'], 'provider' => $plan['provider'], 'credential_pool' => $plan['credential_pool']];
    }

    public function owns(Policy $policy, Task $task): bool
    {
        if (!$this->grouped($policy)) return $task->account_id === $policy->account_id;
        $plan = $task->payload['plan']; $pool = $plan['credential_pool'] ?? [];
        return ($plan['account_id'] ?? null) === $task->account_id && ($plan['provider'] ?? null) === $policy->provider
            && ($pool['group_id'] ?? null) === $policy->credential_group_id && ($pool['provider'] ?? null) === $policy->provider;
    }

    public function assertPlan(array $plan, bool $snapshot = true, bool $membership = true): Account
    {
        $account = Account::query()->find($plan['account_id']);
        if (!$account) throw new CloudException('credentials_required');
        if (!isset($plan['credential_pool'])) return $account;
        $scope = $plan['credential_pool'];
        if ($account->provider !== $scope['provider'] || $account->revision !== $scope['account_revision']) throw new CloudException('credential_group_changed');
        if ($membership && $account->group_id !== $scope['group_id']) throw new CloudException('credential_group_changed');
        if ($snapshot && !hash_equals($this->pool($scope['group_id'], $scope['provider'])['fingerprint'], $scope['fingerprint'])) throw new CloudException('credential_group_changed');
        return $account;
    }

    public function rotationPlan(Policy $policy, Task $task, array $failure, array $attempted = []): array
    {
        if (!$this->grouped($policy) || !$this->owns($policy, $task)) return ['automatic' => false, 'dispatch_allowed' => false, 'action' => 'stop',
            'reason' => 'single_credential', 'next_account_id' => null, 'next_account_revision' => null];
        $health = app(MachineCloudCredentialHealth::class); $pool = $this->pool($policy->credential_group_id, $policy->provider);
        $current = Account::query()->findOrFail($task->account_id); $plan = $task->payload['plan']; $candidates = [];
        $limit = min($policy->daily_limit, max(1, min(2, (int) config('machine_provision.daily_limit_per_account', 2))));
        foreach ($pool['accounts'] as $row) {
            $account = Account::query()->findOrFail($row->id); $ids = $health->sameAccountIds($account);
            $history = Task::query()->whereIn('account_id', $ids); $last = (clone $history)->max('created_at');
            $candidates[] = ['id' => $account->id, 'revision' => $account->revision, 'provider' => $account->provider, 'group_id' => $account->group_id,
                'credential_key' => $health->credentialKey($account), 'identity_key' => $health->identityKey($account),
                'attempts' => (clone $history)->where('created_at', '>=', time() - 86400)->count(), 'last_attempt_at' => $last ?? 0,
                'eligible' => $health->status($account, $plan)['eligible'] && !(clone $history)->whereNotIn('status', ['accepted', 'failed', 'cancelled'])->exists()
                    && !MachineCloudTask::query()->whereIn('resource_id', MachineCloudResource::query()->whereIn('account_id', $ids)->select('id'))->whereNotIn('status', MachineCloudTask::TERMINAL)->exists(),
                'budget_available' => (clone $history)->where('created_at', '>=', time() - 86400)->count() < $limit
                    && ($last === null || $last + $policy->cooldown_seconds <= time())];
        }
        usort($candidates, fn ($a, $b) => [$a['attempts'], $a['last_attempt_at'], $a['id']] <=> [$b['attempts'], $b['last_attempt_at'], $b['id']]);
        $remaining = max(0, $policy->daily_limit - Task::query()->where('machine_id', $policy->machine_id)->where('slot', $policy->slot)->where('created_at', '>=', time() - 86400)->count());
        return app(MachineCredentialRotationPlanner::class)->decide($failure, ['id' => $current->id, 'provider' => $current->provider,
            'group_id' => $policy->credential_group_id, 'credential_key' => $health->credentialKey($current), 'identity_key' => $health->identityKey($current)], $candidates, $remaining,
            array_values(array_unique(array_merge($attempted, [$task->account_id]))));
    }
}

<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource as Resource;
use App\Models\MachineCloudTask;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineProvisionTask as Task;
use Illuminate\Support\Facades\Schema;

class MachineProvisionLimits
{
    public const DEFAULTS = ['max_instances' => 0, 'max_parallel' => 1, 'daily_limit' => 0];
    private const FINISHED = ['rejected', 'failed', 'cancelled', 'completed', 'retained', 'released', 'cleaned'];

    public static function normalize(mixed $input): array
    {
        if (!is_array($input) || array_diff(array_keys($input), array_keys(self::DEFAULTS))) throw new CloudException('invalid_capacity');
        $value = $input + self::DEFAULTS;
        foreach (['max_instances' => [0, 10000], 'max_parallel' => [1, 5], 'daily_limit' => [0, 1000]] as $key => [$min, $max]) {
            if (!is_int($value[$key]) || $value[$key] < $min || $value[$key] > $max) throw new CloudException('invalid_capacity');
        }
        return $value;
    }

    public static function minimum(int ...$limits): int
    {
        $positive = array_filter($limits, fn ($limit) => $limit > 0);
        return $positive ? min($positive) : 0;
    }

    public static function reached(int $count, int $limit): bool { return $limit > 0 && $count >= $limit; }

    public function settings(Account $account): array
    {
        $ids = app(MachineCloudCredentialHealth::class)->sameAccountIds($account);
        $global = config('machine_provision.daily_limit_per_account', 0);
        if (!is_int($global) && !(is_string($global) && preg_match('/^(?:0|[1-9][0-9]{0,3})$/D', $global))) throw new CloudException('invalid_capacity');
        $result = ['max_instances' => 0, 'max_parallel' => 5, 'daily_limit' => (int) $global];
        if ($result['daily_limit'] < 0 || $result['daily_limit'] > 1000) throw new CloudException('invalid_capacity');
        foreach (Account::query()->whereIn('id', $ids)->get() as $alias) {
            $limits = self::normalize($alias->provision_limits ?? []);
            $result['max_instances'] = self::minimum($result['max_instances'], $limits['max_instances']);
            $result['daily_limit'] = self::minimum($result['daily_limit'], $limits['daily_limit']);
            $result['max_parallel'] = min($result['max_parallel'], $limits['max_parallel']);
        }
        // Unknown provider quotas use a serialized create lane, not a one-instance quota.
        if ($account->provider !== 'digitalocean') $result['max_parallel'] = 1;
        return $result;
    }

    public function policyReached(Policy $policy, ?string $except = null): bool
    {
        return self::reached(Task::query()->where('machine_id', $policy->machine_id)->where('slot', $policy->slot)
            ->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->where('created_at', '>=', time() - 86400)->count(), (int) $policy->daily_limit);
    }

    public function usage(Account $account, ?string $exceptTask = null, ?string $exceptAttempt = null): array
    {
        $ids = app(MachineCloudCredentialHealth::class)->sameAccountIds($account);
        $history = Task::query()->whereIn('account_id', $ids)->when($exceptTask, fn ($q) => $q->where('id', '!=', $exceptTask));
        $resources = Resource::query()->whereIn('account_id', $ids)->pluck('external_id')->map(fn ($id) => (string) $id)->all();
        $active = 0; $reserved = 0; $tasks = (clone $history)->get();
        $attempts = Schema::hasTable('v2_machine_failover_attempt')
            ? Attempt::query()->whereIn('task_id', $tasks->pluck('id'))->get()->keyBy('task_id') : collect();
        foreach ($tasks as $task) {
            $attempt = $attempts->get($task->id);
            if ($attempt?->status === 'cleaned') {
                // A terminal status alone cannot release capacity: verify the sealed two-read cleanup proof.
                app(MachineCandidateRetry::class)->confirmed($attempt, $task);
                continue;
            }
            $failure = $task->evidence['credential_failure'] ?? [];
            $rejected = $task->status === 'failed' && $task->bootstrap_state === null
                && in_array($task->evidence['credential_failure_operation'] ?? null, ['create', 'preflight'], true)
                && ($failure['outcome'] ?? null) === 'rejected' && ($failure['requires_review'] ?? true) === false;
            $uncertain = $task->dispatched_at && !$task->external_id && !$rejected;
            if (!in_array($task->status, ['accepted', 'failed', 'cancelled'], true) || $uncertain
                || ($attempt && app(MachineCandidateRetry::class)->pending($attempt))) $active++;
            if ($task->external_id) $resources[] = (string) $task->external_id;
            elseif (!in_array($task->status, ['failed', 'cancelled'], true) || $uncertain) $reserved++;
        }
        if (Schema::hasTable('v2_machine_failover_attempt')) {
            $unbound = Attempt::query()->whereIn('account_id', $ids)->whereNull('task_id')->whereNotIn('status', self::FINISHED)
                ->when($exceptAttempt, fn ($q) => $q->where('id', '!=', $exceptAttempt))->count();
            $active += $unbound; $reserved += $unbound;
        }
        return ['external_ids' => array_values(array_unique($resources)), 'reserved' => $reserved, 'active' => $active,
            'used' => count(array_unique($resources)) + $reserved,
            'attempts_24h' => (clone $history)->where('created_at', '>=', time() - 86400)->count(),
            'power_active' => MachineCloudTask::query()->whereIn('resource_id', Resource::query()->whereIn('account_id', $ids)->select('id'))
                ->whereNotIn('status', MachineCloudTask::TERMINAL)->exists()];
    }

    public function assertAvailable(Account $account, ?string $exceptTask = null, ?string $exceptAttempt = null): array
    {
        $limits = $this->settings($account); $usage = $this->usage($account, $exceptTask, $exceptAttempt);
        if (self::reached($usage['attempts_24h'], $limits['daily_limit'])) throw new CloudException('daily_budget');
        if ($usage['power_active'] || $usage['active'] >= $limits['max_parallel']) throw new CloudException('task_active');
        if (self::reached($usage['used'], $limits['max_instances'])) throw new CloudException('credential_capacity_full');
        return $limits + $usage;
    }

    public function assertDispatch(Account $account, Task $task): void
    {
        $state = $this->assertAvailable($account, $task->id, $task->payload['coordinated_attempt'] ?? null);
        // API reads occur only inside the existing provider quota lock, immediately before POST.
        if ($account->provider !== 'digitalocean' && !$state['max_instances']) return;
        $client = match ($account->provider) {
            'linode' => app(LinodeClient::class), 'digitalocean' => app(DigitalOceanClient::class),
            'vultr' => app(VultrClient::class), default => throw new CloudException('unsupported_provider'),
        };
        $limit = $state['max_instances'];
        if ($account->provider === 'digitalocean') $limit = self::minimum($limit, $client->instanceLimit($account));
        $remote = []; $pages = null; $total = null;
        for ($page = 1; $page <= 10; $page++) {
            $data = $client->inventory($account, $page);
            if (!is_int($data['pages']) || $data['pages'] < 0 || $data['pages'] > 10 || ($pages !== null && $pages !== $data['pages'])) throw new CloudException('capacity_unknown');
            if (!is_int($data['total'] ?? null) || $data['total'] < 0 || $data['total'] > 1000
                || ($total !== null && $total !== $data['total'])
                || !in_array($data['pages'], [(int) ceil($data['total'] / 100), max(1, (int) ceil($data['total'] / 100))], true)
                || count($data['resources']) !== max(0, min(100, $data['total'] - ($page - 1) * 100))) throw new CloudException('capacity_unknown');
            $total = $data['total'];
            $pages = $data['pages'];
            foreach ($data['resources'] as $row) {
                if (isset($remote[$row['external_id']])) throw new CloudException('capacity_unknown');
                $remote[$row['external_id']] = true;
            }
            if ($page >= $pages) break;
        }
        $used = count(array_unique([...array_map('strval', array_keys($remote)), ...$state['external_ids']])) + $state['reserved'];
        if (self::reached($used, $limit)) throw new CloudException('credential_capacity_full');
    }
}

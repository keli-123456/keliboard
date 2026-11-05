<?php
namespace App\Services\ServerMachine\Cloud;

use App\Jobs\CheckMachineCloudJob;
use App\Jobs\MachineCloudPowerJob;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource as Resource;
use App\Models\MachineCloudTask as Task;
use App\Models\User;
use App\Services\Node\NodeReportSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineCloudService
{
    public function __construct(private LinodeClient $provider, private MachineCloudHealth $health) {}
    public function available(): bool
    {
        return Schema::hasTable('v2_machine_cloud_account') && Schema::hasTable('v2_machine_cloud_resource') && Schema::hasTable('v2_machine_cloud_task');
    }
    private function lock(string $key, callable $work): mixed { return Cache::lock('machine-cloud:' . $key, 120)->block(2, $work); }
    private function withResource(int $id, callable $work): mixed
    {
        $initial = Resource::query()->findOrFail($id);
        return $this->lock('account:' . $initial->account_id, fn () => $this->lock('resource:' . $id, function () use ($id, $work) {
            $resource = Resource::query()->findOrFail($id);
            return $work($resource, Account::query()->findOrFail($resource->account_id));
        }));
    }

    public function overview(): array
    {
        if (!$this->available()) return ['available' => false, 'accounts' => [], 'resources' => [], 'tasks' => []];
        $resources = Resource::query()->orderBy('account_id')->orderBy('id')->limit(500)->get();
        $latest = Task::query()->whereIn('id', $resources->pluck('last_task_id')->filter())->get()->keyBy('id');
        return ['available' => true, 'accounts' => Account::query()->orderBy('id')->get(),
            'resources' => $resources->map(fn ($resource) => $resource->toArray() + ['health' => $this->health->snapshot($resource,
                $resource->dns_hold ? (int) ($latest->get($resource->last_task_id)?->dispatched_at ?? 0) : 0)]),
            'tasks' => Task::query()->orderByDesc('id')->limit(100)->get()];
    }

    public function listSummaries(iterable $machines): array
    {
        if (!$this->available()) return [];
        $authorities = [];
        foreach ($machines as $machine) $authorities[$machine->id] = hash('sha256', (string) $machine->token);
        if (!$authorities) return [];
        $resources = Resource::query()->whereIn('machine_id', array_keys($authorities))->whereNotNull('installation_id')->get();
        $tasks = Task::query()->whereIn('id', $resources->pluck('last_task_id')->filter())->get()->keyBy('id');
        $result = [];
        foreach ($resources as $resource) {
            if (!NodeReportSource::installation($resource->installation_id)
                || !hash_equals($authorities[$resource->machine_id], (string) $resource->machine_authority)) continue;
            $task = $tasks->get($resource->last_task_id);
            // Only local, explicitly selected fields; a list read must never contact the provider.
            $result[$resource->machine_id][] = [
                'id' => $resource->id, 'installation_id' => $resource->installation_id, 'provider' => 'linode',
                'external_id' => $resource->external_id, 'label' => $resource->label, 'region' => $resource->region,
                'status' => $resource->status, 'observed_at' => $resource->observed_at,
                'auto_boot' => $resource->auto_boot, 'dns_hold' => $resource->dns_hold,
                'recovery_status' => $resource->recovery_status,
                'task' => $task && $task->resource_id === $resource->id ? $task->only(['id', 'action', 'status', 'reason', 'created_at']) : null,
            ];
        }
        return $result;
    }

    public function saveAccount(array $input): Account
    {
        return $this->lock('account:' . ($input['id'] ?? 'new'), function () use ($input) {
            $row = isset($input['id']) ? Account::query()->findOrFail($input['id']) : new Account();
            if ($row->exists && $row->revision !== $input['expected_revision']) throw new CloudException('config_changed');
            if ($row->exists && Resource::query()->where('account_id', $row->id)->whereNotNull('active_task_id')->exists()) throw new CloudException('task_active');
            if (!$row->exists && Account::query()->count() >= 50) throw new CloudException('limit_reached');
            $keys = $row->exists ? $row->credentials : [];
            if (trim((string) ($input['api_token'] ?? '')) !== '') $keys['api_token'] = trim($input['api_token']);
            if (empty($keys['api_token'])) throw new CloudException('credentials_required');
            DB::transaction(function () use ($row, $keys, $input) {
                if ($row->exists) Resource::query()->where('account_id', $row->id)->update(['auto_boot' => false, 'armed' => false,
                    'manual_hold' => true, 'recovery_status' => 'account_changed', 'revision' => DB::raw('revision + 1')]);
                $row->fill(['name' => $input['name'], 'provider' => 'linode', 'credentials' => $keys, 'revision' => ($row->revision ?? 0) + 1])->save();
            });
            return $row;
        });
    }

    public function deleteAccount(int $id, int $revision): void
    {
        $this->lock('account:' . $id, function () use ($id, $revision) {
            $account = Account::query()->findOrFail($id);
            if ($account->revision !== $revision) throw new CloudException('config_changed');
            if (Resource::query()->where('account_id', $id)->where(fn ($q) => $q->whereNotNull('machine_id')->orWhereNotNull('active_task_id'))->exists()) throw new CloudException('account_in_use');
            DB::transaction(function () use ($account) { Resource::query()->where('account_id', $account->id)->delete(); $account->delete(); });
        });
    }

    public function refreshAccount(int $id, int $page): void
    {
        $this->lock('account:' . $id, function () use ($id, $page) {
            $account = Account::query()->findOrFail($id);
            try {
                $data = $this->provider->inventory($account, $page);
                DB::transaction(function () use ($account, $data, $page) {
                    foreach ($data['resources'] as $snapshot) {
                        $row = Resource::query()->where('external_id', $snapshot['external_id'])->first();
                        if ($row && (int) $row->account_id !== $account->id) throw new CloudException('resource_in_use');
                        if (!$row && Resource::query()->count() >= 500) throw new CloudException('limit_reached');
                        $row ??= new Resource(['account_id' => $account->id]);
                        $this->applySnapshot($row, $snapshot); $row->save();
                    }
                    $account->fill(['last_sync_at' => time(), 'last_page' => $page, 'pages' => $data['pages'], 'last_error' => null])->save();
                });
            } catch (CloudException $error) { $account->last_error = $error->getMessage(); $account->save(); throw $error; }
        });
    }

    private function applySnapshot(Resource $resource, array $snapshot): void
    {
        if ($resource->exists && $resource->provider_created_at !== $snapshot['provider_created_at']) throw new CloudException('identity_changed');
        $resource->fill($snapshot);
    }

    public function binding(int $id, int $revision, ?int $machineId, ?string $installation): void
    {
        $this->withResource($id, function ($resource, $account) use ($revision, $machineId, $installation) {
            $this->editable($resource, $revision);
            $this->applySnapshot($resource, $this->provider->instance($account, $resource->external_id));
            $resource->save();
            if ($machineId !== null && !NodeReportSource::installation($installation)) throw new CloudException('invalid_binding');
            if ($machineId !== null && Resource::query()->where('machine_id', $machineId)->where('installation_id', $installation)->where('id', '!=', $resource->id)->exists()) throw new CloudException('instance_in_use');
            $machine = $machineId === null ? null : \App\Models\ServerMachine::query()->findOrFail($machineId);
            $resource->fill(['machine_id' => $machineId, 'installation_id' => $machineId ? $installation : null,
                'machine_authority' => $machine ? hash('sha256', (string) $machine->token) : null]);
            if ($machine && $this->health->snapshot($resource)['agent'] !== 'fresh') throw new CloudException('source_unavailable');
            $resource->fill(['auto_boot' => false, 'armed' => false, 'manual_hold' => true, 'recovery_status' => 'disabled', 'revision' => $resource->revision + 1])->save();
        });
    }

    private function editable(Resource $resource, int $revision): void
    {
        if ($resource->revision !== $revision) throw new CloudException('config_changed');
        if ($resource->active_task_id !== null) throw new CloudException('task_active');
    }

    public function policy(int $id, int $revision, bool $enabled, int $admin): void
    {
        $this->withResource($id, function ($resource, $account) use ($revision, $enabled, $admin) {
            $this->editable($resource, $revision);
            if ($enabled) {
                $this->applySnapshot($resource, $this->provider->instance($account, $resource->external_id));
                $resource->save();
                if ($resource->status !== 'running' || $this->health->snapshot($resource)['agent'] !== 'fresh') throw new CloudException('baseline_required');
                $this->assertIdle($this->provider->safety($account, $resource->external_id, time() - 600));
            }
            $resource->fill(['auto_boot' => $enabled, 'armed' => $enabled, 'manual_hold' => !$enabled, 'policy_admin_id' => $admin,
                'last_running_at' => $enabled ? time() : $resource->last_running_at, 'offline_since' => null, 'offline_samples' => 0,
                'recovery_status' => $enabled ? 'watching' : 'disabled', 'revision' => $resource->revision + 1, 'next_check_at' => 0])->save();
        });
    }

    public function queue(int $id, int $revision, string $action, string $requestId, int $admin): Task
    {
        if (!in_array($action, ['boot', 'shutdown', 'reboot'], true)) throw new CloudException('invalid_action');
        $key = hash('sha256', $admin . ':' . $requestId);
        return $this->withResource($id, function ($resource, $account) use ($revision, $action, $key, $admin) {
            if ($existing = Task::query()->where('request_key', $key)->first()) {
                if ($existing->resource_id !== $resource->id || $existing->action !== $action || (int) $existing->admin_id !== $admin) throw new CloudException('request_conflict');
                return $existing;
            }
            $this->editable($resource, $revision);
            return $this->accept($resource, $account, $action, $key, $admin, false);
        });
    }

    private function accept(Resource $resource, Account $account, string $action, string $key, int $admin, bool $automatic): Task
    {
        if (!$this->adminActive($admin)) throw new CloudException('permission_revoked');
        if (Task::query()->where('resource_id', $resource->id)->where('created_at', '>', time() - 60)->exists()) throw new CloudException('cooldown');
        if (!$automatic) {
            $this->applySnapshot($resource, $this->provider->instance($account, $resource->external_id));
            $resource->save();
            $events = $this->provider->safety($account, $resource->external_id, time() - 600);
            $this->assertIdle($events);
        }
        if (($action === 'boot' && $resource->status !== 'offline') || ($action !== 'boot' && $resource->status !== 'running')) throw new CloudException('state_changed');
        $task = DB::transaction(function () use ($resource, $account, $action, $key, $admin, $automatic) {
            $resource->revision++;
            $task = Task::query()->create(['resource_id' => $resource->id, 'admin_id' => $admin, 'request_key' => $key,
                'action' => $action, 'automatic' => $automatic, 'resource_revision' => $resource->revision,
                'account_revision' => $account->revision, 'external_id' => $resource->external_id, 'resource_label' => $resource->label,
                'status' => 'queued', 'deadline_at' => time() + 900]);
            $resource->fill(['active_task_id' => $task->id, 'last_task_id' => $task->id, 'dns_hold' => $resource->machine_id !== null,
                'armed' => false, 'manual_hold' => $action === 'shutdown', 'recovery_status' => 'queued',
                'last_auto_boot_at' => $automatic ? time() : $resource->last_auto_boot_at])->save();
            return $task;
        });
        try { MachineCloudPowerJob::dispatch((int) $task->id); }
        catch (\Throwable) { $this->finish($task, $resource, 'failed', 'queue_unavailable'); }
        return $task->fresh();
    }

    public function cancel(int $taskId, int $admin): void
    {
        if (!$this->adminActive($admin)) throw new CloudException('permission_revoked');
        $task = Task::query()->findOrFail($taskId);
        $this->withResource((int) $task->resource_id, function ($resource) use ($taskId) {
            $task = Task::query()->findOrFail($taskId);
            if ($task->status !== 'queued') throw new CloudException('already_dispatched');
            $this->finish($task, $resource, 'cancelled', 'cancelled');
        });
    }

    public function process(int $id): void
    {
        $first = Task::query()->find($id);
        if (!$first || in_array($first->status, Task::TERMINAL, true)) return;
        $this->withResource((int) $first->resource_id, function ($resource, $account) use ($id) {
            $task = Task::query()->findOrFail($id);
            if (in_array($task->status, Task::TERMINAL, true)) return;
            if ($task->deadline_at <= time()) { $this->finish($task, $resource, 'expired', 'deadline'); return; }
            if ($resource->revision !== $task->resource_revision || $account->revision !== $task->account_revision
                || !$this->adminActive((int) $task->admin_id)) { $this->finish($task, $resource, 'failed', 'config_changed'); return; }
            try {
                $this->applySnapshot($resource, $this->provider->instance($account, $resource->external_id));
                if ($task->status === 'queued') {
                    if ($resource->machine_id && $this->health->snapshot($resource)['agent'] === 'identity_changed') throw new CloudException('identity_changed');
                    if ($task->automatic && (!$resource->auto_boot || $resource->manual_hold)) throw new CloudException('permission_revoked');
                    if (($task->action === 'boot' && $resource->status !== 'offline') || ($task->action !== 'boot' && $resource->status !== 'running')) throw new CloudException('state_changed');
                    $events = $this->provider->safety($account, $resource->external_id, time() - 600);
                    $this->assertIdle($events);
                    if ($task->automatic) $this->assertNoShutdown($events, (int) ($resource->last_running_at ?? 0));
                    if (!$this->adminActive((int) $task->admin_id)) throw new CloudException('permission_revoked');
                    if ($resource->machine_id && $this->health->snapshot($resource)['agent'] === 'identity_changed') throw new CloudException('identity_changed');
                    // Commit the dispatch boundary first. A worker crash cannot replay this POST.
                    $task->fill(['status' => 'dispatching', 'dispatched_at' => time(), 'baseline_event_id' => max([0, ...array_column($events, 'id')])])->save();
                    try {
                        $this->provider->power($account, $resource->external_id, $task->action);
                        $task->fill(['status' => 'waiting_provider', 'acknowledged' => true, 'next_check_at' => time() + 30])->save();
                    } catch (CloudException $error) {
                        if ($error->getMessage() !== 'outcome_uncertain') throw $error;
                        $task->fill(['status' => 'uncertain', 'reason' => 'outcome_uncertain', 'next_check_at' => time() + 30])->save();
                    }
                    $resource->save();
                    app(\App\Services\ServerMachine\MachineOperationNotifier::class)->cloud((int) $task->id);
                    return;
                }
                $resource->save();
                if ($task->status === 'dispatching') app(\App\Services\ServerMachine\MachineOperationNotifier::class)->cloud((int) $task->id);
                $wanted = $task->action === 'shutdown' ? 'offline' : 'running';
                if (in_array($resource->status, ['booting', 'rebooting', 'shutting_down'], true)) $task->saw_transition = true;
                $confirmed = $resource->status === $wanted;
                if ($task->action === 'reboot' && $confirmed) {
                    $events = $this->provider->safety($account, $resource->external_id, max(0, $task->dispatched_at - 2));
                    $confirmed = (bool) array_filter($events, fn ($event) => $event['id'] > $task->baseline_event_id
                        && $event['action'] === 'linode_reboot' && in_array($event['status'], ['finished', 'completed'], true));
                }
                if (!$confirmed) { $task->next_check_at = time() + 30; $task->save(); return; }
                $task->provider_confirmed_at ??= time();
                if ($task->action === 'shutdown' || !$resource->machine_id) { $this->finish($task, $resource, 'completed'); return; }
                $health = $this->health->snapshot($resource, (int) $task->dispatched_at);
                if (!$health['ready']) { $task->fill(['status' => 'waiting_reports', 'next_check_at' => time() + 30])->save(); return; }
                $resource->dns_hold = false;
                $this->finish($task, $resource, 'completed');
            } catch (CloudException $error) {
                if ($task->dispatched_at && in_array($error->getMessage(), ['provider_unavailable', 'provider_rate_limited'], true)) {
                    $task->fill(['reason' => $error->getMessage(), 'next_check_at' => time() + 60])->save(); return;
                }
                $this->finish($task, $resource, 'failed', $error->getMessage());
            }
        });
    }

    private function finish(Task $task, Resource $resource, string $status, ?string $reason = null): void
    {
        DB::transaction(function () use ($task, $resource, $status, $reason) {
            $task->fill(['status' => $status, 'reason' => $reason, 'finished_at' => time()])->save();
            if ((int) $resource->active_task_id === $task->id) $resource->active_task_id = null;
            $resource->recovery_status = $status;
            if ($status !== 'completed') { $resource->manual_hold = true; $resource->armed = false; }
            $resource->save();
        });
        app(\App\Services\ServerMachine\MachineOperationNotifier::class)->cloud((int) $task->id);
    }

    public function verifyRecovery(int $id, int $revision): void
    {
        $this->withResource($id, function ($resource, $account) use ($revision) {
            $this->editable($resource, $revision);
            $this->applySnapshot($resource, $this->provider->instance($account, $resource->external_id));
            $resource->save();
            $after = (int) (Task::query()->find($resource->last_task_id)?->dispatched_at ?? 0);
            if ($resource->status !== 'running' || !$this->health->snapshot($resource, $after)['ready']) throw new CloudException('reports_pending');
            $wasHeld = $resource->dns_hold;
            $resource->fill(['dns_hold' => false, 'revision' => $resource->revision + 1])->save();
            if ($wasHeld && $resource->last_task_id) app(\App\Services\ServerMachine\MachineOperationNotifier::class)->cloud((int) $resource->last_task_id, true);
        });
    }

    public function check(int $id, int $revision): void
    {
        $this->withResource($id, function ($resource, $account) use ($revision) {
            if ($resource->revision !== $revision || !$resource->auto_boot || $resource->active_task_id || $resource->next_check_at > time()) return;
            $resource->next_check_at = time() + 60;
            try {
                if (!$this->adminActive((int) $resource->policy_admin_id)) throw new CloudException('permission_revoked');
                $this->applySnapshot($resource, $this->provider->instance($account, $resource->external_id));
                $health = $this->health->snapshot($resource);
                if ($health['agent'] === 'identity_changed') throw new CloudException('identity_changed');
                if ($resource->status === 'running') {
                    $resource->offline_samples = 0; $resource->offline_since = null;
                    if ($health['agent'] === 'fresh' && !$resource->manual_hold) {
                        $resource->last_running_at = time(); $resource->armed = true; $resource->recovery_status = 'watching';
                    } else $resource->recovery_status = $resource->manual_hold ? 'manual_hold' : 'reports_pending';
                    $resource->save(); return;
                }
                if ($resource->status !== 'offline') throw new CloudException('provider_busy');
                if ($resource->manual_hold || !$resource->armed) throw new CloudException('manual_hold');
                if (!$resource->last_running_at || $resource->last_running_at < time() - 600) throw new CloudException('baseline_required');
                $resource->offline_since ??= time(); $resource->offline_samples++;
                if ($resource->offline_samples < 2 || $resource->offline_since > time() - 55) { $resource->recovery_status = 'confirming_offline'; $resource->save(); return; }
                if ($resource->last_auto_boot_at > time() - 3600 || Task::query()->where('resource_id', $resource->id)->where('automatic', true)->where('created_at', '>=', time() - 86400)->count() >= 2) throw new CloudException('auto_budget');
                $events = $this->provider->safety($account, $resource->external_id, $resource->last_running_at);
                $this->assertIdle($events); $this->assertNoShutdown($events, $resource->last_running_at);
                $resource->save();
                $this->accept($resource, $account, 'boot', hash('sha256', 'auto:' . $resource->id . ':' . $resource->offline_since), (int) $resource->policy_admin_id, true);
            } catch (CloudException $error) {
                $code = $error->getMessage();
                if (in_array($code, ['external_shutdown', 'identity_changed', 'permission_revoked'], true)) { $resource->manual_hold = true; $resource->armed = false; }
                $resource->fill(['recovery_status' => $code, 'next_check_at' => time() + 300])->save();
            }
        });
    }

    public function schedule(): array
    {
        if (!$this->available()) return ['tasks' => 0, 'checks' => 0];
        $tasks = Task::query()->whereNotIn('status', Task::TERMINAL)->where('next_check_at', '<=', time())->orderBy('next_check_at')->limit(25)->get();
        foreach ($tasks as $task) MachineCloudPowerJob::dispatch((int) $task->id);
        $resources = Resource::query()->where('auto_boot', true)->whereNull('active_task_id')->where('next_check_at', '<=', time())->orderBy('next_check_at')->limit(25)->get();
        foreach ($resources as $resource) CheckMachineCloudJob::dispatch((int) $resource->id, $resource->revision);
        return ['tasks' => $tasks->count(), 'checks' => $resources->count()];
    }

    private function assertIdle(array $events): void
    {
        if (array_filter($events, fn ($event) => in_array($event['status'], ['scheduled', 'started', 'in_progress'], true))) throw new CloudException('provider_busy');
    }
    private function assertNoShutdown(array $events, int $since): void
    {
        if (array_filter($events, fn ($event) => $event['at'] >= $since && $event['action'] === 'linode_shutdown'
            && !in_array($event['status'], ['failed', 'canceled'], true))) throw new CloudException('external_shutdown');
    }
    private function adminActive(int $id): bool
    {
        $admin = User::query()->find($id);
        return $admin && $admin->is_admin && !$admin->banned;
    }
}

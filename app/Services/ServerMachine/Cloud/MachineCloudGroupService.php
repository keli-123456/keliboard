<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudGroup as Group;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineCloudGroupService
{
    public function available(): bool
    {
        return Schema::hasTable('v2_machine_cloud_group') && Schema::hasTable('v2_machine_cloud_account')
            && Schema::hasColumn('v2_machine_cloud_account', 'group_id') && Schema::hasColumn('v2_machine_cloud_account', 'group_revision');
    }

    public function overview(): array
    {
        return $this->available() ? Group::query()->orderBy('name')->orderBy('id')->get()->toArray() : [];
    }

    private function locked(callable $work): mixed
    {
        if (!$this->available()) throw new CloudException('groups_migration_required');
        return Cache::lock('machine-cloud:credential-groups', 120)->block(2, $work);
    }

    public function withGroup(?int $id, callable $work): mixed
    {
        return $this->locked(function () use ($id, $work) {
            if ($id !== null && !Group::query()->whereKey($id)->exists()) throw new CloudException('group_missing');
            return $work();
        });
    }

    public function save(array $input): Group
    {
        $name = (string) ($input['name'] ?? '');
        if (preg_match('/[\p{C}]/u', $name) !== 0) throw new CloudException('invalid_group_name');
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 60) throw new CloudException('invalid_group_name');
        $key = hash('sha256', mb_strtolower($name));
        return $this->locked(function () use ($input, $name, $key) {
            $group = isset($input['id']) ? Group::query()->find($input['id']) : new Group();
            if (!$group) throw new CloudException('group_missing');
            if ($group->exists && $group->revision !== ($input['expected_revision'] ?? null)) throw new CloudException('config_changed');
            if (Group::query()->where('name_key', $key)->when($group->exists, fn ($q) => $q->where('id', '!=', $group->id))->exists()) throw new CloudException('group_name_taken');
            if (!$group->exists && Group::query()->count() >= 100) throw new CloudException('group_limit');
            $group->fill(['name' => $name, 'name_key' => $key, 'revision' => ($group->revision ?? 0) + 1])->save();
            return $group->fresh();
        });
    }

    public function assign(int $account, ?int $group, int $revision): Account
    {
        // Use the same group -> account lock order as grouped credential creation.
        return $this->withGroup($group, fn () => Cache::lock('machine-cloud:account:' . $account, 120)->block(2, function () use ($account, $group, $revision) {
            $row = Account::query()->findOrFail($account);
            if ($row->group_revision !== $revision) throw new CloudException('group_changed');
            if ($row->group_id !== $group) $row->fill(['group_id' => $group, 'group_revision' => $row->group_revision + 1])->save();
            return $row;
        }));
    }

    public function delete(int $id, int $revision): void
    {
        $this->locked(function () use ($id, $revision) {
            $group = Group::query()->find($id);
            if (!$group) throw new CloudException('group_missing');
            if ($group->revision !== $revision) throw new CloudException('config_changed');
            if (Schema::hasColumn('v2_machine_failover_policy', 'credential_group_id')
                && \App\Models\MachineFailoverPolicy::query()->where('credential_group_id', $id)->exists()) throw new CloudException('group_in_use');
            DB::transaction(function () use ($group) {
                Account::query()->where('group_id', $group->id)->update(['group_id' => null, 'group_revision' => DB::raw('group_revision + 1')]);
                $group->delete();
            });
        });
    }
}

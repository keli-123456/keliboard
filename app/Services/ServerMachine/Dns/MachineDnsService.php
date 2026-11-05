<?php

namespace App\Services\ServerMachine\Dns;

use App\Jobs\SyncMachineDnsJob;
use App\Models\MachineDnsBinding;
use App\Models\MachineDnsCredential;
use App\Models\ServerMachine;
use App\Services\Node\NodeReportSource;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineDnsService
{
    public function __construct(private DnsProviderClient $provider, private MachineAddressInventory $addresses) {}

    public function available(): bool
    {
        return Schema::hasTable('v2_machine_dns_credential') && Schema::hasTable('v2_machine_dns_binding') && Schema::hasTable('v2_machine_dns_log');
    }

    public function overview(): array
    {
        if (!$this->available()) return ['available' => false, 'credentials' => [], 'bindings' => [], 'logs' => []];
        $credentials = MachineDnsCredential::query()->orderBy('id')->get(['id', 'name', 'provider', 'revision']);
        $bindings = MachineDnsBinding::query()->orderBy('id')->get();
        $machines = ServerMachine::query()->whereIn('id', $bindings->pluck('machine_id')->unique())->get()->keyBy('id');
        return ['available' => true, 'credentials' => $credentials, 'bindings' => $bindings->map(function ($binding) use ($machines) {
            $row = $binding->toArray();
            $row['machine_name'] = $machines->get($binding->machine_id)?->name;
            return $row;
        }), 'logs' => DB::table('v2_machine_dns_log')->orderByDesc('id')->limit(100)->get()];
    }

    public function instances(int $machineId): array
    {
        $machine = ServerMachine::query()->findOrFail($machineId);
        $rows = $this->addresses->snapshots([$machine])[$machineId] ?? [];
        $result = [];
        foreach ($rows as $id => $row) {
            if (!NodeReportSource::installation($id)) continue;
            $entry = ['installation_id' => $id, 'seen_at' => (int) ($row['at'] ?? 0), 'ipv4' => null, 'ipv6' => null];
            foreach (['A' => 'ipv4', 'AAAA' => 'ipv6'] as $type => $key) {
                try { $entry[$key] = $this->addressFromRow($row, $type); } catch (DnsException) {}
            }
            $result[] = $entry;
        }
        return $result;
    }

    private function lock(string $key, callable $work): mixed
    {
        return Cache::lock('machine-dns:' . $key, 90)->block(2, $work);
    }

    public function catalog(array $input, bool $records): array
    {
        $credential = MachineDnsCredential::query()->findOrFail($input['credential_id']);
        $page = (int) ($input['page'] ?? 1);
        $search = trim((string) ($input['search'] ?? ''));
        if ($page < 1 || $page > 10000 || strlen($search) > 253) throw new DnsException('invalid_record');
        if (!$records) return $this->provider->zones($credential, $page, $search);
        $zone = strtolower(rtrim($input['zone'], '.'));
        $this->validateZone($credential, $zone);
        if (!in_array($input['type'], ['A', 'AAAA'], true)) throw new DnsException('invalid_record');
        return $this->provider->records($credential, $zone, $input['type'], $page, $search);
    }

    public function preview(array $input): array
    {
        $credential = MachineDnsCredential::query()->findOrFail($input['credential_id']);
        $machine = ServerMachine::query()->findOrFail($input['machine_id']);
        $binding = $this->bindingDraft($input, $credential, $machine);
        if (MachineDnsBinding::query()->where('record_scope', $binding->record_scope)->exists()) throw new DnsException('record_in_use');
        $target = $this->target($binding, $machine);
        $record = $this->provider->inspect($credential, $binding);
        $this->assertRecord($binding, $record);
        return ['current_ip' => $this->literal($record['content'], $binding->type), 'target_ip' => $target,
            'credential_revision' => $credential->revision, 'checked_at' => time(),
            'source_seen_at' => $this->addresses->snapshots([$machine])[$machine->id][$binding->installation_id]['at'] ?? null,
            'ttl' => $record['ttl'], 'proxied' => $record['proxied'] ?? false, 'line' => $record['line'] ?? null];
    }

    private function validateZone(MachineDnsCredential $credential, string $zone): void
    {
        if ($credential->provider === 'cloudflare' ? !preg_match('/^[a-f0-9]{32}$/D', $zone) : !$this->hostname($zone)) throw new DnsException('invalid_record');
    }

    private function bindingDraft(array $input, MachineDnsCredential $credential, ServerMachine $machine): MachineDnsBinding
    {
        $hostname = strtolower(rtrim($input['hostname'], '.'));
        $zone = strtolower(rtrim($input['zone'], '.'));
        $this->validateZone($credential, $zone);
        if (!$this->hostname($hostname) || !NodeReportSource::installation($input['installation_id']) || !in_array($input['type'], ['A', 'AAAA'], true)
            || !preg_match($credential->provider === 'cloudflare' ? '/^[a-f0-9]{32}$/D' : '/^[0-9]{1,40}$/D', $input['record_id'])
            || ($credential->provider !== 'cloudflare' && $hostname !== $zone && !str_ends_with($hostname, '.' . $zone))) throw new DnsException('invalid_record');
        return new MachineDnsBinding(array_intersect_key($input, array_flip(['credential_id', 'machine_id', 'installation_id', 'record_id', 'type']))
            + ['hostname' => $hostname, 'zone' => $zone, 'record_scope' => hash('sha256', $credential->provider . ':' . $zone . ':' . $input['record_id']),
                'machine_authority' => $this->authority($machine)]);
    }

    public function saveCredential(array $input, int $admin): array
    {
        return $this->lock('credential:' . ($input['id'] ?? 'new'), function () use ($input, $admin) {
            $row = isset($input['id']) ? MachineDnsCredential::query()->findOrFail($input['id']) : new MachineDnsCredential();
            if ($row->exists && (int) $row->revision !== ($input['expected_revision'] ?? null)) throw new DnsException('config_changed');
            if ($row->exists && $row->provider !== $input['provider']) throw new DnsException('config_changed');
            if (!$row->exists && MachineDnsCredential::query()->count() >= 100) throw new DnsException('limit_reached');
            $keys = $row->exists ? $row->credentials : [];
            foreach ($input['provider'] === 'cloudflare' ? ['api_token'] : ['access_key_id', 'access_key_secret'] as $key) {
                if (is_string($input[$key] ?? null) && trim($input[$key]) !== '') $keys[$key] = trim($input[$key]);
                if (empty($keys[$key])) throw new DnsException('credentials_required');
            }
            $row->fill(['name' => $input['name'], 'provider' => $input['provider'], 'credentials' => $keys, 'revision' => ($row->revision ?? 0) + 1]);
            DB::transaction(function () use ($row, $admin) {
                $row->save();
                $this->audit('credential_saved', null, $row->id, $admin);
            });
            return ['id' => $row->id, 'name' => $row->name, 'provider' => $row->provider, 'revision' => $row->revision];
        });
    }

    public function deleteCredential(int $id, int $revision, int $admin): void
    {
        $this->lock('credential:' . $id, function () use ($id, $revision, $admin) {
            $row = MachineDnsCredential::query()->findOrFail($id);
            if ($row->revision !== $revision) throw new DnsException('config_changed');
            if (MachineDnsBinding::query()->where('credential_id', $id)->exists()) throw new DnsException('credential_in_use');
            DB::transaction(function () use ($row, $admin) {
                $this->audit('credential_deleted', null, $row->id, $admin);
                $row->delete();
            });
        });
    }

    public function createBinding(array $input, int $admin): MachineDnsBinding
    {
        return $this->lock('credential:' . $input['credential_id'], function () use ($input, $admin) {
            $credential = MachineDnsCredential::query()->findOrFail($input['credential_id']);
            if (MachineDnsBinding::query()->count() >= 500) throw new DnsException('limit_reached');
            $machine = ServerMachine::query()->findOrFail($input['machine_id']);
            $binding = $this->bindingDraft($input, $credential, $machine);
            return $this->lock('record:' . $binding->record_scope, function () use ($input, $admin, $credential, $machine, $binding) {
                if (MachineDnsBinding::query()->where('record_scope', $binding->record_scope)->exists()) throw new DnsException('record_in_use');
                $target = $this->target($binding, $machine);
                $record = $this->provider->inspect($credential, $binding);
                $this->assertRecord($binding, $record);
                $current = $this->literal($record['content'], $binding->type);
                if (isset($input['preview_checked_at'])) {
                    if ($input['preview_checked_at'] < time() - 120 || $input['preview_checked_at'] > time() + 30) throw new DnsException('preview_expired');
                    if ($credential->revision !== $input['credential_revision']) throw new DnsException('config_changed');
                    if ($current !== $this->literal($input['expected_current_ip'], $binding->type)) throw new DnsException('preview_record_changed');
                    if ($target !== $this->literal($input['expected_target_ip'], $binding->type)) throw new DnsException('source_changed');
                }
                $binding->fill(['last_ip' => $current, 'enabled' => false,
                    'status' => 'disabled', 'revision' => 1, 'last_verified_at' => time()]);
                DB::transaction(function () use ($binding, $admin) {
                    $binding->save();
                    $this->audit('binding_created', $binding, $binding->credential_id, $admin);
                });
                return $binding;
            });
        });
    }

    public function changeBinding(int $id, int $revision, bool $enabled, bool $delete, int $admin): void
    {
        $this->lock('binding:' . $id, function () use ($id, $revision, $enabled, $delete, $admin) {
            $binding = MachineDnsBinding::query()->findOrFail($id);
            if ($binding->revision !== $revision) throw new DnsException('config_changed');
            DB::transaction(function () use ($binding, $enabled, $delete, $admin) {
                $this->audit($delete ? 'binding_deleted' : ($enabled ? 'binding_enabled' : 'binding_disabled'), $binding, $binding->credential_id, $admin);
                if ($delete) { $binding->delete(); return; }
                $binding->fill(['enabled' => $enabled, 'revision' => $binding->revision + 1,
                    'status' => $enabled ? 'pending' : 'disabled', 'last_verified_at' => null, 'next_attempt_at' => 0])->save();
            });
        });
    }

    public function queue(int $id, int $revision, int $admin): void
    {
        $this->lock('binding:' . $id, function () use ($id, $revision, $admin) {
            $binding = MachineDnsBinding::query()->findOrFail($id);
            if (!$binding->enabled) throw new DnsException('disabled');
            if ($binding->revision !== $revision) throw new DnsException('config_changed');
            $binding->last_verified_at = null;
            $binding->status = 'pending';
            $binding->save();
            SyncMachineDnsJob::dispatch($id, $revision);
            $this->audit('sync_queued', $binding, $binding->credential_id, $admin);
        });
    }

    public function schedule(): int
    {
        if (!$this->available()) return 0;
        $rows = MachineDnsBinding::query()->where('enabled', true)->where('next_attempt_at', '<=', time())->orderBy('next_attempt_at')->limit(50)->get();
        foreach ($rows as $binding) SyncMachineDnsJob::dispatch((int) $binding->id, $binding->revision);
        $old = DB::table('v2_machine_dns_log')->where('created_at', '<', time() - 86400 * 30)->orderBy('id')->limit(1000)->pluck('id');
        if ($old->isNotEmpty()) DB::table('v2_machine_dns_log')->whereIn('id', $old)->delete();
        return $rows->count();
    }

    public function sync(int $id, int $revision): void
    {
        $initial = MachineDnsBinding::query()->find($id);
        if (!$initial) return;
        $this->lock('credential:' . $initial->credential_id, function () use ($id, $revision) {
            $this->lock('binding:' . $id, function () use ($id, $revision) {
                $binding = MachineDnsBinding::query()->find($id);
                if (!$binding || !$binding->enabled || $binding->revision !== $revision) return;
                $desired = null;
                try {
                    $machine = ServerMachine::query()->find($binding->machine_id);
                    if (!$machine || !hash_equals($binding->machine_authority, $this->authority($machine))) throw new DnsException('identity_changed');
                    $credential = MachineDnsCredential::query()->find($binding->credential_id);
                    if (!$credential) throw new DnsException('credentials_required');
                    $desired = $this->target($binding, $machine);
                    if ($desired === $binding->last_ip && $binding->last_verified_at > time() - 900) {
                        $binding->fill(['status' => 'synced', 'next_attempt_at' => time() + 60])->save();
                        return;
                    }
                    $record = $this->provider->inspect($credential, $binding);
                    $this->assertRecord($binding, $record);
                    $before = $this->literal($record['content'], $binding->type);
                    if ($before !== $binding->last_ip && $before !== $desired) throw new DnsException('record_changed');
                    if ($before !== $desired) $this->provider->update($credential, $binding, $record, $desired);
                    $verified = $this->provider->inspect($credential, $binding);
                    $this->assertRecord($binding, $verified);
                    if ($this->literal($verified['content'], $binding->type) !== $desired) throw new DnsException('verification_failed');
                    DB::transaction(function () use ($binding, $before, $desired) {
                        if ($before !== $desired || $binding->status !== 'synced') $this->audit('synced', $binding, $binding->credential_id, null, $before, $desired);
                        $binding->fill(['last_ip' => $desired, 'last_verified_at' => time(), 'next_attempt_at' => time() + 60, 'status' => 'synced'])->save();
                    });
                } catch (\Throwable $error) {
                    $status = $error instanceof DnsException ? $error->getMessage() : 'sync_failed';
                    $halt = in_array($status, ['identity_changed', 'record_changed', 'record_mismatch', 'record_locked'], true);
                    DB::transaction(function () use ($binding, $status, $halt, $desired) {
                        if ($binding->status !== $status) $this->audit($status, $binding, $binding->credential_id, null, $binding->last_ip, $desired);
                        $binding->fill(['status' => $status, 'enabled' => $halt ? false : true,
                            'next_attempt_at' => time() + 300, 'revision' => $binding->revision + ($halt ? 1 : 0)])->save();
                    });
                }
            });
        });
    }

    private function target(MachineDnsBinding $binding, ServerMachine $machine): string
    {
        if (!$machine->is_active) throw new DnsException('source_unavailable');
        if (Schema::hasTable('v2_machine_cloud_resource') && DB::table('v2_machine_cloud_resource')->where('machine_id', $machine->id)
            ->where('installation_id', $binding->installation_id)->where('dns_hold', true)->exists()) throw new DnsException('cloud_recovery_pending');
        $row = $this->addresses->snapshots([$machine])[$machine->id][$binding->installation_id] ?? null;
        if (!$row || ($row['at'] ?? 0) < time() - 180 || ($row['at'] ?? 0) > time() + 30) throw new DnsException('source_unavailable');
        return $this->addressFromRow($row, $binding->type);
    }

    private function addressFromRow(array $row, string $type): string
    {
        $field = $type === 'A' ? 'public_ipv4' : 'public_ipv6';
        $ip = $this->literal($row['ip_observation']['current'][$field] ?? '', $type);
        // The IP must also occur in this report, not only in retained history or the observed HTTP peer.
        $present = false;
        foreach (($row['ips'] ?? []) as $entry) if (($entry['ip'] ?? null) === $ip && ($entry['observed'] ?? true) === false) $present = true;
        if (!$present) throw new DnsException('source_unavailable');
        return $ip;
    }

    private function literal(mixed $value, string $type): string
    {
        $ip = MachineEntryProbe::publicIp($value);
        if (!$ip || str_contains($ip, ':') !== ($type === 'AAAA')) throw new DnsException('source_unavailable');
        return $ip;
    }

    private function assertRecord(MachineDnsBinding $binding, array $record): void
    {
        if (($record['hostname'] ?? '') !== $binding->hostname || ($record['type'] ?? '') !== $binding->type
            || (isset($record['zone']) && $record['zone'] !== $binding->zone)) throw new DnsException('record_mismatch');
        if ($record['locked'] ?? false) throw new DnsException('record_locked');
    }

    private function authority(ServerMachine $machine): string { return hash('sha256', (string) $machine->token); }
    private function hostname(string $value): bool
    {
        return strlen($value) <= 253 && str_contains($value, '.') && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function audit(string $action, ?MachineDnsBinding $binding, ?int $credential, ?int $admin, ?string $from = null, ?string $to = null): void
    {
        DB::table('v2_machine_dns_log')->insert(['binding_id' => $binding?->id, 'credential_id' => $credential, 'admin_id' => $admin,
            'action' => $action, 'hostname' => $binding?->hostname, 'from_ip' => $from, 'to_ip' => $to, 'created_at' => time()]);
    }
}

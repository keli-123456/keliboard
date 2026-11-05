<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudResource;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\Node\NodeMachineBinding;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\UserOnlineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineCloudHealth
{
    public function __construct(private MachineAddressInventory $addresses, private NodeInstanceRegistry $nodes) {}

    public function snapshot(MachineCloudResource $resource, int $after = 0): array
    {
        $now = time();
        if (!$resource->machine_id || !$resource->installation_id) return ['agent' => 'unbound', 'nodes' => 'unknown', 'ready' => false];
        $machine = ServerMachine::query()->find($resource->machine_id);
        if (!$machine || !$machine->is_active || !hash_equals((string) $resource->machine_authority, hash('sha256', (string) $machine->token))) {
            return ['agent' => 'identity_changed', 'nodes' => 'unknown', 'ready' => false];
        }
        $row = $this->addresses->snapshots([$machine])[$machine->id][$resource->installation_id] ?? [];
        $at = (int) ($row['at'] ?? 0);
        if ($after && $at <= $after && $at >= $now - 180) return ['agent' => 'waiting', 'nodes' => 'unknown', 'ready' => false];
        if ($at < max($after ? $after + 1 : 0, $now - 180) || $at > $now + 30) return ['agent' => 'stale', 'nodes' => 'unknown', 'ready' => false];
        $explicit = array_column(array_filter($row['ips'] ?? [], fn ($ip) => ($ip['observed'] ?? true) === false), 'ip');
        if (!array_intersect($explicit, $resource->public_ips ?? [])) return ['agent' => 'address_mismatch', 'nodes' => 'unknown', 'ready' => false];
        if (!Schema::hasTable('v2_server')) return ['agent' => 'fresh', 'nodes' => 'unknown', 'ready' => false];
        $ids = NodeMachineBinding::available() ? DB::table('v2_server_machine_binding')->where('machine_id', $machine->id)->pluck('server_id') : [];
        $nodes = Server::query()->where('enabled', true)->where(fn ($query) => $query->where('machine_id', $machine->id)->orWhereIn('id', $ids))->get(['id']);
        $since = max($after ? $after + 1 : 0, $now - min(180, UserOnlineService::nodeDataExpirySeconds()));
        foreach ($nodes as $node) {
            $matches = array_filter($this->nodes->snapshot($node->id), fn ($report) => ($report['machine_id'] ?? null) === $machine->id
                && ($report['installation_id'] ?? null) === $resource->installation_id
                && $this->freshNodeReport($report, $since, $now));
            if (!$matches) return ['agent' => 'fresh', 'nodes' => 'waiting', 'ready' => false];
        }
        return ['agent' => 'fresh', 'nodes' => $nodes->isEmpty() ? 'not_bound' : 'fresh', 'ready' => true];
    }

    private function freshNodeReport(array $report, int $since, int $now): bool
    {
        // These are panel receive times; config polls and generic heartbeats are not recovery evidence.
        foreach (['last_status_at', 'last_online_at', 'last_traffic_at'] as $field) {
            $at = $report[$field] ?? null;
            if (is_int($at) && $at >= $since && $at <= $now + 30) return true;
        }
        return false;
    }
}

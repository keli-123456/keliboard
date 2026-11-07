<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverPolicy;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask;
use App\Models\MachineCloudResource;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Node\NodeMachineBinding;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Support\Facades\DB;

class MachineAcceptanceEvidence
{
    public function __construct(private MachineAddressInventory $addresses, private MachineCloudHealth $health) {}

    public function capture(MachineOwnedInstance $instance, MachineFailoverPolicy $policy): array
    {
        $task = MachineProvisionTask::query()->findOrFail($instance->task_id);
        $machine = ServerMachine::query()->findOrFail($policy->machine_id);
        $resource = new MachineCloudResource(['machine_id' => $policy->machine_id, 'installation_id' => $instance->installation_id,
            'machine_authority' => $task->machine_authority, 'public_ips' => $task->public_ips]);
        $health = $this->health->snapshot($resource, $task->dispatched_at);
        if (!$health['ready'] || $health['nodes'] !== 'fresh') throw new CloudException('reports_not_ready');
        $row = $this->addresses->snapshots([$machine], true)[$machine->id][$instance->installation_id] ?? [];
        $runtime = $row['runtime'] ?? [];
        if (($runtime['binary_sha256'] ?? null) !== $instance->binary_sha256
            || ltrim((string) ($runtime['version'] ?? ''), 'v') !== ltrim($instance->version, 'v')
            || !is_string($runtime['observation_session'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $runtime['observation_session'])) throw new CloudException('runtime_not_verified');
        $probe = $this->addresses->probes([$instance->installation_id => $row], time())[$instance->installation_id] ?? [];
        if (($probe['address_installation_id'] ?? null) !== $instance->installation_id
            || ($probe['checked_at'] ?? 0) <= $task->dispatched_at || ($probe['addresses'][$instance->ip]['status'] ?? null) !== 'reachable') throw new CloudException('ipv4_not_verified');
        $bindings = NodeMachineBinding::available() ? DB::table('v2_server_machine_binding')->where('machine_id', $machine->id)->pluck('server_id') : [];
        $nodes = Server::query()->where('enabled', true)->where(fn ($query) => $query->where('machine_id', $machine->id)->orWhereIn('id', $bindings))->orderBy('id')->get();
        if ($nodes->isEmpty() || $nodes->count() > 128) throw new CloudException('invalid_node_group');
        $projection = []; $configs = [];
        foreach ($nodes as $node) {
            $port = $node->server_port ?: $node->port;
            if (!is_numeric($port) || (int) $port < 1 || (int) $port > 65535 || (string) (int) $port !== (string) $port) throw new CloudException('invalid_node_group');
            $projection[] = ['id' => $node->id, 'protocol' => $node->type === 'hysteria' ? 'hysteria2' : $node->type, 'port' => (int) $port];
            $configs[] = array_intersect_key($node->getAttributes(), array_flip(['id', 'type', 'host', 'port', 'server_port', 'network',
                'protocol_settings', 'password', 'group_ids', 'route_ids', 'enabled', 'machine_id', 'parent_id']));
        }
        if (($runtime['configured_nodes'] ?? null) !== $projection) throw new CloudException('configuration_not_verified');
        return ['configuration_fingerprint' => hash('sha256', json_encode($configs, JSON_THROW_ON_ERROR)), 'nodes' => $projection,
            'session' => $runtime['observation_session'], 'binary_sha256' => $instance->binary_sha256, 'ip' => $instance->ip];
    }
}

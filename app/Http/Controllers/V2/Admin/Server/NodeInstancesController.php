<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\Node\NodeMachineBinding;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\ServerMachine\MachineIdentityGuard;
use App\Services\ServerMachine\MachineTelemetryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NodeInstancesController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $node = Server::query()->findOrFail((int) $request->input('id'));
        $ids = NodeMachineBinding::machineIds($node);
        $machines = ServerMachine::query()->whereIn('id', $ids)->orderBy('id')->get();
        $observed = app(NodeInstanceRegistry::class)->snapshot((int) $node->id);
        $traffic = Schema::hasTable('v2_server_instance_traffic') ? DB::table('v2_server_instance_traffic')
            ->where('server_id', $node->id)->where('record_at', strtotime(date('Y-m-d')))->limit(64)->get()->keyBy('reporter_key') : collect();
        $receipts = [];
        foreach ($observed as $row) foreach (['config', 'users'] as $topic) $receipts[] = 'node_instance_receipt:' . $node->id . ':' . $row['key'] . ':' . $topic;
        $receiptRows = $receipts ? Cache::many($receipts) : [];
        $sources = array_map(function ($row) use ($traffic, $receiptRows, $node): array {
            $stat = $traffic->get($row['key']);
            return $row + ['today_upload' => $stat ? (int) $stat->upload : null, 'today_download' => $stat ? (int) $stat->download : null,
                'accounting' => $stat ? 'durable_receipts' : 'unavailable',
                'config_receipt' => $receiptRows['node_instance_receipt:' . $node->id . ':' . $row['key'] . ':config'] ?? null,
                'users_receipt' => $receiptRows['node_instance_receipt:' . $node->id . ':' . $row['key'] . ':users'] ?? null];
        }, $observed);
        $compatible = true;
        try { NodeMachineBinding::assertCompatible($node); } catch (\Illuminate\Validation\ValidationException) { $compatible = false; }
        return $this->success([
            'node_id' => (int) $node->id, 'name' => $node->name, 'revision' => NodeMachineBinding::revision($node),
            'config_updated_at' => $node->updated_at,
            'migration_ready' => NodeMachineBinding::available(), 'sharing_compatible' => $compatible,
            'primary_machine_id' => $node->machine_id, 'observed_at' => time(), 'sources' => $sources,
            'machines' => $machines->map(function ($machine) use ($node): array {
                $status = app(MachineTelemetryService::class)->snapshot($machine)['load_status'];
                return ['id' => (int) $machine->id, 'name' => $machine->name, 'is_active' => (bool) $machine->is_active,
                    'role' => (int) $node->machine_id === (int) $machine->id ? 'primary' : 'replica',
                    'last_seen_at' => $machine->last_seen_at, 'ip' => data_get($status, 'ip.public_ipv4') ?: data_get($status, 'ip.panel_seen'),
                    'identity_conflict' => app(MachineIdentityGuard::class)->conflicted((int) $machine->id),
                    'cpu' => data_get($status, 'cpu'), 'memory_used' => data_get($status, 'mem.used'), 'memory_total' => data_get($status, 'mem.total')];
            })->values()->all(),
            'available_machines' => ServerMachine::query()->orderBy('id')->limit(500)->get(['id', 'name', 'is_active'])->toArray(),
        ])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, NodeMachineBinding $bindings)
    {
        $data = $request->validate(['id' => 'required|integer|min:1', 'replica_machine_ids' => 'present|array|max:16',
            'replica_machine_ids.*' => 'required|integer|min:1|distinct', 'revision' => 'required|string|size:64']);
        $affected = $bindings->replaceReplicas((int) $data['id'], $data['replica_machine_ids'], $data['revision']);
        app(NodeRealtimePublisher::class)->invalidateConfigForMachines($affected, 'admin.server_machine.bound', ['server_ids' => [(int) $data['id']]]);
        return $this->success(true);
    }
}

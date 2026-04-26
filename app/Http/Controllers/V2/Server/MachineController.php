<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MachineController extends Controller
{
    public function nodes(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);
        if (!$machine) {
            return response()->json(['message' => 'Invalid machine credentials'], 401);
        }

        $this->touchMachine($machine);

        $nodes = ServerService::getMachineNodes($machine)
            ->map(function ($server): array {
                return [
                    'id' => (int) $server->id,
                    'code' => $server->code,
                    'type' => $server->type,
                    'name' => $server->name,
                    'updated_at' => $server->updated_at,
                ];
            })
            ->values();

        $settings = app(NodeRealtimeSettings::class);
        $wsURL = $settings->resolvedPublicUrl();
        $realtimeEnabled = $settings->enabled() && $wsURL !== '';

        return response()->json([
            'nodes' => $nodes,
            'base_config' => [
                'push_interval' => (int) admin_setting('server_push_interval', 60),
                'pull_interval' => (int) admin_setting('server_pull_interval', 60),
                'realtime' => [
                    'enabled' => $realtimeEnabled,
                    'url' => $realtimeEnabled ? $wsURL : '',
                    'ping_interval' => (int) admin_setting('server_realtime_ping_interval', 30),
                ],
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);
        if (!$machine) {
            return response()->json(['message' => 'Invalid machine credentials'], 401);
        }

        $payload = $request->input('status');
        if (!is_array($payload)) {
            $payload = $request->only(['cpu', 'mem', 'swap', 'disk', 'net', 'uptime', 'version']);
        }

        $validator = Validator::make($payload, [
            'cpu' => 'nullable|numeric|min:0|max:100',
            'mem.total' => 'nullable|integer|min:0',
            'mem.used' => 'nullable|integer|min:0',
            'swap.total' => 'nullable|integer|min:0',
            'swap.used' => 'nullable|integer|min:0',
            'disk.total' => 'nullable|integer|min:0',
            'disk.used' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid status payload'], 422);
        }

        $status = [
            'cpu' => (float) data_get($payload, 'cpu', 0),
            'mem' => [
                'total' => (int) data_get($payload, 'mem.total', 0),
                'used' => (int) data_get($payload, 'mem.used', 0),
            ],
            'swap' => [
                'total' => (int) data_get($payload, 'swap.total', 0),
                'used' => (int) data_get($payload, 'swap.used', 0),
            ],
            'disk' => [
                'total' => (int) data_get($payload, 'disk.total', 0),
                'used' => (int) data_get($payload, 'disk.used', 0),
            ],
            'net' => data_get($payload, 'net'),
            'uptime' => data_get($payload, 'uptime'),
            'version' => data_get($payload, 'version'),
            'updated_at' => now()->timestamp,
        ];

        $machine->forceFill([
            'last_seen_at' => now()->timestamp,
            'load_status' => $status,
        ])->save();

        ServerMachineLoadHistory::create([
            'machine_id' => (int) $machine->id,
            'cpu' => $status['cpu'],
            'mem_total' => $status['mem']['total'],
            'mem_used' => $status['mem']['used'],
            'swap_total' => $status['swap']['total'],
            'swap_used' => $status['swap']['used'],
            'disk_total' => $status['disk']['total'],
            'disk_used' => $status['disk']['used'],
            'load_status' => $status,
        ]);

        ServerMachineLoadHistory::where('machine_id', (int) $machine->id)
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        return response()->json(['data' => true]);
    }

    private function authenticateMachine(Request $request): ?ServerMachine
    {
        $machineId = $request->input('machine_id');
        $token = trim((string) $request->input('token', ''));
        if (!is_scalar($machineId) || (int) $machineId <= 0 || $token === '') {
            return null;
        }

        $machine = ServerMachine::query()
            ->whereKey((int) $machineId)
            ->where('is_active', true)
            ->first();
        if (!$machine || !hash_equals((string) $machine->token, $token)) {
            return null;
        }

        return $machine;
    }

    private function touchMachine(ServerMachine $machine): void
    {
        $machine->forceFill(['last_seen_at' => now()->timestamp])->save();
    }
}

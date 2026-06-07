<?php

namespace App\Services\NodeRealtime;

use App\Models\Server as ServerModel;
use App\Models\ServerMachine;
use App\Services\ServerService;
use Illuminate\Support\Facades\Schema;

class NodeRealtimeAuthenticator
{
    public function authenticate(array $params): ?array
    {
        $token = trim((string) ($params['token'] ?? ''));
        $nodeId = $params['node_id'] ?? null;
        $machineId = $params['machine_id'] ?? null;
        $rawNodeType = $params['node_type'] ?? null;
        $isV2Node = $rawNodeType === 'v2node';
        $nodeType = $isV2Node ? null : $rawNodeType;

        if ($token === '') {
            return null;
        }

        if ($this->isMachineOnlyV2Node($machineId, $nodeId, $isV2Node)) {
            return $this->authenticateMachineOnly((int) $machineId, $token);
        }

        $nodeType = is_string($nodeType) ? trim($nodeType) : null;
        if ($nodeType !== null && !ServerModel::isValidType($nodeType)) {
            return null;
        }
        if ($nodeType === null && !$isV2Node) {
            return null;
        }

        $normalizedNodeType = $nodeType !== null ? ServerModel::normalizeType($nodeType) : null;
        if (!is_scalar($nodeId)) {
            return null;
        }

        if ($this->hasMachineId($machineId)) {
            return $this->authenticateMachine(
                (int) $machineId,
                $token,
                (string) $nodeId,
                $normalizedNodeType,
                true
            );
        }

        $serverToken = (string) admin_setting('server_token');
        if ($serverToken === '' || !hash_equals($serverToken, $token)) {
            return null;
        }

        $serverInfo = ServerService::getServer((string) $nodeId, $normalizedNodeType);
        if (!$serverInfo) {
            return null;
        }

        return $this->buildAuthResult($serverInfo, (string) $nodeId, $normalizedNodeType, $isV2Node);
    }

    private function authenticateMachineOnly(int $machineId, string $token): ?array
    {
        $machine = ServerMachine::query()
            ->whereKey($machineId)
            ->where('is_active', true)
            ->first();
        if (!$machine || !hash_equals((string) $machine->token, $token)) {
            return null;
        }

        return [
            'server' => null,
            'machine' => $machine,
            'input_node_id' => '0',
            'normalized_node_type' => null,
            'is_v2node' => true,
            'connection_key' => implode(':', [
                'v2node',
                'machine',
                (string) $machine->id,
            ]),
        ];
    }

    private function authenticateMachine(int $machineId, string $token, string $nodeId, ?string $nodeType, bool $isV2Node): ?array
    {
        $machine = ServerMachine::query()
            ->whereKey($machineId)
            ->where('is_active', true)
            ->first();
        if (!$machine || !hash_equals((string) $machine->token, $token)) {
            return null;
        }

        $query = ServerModel::query()
            ->where('machine_id', (int) $machine->id)
            ->when($nodeType, function ($query) use ($nodeType) {
                $query->where('type', $nodeType);
            })
            ->where(function ($query) use ($nodeId) {
                $query->where('code', $nodeId)
                    ->orWhere('id', $nodeId);
            });
        if ($this->serverEnabledColumnExists()) {
            $query->where('enabled', true);
        }

        $serverInfo = $query
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$nodeId])
            ->first();
        if (!$serverInfo) {
            return null;
        }

        $result = $this->buildAuthResult($serverInfo, $nodeId, $nodeType, $isV2Node);
        $result['machine'] = $machine;
        $result['connection_key'] = implode(':', [
            $isV2Node ? 'v2node' : 'server',
            'machine',
            (string) $machine->id,
            (string) ($nodeType ?? 'default'),
            $nodeId,
            (string) $serverInfo->id,
        ]);

        return $result;
    }

    private function buildAuthResult(ServerModel $serverInfo, string $nodeId, ?string $nodeType, bool $isV2Node): array
    {
        return [
            'server' => $serverInfo,
            'input_node_id' => $nodeId,
            'normalized_node_type' => $nodeType,
            'is_v2node' => $isV2Node,
            'connection_key' => implode(':', [
                $isV2Node ? 'v2node' : 'server',
                (string) ($nodeType ?? 'default'),
                $nodeId,
                (string) $serverInfo->id,
            ]),
        ];
    }

    private function hasMachineId($machineId): bool
    {
        return is_scalar($machineId) && (int) $machineId > 0;
    }

    private function isMachineOnlyV2Node($machineId, $nodeId, bool $isV2Node): bool
    {
        if (!$isV2Node || !$this->hasMachineId($machineId)) {
            return false;
        }
        if (!is_scalar($nodeId)) {
            return true;
        }
        $value = trim((string) $nodeId);
        return $value === '' || $value === '0' || strtolower($value) === 'machine';
    }

    private function serverEnabledColumnExists(): bool
    {
        try {
            return Schema::hasTable('v2_server') && Schema::hasColumn('v2_server', 'enabled');
        } catch (\Throwable) {
            return false;
        }
    }
}

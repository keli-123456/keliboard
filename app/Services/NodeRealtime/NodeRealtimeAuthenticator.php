<?php

namespace App\Services\NodeRealtime;

use App\Models\Server as ServerModel;
use App\Services\ServerService;

class NodeRealtimeAuthenticator
{
    public function authenticate(array $params): ?array
    {
        $token = trim((string) ($params['token'] ?? ''));
        $nodeId = $params['node_id'] ?? null;
        $rawNodeType = $params['node_type'] ?? null;
        $isV2Node = $rawNodeType === 'v2node';
        $nodeType = $isV2Node ? null : $rawNodeType;

        if ($token === '' || !is_scalar($nodeId)) {
            return null;
        }

        $serverToken = (string) admin_setting('server_token');
        if ($serverToken === '' || !hash_equals($serverToken, $token)) {
            return null;
        }

        $nodeType = is_string($nodeType) ? trim($nodeType) : null;
        if (!ServerModel::isValidType($nodeType)) {
            return null;
        }

        $normalizedNodeType = ServerModel::normalizeType($nodeType);
        $serverInfo = ServerService::getServer((string) $nodeId, $normalizedNodeType);
        if (!$serverInfo) {
            return null;
        }

        return [
            'server' => $serverInfo,
            'input_node_id' => (string) $nodeId,
            'normalized_node_type' => $normalizedNodeType,
            'is_v2node' => $isV2Node,
            'connection_key' => implode(':', [
                $isV2Node ? 'v2node' : 'server',
                (string) ($normalizedNodeType ?? 'default'),
                (string) $nodeId,
                (string) $serverInfo->id,
            ]),
        ];
    }
}

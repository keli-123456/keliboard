<?php
namespace App\Services\ServerMachine;

class MachineInstanceRuntime
{
    public function normalize(array $payload): array
    {
        $hash = data_get($payload, 'runtime.binary_sha256');
        $observation = app(MachineRuntimeObservation::class)->normalize(data_get($payload, 'runtime.observation'));
        $nodes = data_get($payload, 'runtime.node_statuses');
        $result = ['binary_sha256' => is_string($hash) && preg_match('/^[a-f0-9]{64}$/Di', $hash) ? strtolower($hash) : null,
            'observation_session' => $observation['session'] ?? null, 'configured_nodes' => null];
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) > 128) return array_filter($result, fn ($value) => $value !== null);
        $normalized = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_int($node['node_id'] ?? null) || $node['node_id'] < 1 || isset($normalized[$node['node_id']])
                || !is_string($node['protocol'] ?? null) || !preg_match('/^[a-z0-9_-]{1,40}$/D', $node['protocol'])
                || !is_int($node['server_port'] ?? null) || $node['server_port'] < 1 || $node['server_port'] > 65535
                || ($node['status'] ?? null) !== 'configured') return array_filter($result, fn ($value) => $value !== null);
            $normalized[$node['node_id']] = ['id' => $node['node_id'], 'protocol' => $node['protocol'], 'port' => $node['server_port']];
        }
        ksort($normalized); $result['configured_nodes'] = array_values($normalized);
        return array_filter($result, fn ($value) => $value !== null);
    }
}

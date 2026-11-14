<?php
declare(strict_types=1);

// A pure checker: no configuration changes, transport, persistence or authorization.
return static function (array $task, array $cloud, array $row, array $summary, array $expected, int $now): array {
    $report = static function (array $checks, string $reason): array {
        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
        return ['schema' => 1, 'ok' => $failed === [], 'reason' => $failed === [] ? null : $reason,
            'failed_fields' => $failed, 'checks' => $checks];
    };
    $scope = [
        'expected_run_id' => is_string($expected['run_id'] ?? null)
            && preg_match('/^keli-accept-[a-z0-9-]{8,80}$/D', $expected['run_id']) === 1,
        'expected_machine_id' => is_int($expected['machine_id'] ?? null) && $expected['machine_id'] > 0,
        'expected_account_id' => is_int($expected['account_id'] ?? null) && $expected['account_id'] > 0,
        'expected_node_id' => is_int($expected['node_id'] ?? null) && $expected['node_id'] > 0,
        'expected_ipv4' => is_string($expected['ip'] ?? null)
            && filter_var($expected['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && App\Services\ServerMachine\MachineEntryProbe::publicIp($expected['ip']) === $expected['ip'],
        'expected_binary' => is_string($expected['binary_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $expected['binary_sha256']) === 1,
        'expected_version' => is_string($expected['version'] ?? null)
            && preg_match('/^v?[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9.-]+)?$/D', $expected['version']) === 1,
        'no_health_claim' => ($expected['health_or_forwarding_claimed'] ?? null) === false,
    ];
    if (in_array(false, $scope, true)) return $report($scope, 'source_fixture_scope_invalid');
    $id = $task['installation_id'] ?? null;
    $probe = is_array($summary['probe'] ?? null) ? $summary['probe'] : [];
    $runtime = is_array($row['runtime'] ?? null) ? $row['runtime'] : [];
    $checks = [
        'installation_id' => is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) === 1,
        'task_label' => ($task['label'] ?? null) === $expected['run_id'] . '-source',
        'task_status' => in_array($task['status'] ?? null, ['waiting_ipv4_probe', 'awaiting_acceptance'], true),
        'bootstrap_state' => ($task['bootstrap_state'] ?? null) === 'succeeded',
        'machine_id' => ($task['machine_id'] ?? null) === $expected['machine_id'],
        'account_id' => ($task['account_id'] ?? null) === $expected['account_id'],
        'dispatched_at' => is_int($task['dispatched_at'] ?? null) && $task['dispatched_at'] > 0 && $task['dispatched_at'] < $now,
        'external_id' => is_string($task['external_id'] ?? null) && $task['external_id'] !== ''
            && $task['external_id'] === ($cloud['external_id'] ?? null),
        'provider_created_at' => is_string($task['provider_created_at'] ?? null) && $task['provider_created_at'] !== ''
            && $task['provider_created_at'] === ($cloud['provider_created_at'] ?? null),
        'cloud_status' => ($cloud['status'] ?? null) === 'running',
        'task_ipv4' => is_array($task['public_ips'] ?? null) && in_array($expected['ip'], $task['public_ips'], true),
        'cloud_ipv4' => is_array($cloud['public_ips'] ?? null) && in_array($expected['ip'], $cloud['public_ips'], true),
        'runtime_binary' => ($runtime['binary_sha256'] ?? null) === $expected['binary_sha256'],
        'runtime_version' => is_string($runtime['version'] ?? null)
            && ltrim($runtime['version'], 'v') === ltrim($expected['version'], 'v'),
        'runtime_session' => is_string($runtime['observation_session'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/D', $runtime['observation_session']) === 1,
        'runtime_nodes' => ($runtime['configured_nodes'] ?? null)
            === [['id' => $expected['node_id'], 'protocol' => 'hysteria2', 'port' => 24443]],
        'agent_at_type' => is_int($row['at'] ?? null),
        'agent_at_future' => is_int($row['at'] ?? null) && $row['at'] <= $now,
        'agent_at_fresh' => is_int($row['at'] ?? null) && $row['at'] >= $now - 30,
        'agent_after_dispatch' => is_int($row['at'] ?? null) && is_int($task['dispatched_at'] ?? null)
            && $row['at'] > $task['dispatched_at'],
        'evidence_current' => ($summary['evidence_current'] ?? null) === true,
        'threshold_reached' => ($summary['threshold_reached'] ?? null) === true,
        'observed_failures' => is_int($summary['observed_failures'] ?? null) && $summary['observed_failures'] >= 3,
        'probe_state' => ($probe['state'] ?? null) === 'failed',
        'probe_installation' => is_string($id) && ($probe['installation_id'] ?? null) === $id,
        'probe_ipv4' => ($probe['ipv4'] ?? null) === [$expected['ip']],
        'probe_at_type' => is_int($probe['checked_at'] ?? null),
        'probe_at_future' => is_int($probe['checked_at'] ?? null) && $probe['checked_at'] <= $now,
        'probe_at_fresh' => is_int($probe['checked_at'] ?? null) && $probe['checked_at'] >= $now - 30,
        'probe_valid_until' => is_int($probe['valid_until'] ?? null) && $probe['valid_until'] > $now,
    ];
    return $report($checks, 'source_fixture_evidence_invalid');
};

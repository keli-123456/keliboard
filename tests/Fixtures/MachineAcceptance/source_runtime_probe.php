<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$root = $argv[1];
$mode = $argv[2];
require_once $root . '/cli_diagnostics.php';
require $root . '/source_fixture.php';
$handler = new Illuminate\Foundation\Bootstrap\HandleExceptions();
set_error_handler([$handler, 'handleError']);
$remembered = null;
try {
    keli_acceptance_cli_guard(static function () use ($mode): void {
        $now = 2000000000;
        $expected = ['run_id' => 'keli-accept-20990101-runtime-offline', 'machine_id' => 17, 'account_id' => 1,
            'node_id' => 1, 'ip' => '45.79.10.20', 'version' => 'v0.1.999',
            'binary_sha256' => str_repeat('b', 64), 'health_or_forwarding_claimed' => false];
        $task = ['installation_id' => str_repeat('a', 32), 'label' => $expected['run_id'] . '-source',
            'status' => 'waiting_ipv4_probe', 'bootstrap_state' => 'succeeded', 'machine_id' => 17, 'account_id' => 1,
            'dispatched_at' => $now - 120, 'external_id' => '123', 'provider_created_at' => '2033-05-18T03:31:20Z',
            'public_ips' => [$expected['ip']]];
        $cloud = array_intersect_key($task, array_flip(['external_id', 'provider_created_at', 'public_ips'])) + ['status' => 'running'];
        $row = ['at' => $now - 1, 'runtime' => ['binary_sha256' => $expected['binary_sha256'], 'version' => 'v0.1.999',
            'observation_session' => str_repeat('c', 32), 'configured_nodes' => [['id' => 1, 'protocol' => 'hysteria2', 'port' => 24443]]]];
        $summary = ['evidence_current' => true, 'threshold_reached' => true, 'observed_failures' => 3,
            'probe' => ['state' => 'failed', 'installation_id' => $task['installation_id'], 'ipv4' => [$expected['ip']],
                'checked_at' => $now - 1, 'valid_until' => $now + 30]];
        if ($mode === 'invalid-evidence') $row['runtime']['configured_nodes'] = [];
        keli_acceptance_source_facts($task, $cloud, $row, $summary, $expected, $now);
    }, static function (array $safe) use (&$remembered, $mode): void {
        if ($mode === 'storage-failure') throw new RuntimeException('private-sentinel-never-export');
        $remembered = $safe;
    });
    $out = ['ok' => true, 'evidence_valid' => true];
} catch (Throwable $error) {
    $out = keli_acceptance_cli_failure('adopt-source', $error);
    $out['same_first_diagnostic'] = $remembered === $out['cli_diagnostic'];
} finally {
    restore_error_handler();
}
echo json_encode($out, JSON_THROW_ON_ERROR);

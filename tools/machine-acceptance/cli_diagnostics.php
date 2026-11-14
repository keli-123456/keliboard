<?php
declare(strict_types=1);

function keli_acceptance_cli_records(): WeakMap
{
    static $records = new WeakMap();
    return $records;
}

function keli_acceptance_cli_diagnostic(Throwable $error): array
{
    $classes = ['ErrorException', 'RuntimeException', 'LogicException', 'TypeError', 'Error', 'JsonException',
        'KeliSourceFixtureEvidenceRejected', 'Illuminate\Database\Eloquent\ModelNotFoundException',
        'App\Services\ServerMachine\Cloud\CloudException',
        'App\Services\ServerMachine\Dns\DnsException', 'App\Services\ServerMachine\Dns\DnsRequestFailure'];
    $class = in_array(get_class($error), $classes, true) ? get_class($error) : 'other';
    $file = null;
    $path = str_replace('\\', '/', $error->getFile());
    $roots = [str_replace('\\', '/', __DIR__) => ['cli_diagnostics.php', 'source_fixture.php', 'source_fixture_evidence.php']];
    $base = basename(__DIR__) === 'machine-acceptance' && basename(dirname(__DIR__)) === 'tools'
        ? dirname(__DIR__, 2) : __DIR__;
    $roots[str_replace('\\', '/', $base)] = array_merge($roots[str_replace('\\', '/', $base)] ?? [], [
        'cli_diagnostics.php', 'source_fixture.php', 'source_fixture_evidence.php', 'live_cli.php',
        'app/Services/ServerMachine/Cloud/MachineFailoverObserver.php',
        'app/Services/ServerMachine/Cloud/MachineCloudHealth.php',
        'app/Services/ServerMachine/Cloud/MachineProvisionProviders.php',
        'app/Services/ServerMachine/Cloud/LinodeProvisionClient.php',
        'app/Services/ServerMachine/MachineAddressInventory.php',
        'app/Services/ServerMachine/Dns/DnsProviderClient.php',
        'app/Services/ServerMachine/Dns/MachineDnsRecordSetProvider.php',
    ]);
    foreach ($roots as $root => $files) foreach ($files as $name) {
        if ($path === rtrim($root, '/') . '/' . $name && realpath($error->getFile()) !== false
            && str_replace('\\', '/', (string) realpath($error->getFile())) === $path) $file = $name;
    }
    $severity = $error instanceof ErrorException ? match ($error->getSeverity()) {
        E_WARNING, E_USER_WARNING => 'warning', E_NOTICE, E_USER_NOTICE => 'notice',
        E_DEPRECATED, E_USER_DEPRECATED => 'deprecated', default => 'other',
    } : null;
    $category = match (true) {
        $error instanceof ErrorException => 'php_warning', $error instanceof TypeError => 'php_type_error',
        $error instanceof Error => 'php_error', $error instanceof RuntimeException => 'domain_rejection',
        default => 'unexpected_exception',
    };
    $line = $file !== null && $error->getLine() > 0 && $error->getLine() <= 1000000 ? $error->getLine() : null;
    return ['schema' => 1, 'category' => $category, 'error_class' => $class, 'source_known' => $file !== null && $line !== null,
        'file' => $line !== null ? $file : null, 'line' => $line, 'severity' => $severity];
}

function keli_acceptance_cli_guard(callable $operation, callable $remember): mixed
{
    try {
        return $operation();
    } catch (Throwable $error) {
        $records = keli_acceptance_cli_records();
        if (!isset($records[$error])) {
            $safe = keli_acceptance_cli_diagnostic($error);
            $recorded = false;
            try { $remember($safe); $recorded = true; } catch (Throwable) {}
            $records[$error] = ['diagnostic' => $safe, 'recorded' => $recorded];
        }
        throw $error;
    }
}

function keli_acceptance_cli_fields(array $value): ?array
{
    $keys = array_keys($value); sort($keys);
    if ($keys !== ['checks', 'failed_fields', 'ok', 'reason', 'schema'] || $value['schema'] !== 1 || $value['ok'] !== false
        || !in_array($value['reason'], ['source_fixture_scope_invalid', 'source_fixture_evidence_invalid'], true)) return null;
    $scope = ['expected_run_id', 'expected_machine_id', 'expected_account_id', 'expected_node_id',
        'expected_ipv4', 'expected_binary', 'expected_version', 'no_health_claim'];
    $evidence = ['installation_id', 'task_label', 'task_status', 'bootstrap_state', 'machine_id', 'account_id',
        'dispatched_at', 'external_id', 'provider_created_at', 'cloud_status', 'task_ipv4', 'cloud_ipv4',
        'runtime_binary', 'runtime_version', 'runtime_session', 'runtime_nodes', 'agent_at_type', 'agent_at_future',
        'agent_at_fresh', 'agent_after_dispatch', 'evidence_current', 'threshold_reached', 'observed_failures',
        'probe_state', 'probe_installation', 'probe_ipv4', 'probe_at_type', 'probe_at_future', 'probe_at_fresh', 'probe_valid_until'];
    $names = $value['reason'] === 'source_fixture_scope_invalid' ? $scope : $evidence;
    if (!is_array($value['checks']) || count($value['checks']) !== count($names)) return null;
    $failed = []; $checks = [];
    foreach ($names as $name) {
        if (!is_bool($value['checks'][$name] ?? null)) return null;
        $checks[$name] = $value['checks'][$name];
        if (!$checks[$name]) $failed[] = $name;
    }
    if ($failed === [] || $value['failed_fields'] !== $failed) return null;
    return ['schema' => 1, 'ok' => false, 'reason' => $value['reason'], 'failed_fields' => $failed, 'checks' => $checks];
}

function keli_acceptance_cli_failure(string $command, Throwable $error): array
{
    $record = keli_acceptance_cli_records()[$error] ?? ['diagnostic' => keli_acceptance_cli_diagnostic($error), 'recorded' => false];
    $out = ['ok' => false, 'command' => in_array($command, ['adopt-source', 'initial-dns'], true) ? $command : 'other',
        'error_class' => $record['diagnostic']['error_class'], 'reason' => 'cli_exception',
        'cli_diagnostic' => $record['diagnostic'], 'cli_diagnostic_recorded' => $record['recorded']];
    if (in_array($error->getMessage(), ['source_fixture_ownership_invalid', 'single_source_ipv4_required',
        'source_fixture_cloud_missing', 'source_fixture_not_failed', 'source_fixture_reports_missing',
        'source_fixture_original_identity_changed', 'exact_source_reference_required',
        'source_fixture_registration_changed', 'source_fixture_failure_not_current'], true)) $out['reason'] = $error->getMessage();
    if ($command === 'initial-dns' && $error instanceof \App\Services\ServerMachine\Dns\DnsException) {
        if (in_array($error->getMessage(), ['provider_rejected', 'provider_forbidden', 'provider_unavailable', 'provider_rate_limited',
            'dns_outcome_unknown', 'dns_ownership_changed', 'dns_inventory_incomplete', 'dns_credential_changed'], true)) $out['reason'] = $error->getMessage();
        if ($error->providerDiagnostic() !== null) $out['dns_provider_diagnostic'] = $error->providerDiagnostic();
    }
    if ($error instanceof KeliSourceFixtureEvidenceRejected) {
        $fields = keli_acceptance_cli_fields($error->diagnostic);
        if ($fields !== null) {
            $out['reason'] = $fields['reason'] . '_' . $fields['failed_fields'][0];
            $out['source_fixture_evidence'] = $fields;
            $out['source_fixture_controller_journal_recorded'] = $error->diagnosticRecorded;
        }
    }
    return $out;
}

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

function keli_acceptance_source_window_diagnostic(array $value): ?array
{
    $keys = array_keys($value); sort($keys);
    if ($keys !== ['attempts', 'budget_seconds', 'elapsed_ms', 'poll_seconds', 'required_failures', 'schema', 'stop_reason']
        || $value['schema'] !== 1 || $value['budget_seconds'] !== 60 || $value['poll_seconds'] !== 5
        || !is_int($value['required_failures']) || $value['required_failures'] < 3 || $value['required_failures'] > 10
        || !is_int($value['elapsed_ms']) || $value['elapsed_ms'] < 0 || $value['elapsed_ms'] > 60000
        || !in_array($value['stop_reason'], ['threshold_reached', 'deadline', 'probe_not_failed', 'clock_invalid'], true)
        || !is_array($value['attempts']) || !array_is_list($value['attempts']) || count($value['attempts']) > 12) return null;
    $previous = -1;
    foreach ($value['attempts'] as $row) {
        if (!is_array($row)) return null;
        $keys = array_keys($row); sort($keys);
        if ($keys !== ['checked_at', 'collected_checked_at', 'collected_failures', 'collection_pending', 'elapsed_ms',
            'evidence_current', 'observed_failures', 'probe_state', 'same_context', 'same_sample', 'threshold_reached']
            || !is_int($row['elapsed_ms']) || $row['elapsed_ms'] < 0 || $row['elapsed_ms'] < $previous || $row['elapsed_ms'] > $value['elapsed_ms']
            || !in_array($row['probe_state'], ['healthy', 'failed', 'unknown'], true)) return null;
        foreach (['evidence_current', 'threshold_reached', 'collection_pending', 'same_context', 'same_sample'] as $key)
            if (!is_bool($row[$key])) return null;
        foreach (['collected_failures', 'observed_failures', 'collected_checked_at', 'checked_at'] as $key)
            if ($row[$key] !== null && (!is_int($row[$key]) || $row[$key] < 0
                || $row[$key] > (str_ends_with($key, '_failures') ? 1000000 : 9007199254740991))) return null;
        $previous = $row['elapsed_ms'];
    }
    if ($value['stop_reason'] === 'threshold_reached') {
        $last = $value['attempts'][array_key_last($value['attempts'])] ?? null;
        if (!$last || !$last['evidence_current'] || !$last['threshold_reached'] || !$last['same_context']
            || !$last['same_sample'] || $last['collection_pending'] || $last['probe_state'] !== 'failed'
            || $last['checked_at'] === null || $last['checked_at'] !== $last['collected_checked_at']
            || $last['observed_failures'] < $value['required_failures']
            || $last['observed_failures'] !== $last['collected_failures'] || $value['elapsed_ms'] >= 60000) return null;
    }
    return $value;
}

function keli_acceptance_source_window_matches(array $window, array $registration): bool
{
    $last = $window['attempts'][array_key_last($window['attempts'])] ?? [];
    return $window['stop_reason'] === 'threshold_reached' && $registration['same_context']
        && $registration['evidence_current'] && $registration['threshold_reached']
        && $registration['checked_at'] === ($last['checked_at'] ?? null)
        && $registration['observed_failures'] === ($last['observed_failures'] ?? null);
}

function keli_acceptance_cli_fields(array $value): ?array
{
    $keys = array_keys($value); sort($keys);
    $extended = ($value['schema'] ?? null) === 2;
    if ($keys !== ($extended ? ['checks', 'failed_fields', 'observation_window', 'ok', 'reason', 'registration', 'schema']
            : ['checks', 'failed_fields', 'ok', 'reason', 'schema'])
        || !in_array($value['schema'], [1, 2], true) || $value['ok'] !== false
        || !in_array($value['reason'], ['source_fixture_scope_invalid', 'source_fixture_evidence_invalid'], true)) return null;
    $scope = ['expected_run_id', 'expected_machine_id', 'expected_account_id', 'expected_node_id',
        'expected_ipv4', 'expected_binary', 'expected_version', 'no_health_claim'];
    $evidence = ['installation_id', 'task_label', 'task_status', 'bootstrap_state', 'machine_id', 'account_id',
        'dispatched_at', 'external_id', 'provider_created_at', 'cloud_status', 'task_ipv4', 'cloud_ipv4',
        'runtime_binary', 'runtime_version', 'runtime_session', 'runtime_nodes', 'agent_at_type', 'agent_at_future',
        'agent_at_fresh', 'agent_after_dispatch', 'evidence_current', 'threshold_reached', 'observed_failures',
        'probe_state', 'probe_installation', 'probe_ipv4', 'probe_at_type', 'probe_at_future', 'probe_at_fresh', 'probe_valid_until'];
    $names = $value['reason'] === 'source_fixture_scope_invalid' ? $scope : $evidence;
    if ($extended) {
        if ($value['reason'] !== 'source_fixture_evidence_invalid' || !is_array($value['observation_window'])
            || keli_acceptance_source_window_diagnostic($value['observation_window']) === null) return null;
        if (!is_array($value['registration'])) return null;
        $keys = array_keys($value['registration']); sort($keys);
        if ($keys !== ['checked_at', 'evidence_current', 'observed_failures', 'same_context', 'threshold_reached']) return null;
        foreach (['same_context', 'evidence_current', 'threshold_reached'] as $key)
            if (!is_bool($value['registration'][$key])) return null;
        foreach (['observed_failures', 'checked_at'] as $key) {
            $number = $value['registration'][$key];
            if ($number !== null && (!is_int($number) || $number < 0
                || $number > ($key === 'observed_failures' ? 1000000 : 9007199254740991))) return null;
        }
        $names[] = 'observation_window_complete';
    }
    if (!is_array($value['checks']) || count($value['checks']) !== count($names)) return null;
    $failed = []; $checks = [];
    foreach ($names as $name) {
        if (!is_bool($value['checks'][$name] ?? null)) return null;
        $checks[$name] = $value['checks'][$name];
        if (!$checks[$name]) $failed[] = $name;
    }
    if ($failed === [] || $value['failed_fields'] !== $failed) return null;
    $out = ['schema' => $value['schema'], 'ok' => false, 'reason' => $value['reason'], 'failed_fields' => $failed, 'checks' => $checks];
    if ($extended) {
        if ($checks['observation_window_complete'] !== keli_acceptance_source_window_matches($value['observation_window'], $value['registration'])) return null;
        $out['observation_window'] = $value['observation_window'];
        $out['registration'] = $value['registration'];
    }
    return $out;
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

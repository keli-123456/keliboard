<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineProvisionTask as Task;
use Illuminate\Support\Facades\DB;

class MachineProvisionDiagnostic
{
    public const KEY = 'task_diagnostic';
    public const PHASES = ['admission', 'credentials', 'preflight', 'dispatch', 'create', 'reconcile',
        'readback', 'initialization', 'reports', 'ipv4_probe'];
    public const CLASSES = ['ErrorException', 'RuntimeException', 'LogicException', 'TypeError', 'Error', 'JsonException',
        'Illuminate\Database\QueryException', 'Illuminate\Database\Eloquent\ModelNotFoundException',
        'Illuminate\Contracts\Cache\LockTimeoutException', CloudException::class, CloudRequestFailure::class];
    public const FILES = ['live_support.php', 'live_cli.php',
        'app/Services/ServerMachine/Cloud/MachineProvisionService.php',
        'app/Services/ServerMachine/Cloud/MachineProvisionDiagnostic.php',
        'app/Services/ServerMachine/Cloud/MachineFailoverNativeBridge.php',
        'app/Services/ServerMachine/Cloud/MachineFailoverExecutionGate.php',
        'app/Services/ServerMachine/Cloud/MachineFailoverRunner.php',
        'app/Services/ServerMachine/Cloud/MachineCloudCredentialHealth.php',
        'app/Services/ServerMachine/Cloud/MachineProvisionProviders.php',
        'app/Services/ServerMachine/Cloud/LinodeClient.php',
        'app/Services/ServerMachine/Cloud/LinodeProvisionClient.php',
        'app/Services/ServerMachine/Cloud/JsonProvisionClient.php',
        'app/Services/ServerMachine/Cloud/MachineCloudHealth.php',
        'app/Services/ServerMachine/MachineAddressInventory.php'];
    public const CAUSES = ['other', 'internal_failure', 'provisioning_disabled', 'permission_revoked', 'test_machine_not_allowed',
        'account_changed', 'source_changed', 'panel_origin_changed', 'identity_changed', 'readback_identity_required',
        'provider_invalid', 'provider_rejected', 'provider_forbidden', 'provider_unavailable', 'provider_rate_limited',
        'outcome_uncertain', 'reconcile_incomplete', 'replacement_evidence_changed', 'replacement_evidence_pending',
        'native_lineage_changed', 'native_context_changed', 'native_intent_expired', 'native_stop_requested', 'native_lease_lost',
        'orchestration_disabled', 'credential_health_blocked', 'bootstrap_too_large',
        'isolated_manual_cli_required', 'isolated_operation_not_authorized', 'isolated_scope_changed',
        'isolated_source_cli_required', 'isolated_source_stopped', 'isolated_manual_stopped', 'isolated_scope_expired'];

    public static function project(\Throwable $error, string $phase): array
    {
        $path = str_replace('\\', '/', $error->getFile());
        $root = str_replace('\\', '/', base_path()); $file = null;
        foreach (self::FILES as $name) {
            if ($path === $root . '/' . $name && realpath($error->getFile()) !== false
                && str_replace('\\', '/', (string) realpath($error->getFile())) === $path) $file = $name;
        }
        $line = $file !== null && $error->getLine() > 0 && $error->getLine() <= 1000000 ? $error->getLine() : null;
        return ['schema' => 1, 'phase' => in_array($phase, self::PHASES, true) ? $phase : 'admission',
            'category' => match (true) {
                $error instanceof \ErrorException => 'php_warning', $error instanceof \TypeError => 'php_type_error',
                $error instanceof \Error => 'php_error', $error instanceof CloudException => 'domain_rejection',
                default => 'unexpected_exception',
            },
            'error_class' => in_array(get_class($error), self::CLASSES, true) ? get_class($error) : 'other',
            'cause' => $error instanceof CloudException && in_array($error->getMessage(), self::CAUSES, true) ? $error->getMessage() : 'other',
            'source_known' => $line !== null, 'file' => $line !== null ? $file : null, 'line' => $line,
            'severity' => $error instanceof \ErrorException ? match ($error->getSeverity()) {
                E_WARNING, E_USER_WARNING => 'warning', E_NOTICE, E_USER_NOTICE => 'notice',
                E_DEPRECATED, E_USER_DEPRECATED => 'deprecated', default => 'other',
            } : null];
    }

    public static function safe(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $keys = array_keys($value); sort($keys);
        if ($keys !== ['category', 'cause', 'error_class', 'file', 'line', 'phase', 'schema', 'severity', 'source_known']
            || ($value['schema'] ?? null) !== 1 || !in_array($value['phase'], self::PHASES, true)
            || !in_array($value['category'], ['php_warning', 'php_type_error', 'php_error', 'domain_rejection', 'unexpected_exception'], true)
            || !in_array($value['error_class'], [...self::CLASSES, 'other'], true) || !in_array($value['cause'], self::CAUSES, true)
            || !is_bool($value['source_known']) || !in_array($value['severity'], [null, 'warning', 'notice', 'deprecated', 'other'], true)) return null;
        if ($value['source_known']) {
            if (!in_array($value['file'], self::FILES, true) || !is_int($value['line']) || $value['line'] < 1 || $value['line'] > 1000000) return null;
        } elseif ($value['file'] !== null || $value['line'] !== null) return null;
        if (($value['category'] === 'php_warning') !== ($value['severity'] !== null)) return null;
        return $value;
    }

    public function remember(Task $task, \Throwable $error, string $phase): bool
    {
        $before = $task->evidence;
        try {
            $safe = self::project($error, $phase);
            $stored = DB::transaction(function () use ($task, $safe) {
                $row = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
                if ($id = app(MachineFailoverRunner::class)->managed($row)) app(MachineFailoverRunner::class)->guard($id);
                $evidence = $row->evidence ?? [];
                if (!array_key_exists(self::KEY, $evidence)) {
                    $evidence[self::KEY] = $safe; $row->evidence = $evidence; $row->save();
                }
                return $evidence;
            });
            $readback = Task::query()->findOrFail($task->id)->evidence;
            if (self::safe($stored[self::KEY] ?? null) === null || ($readback[self::KEY] ?? null) !== $stored[self::KEY]) return false;
            $task->evidence = $readback;
            return true;
        } catch (\Throwable) {
            // Never let diagnostic storage replace the failure or dirty its subsequent state save.
            $task->evidence = $before;
            return false;
        }
    }
}

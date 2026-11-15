<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/tools/machine-acceptance/candidate_readback.php';

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Models\MachineProvisionTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class KeliLiveScope
{
    public const RUN = 'keli-accept-20990101-candidate-offline';
    public static string $mode = 'native';
    public static bool $stopped = false;
    public static function assertManual(array $commands): array
    {
        if (self::$stopped) throw new CloudException('isolated_manual_stopped');
        if (PHP_SAPI !== 'cli' || DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('offline_memory_database_required');
        }
        if (!in_array($GLOBALS['argv'][1] ?? null, $commands, true)) throw new CloudException('isolated_manual_cli_required');
        return ['run_id' => self::RUN];
    }
}

final class CandidateProbeGate extends MachineFailoverExecutionGate
{
    public static bool $denied = false;
    public static array $operations = [];
    public function assertAllowed(string $operation): void
    {
        self::$operations[] = $operation;
        // These are the R20 public adapter's original command and mode restrictions.
        KeliLiveScope::assertManual(['native-start', 'native-advance', 'initial-dns', 'dns-external', 'post-dns']);
        if (self::$denied) throw new CloudException('orchestration_disabled');
        if (!in_array(KeliLiveScope::$mode, ['native', 'initial-dns'], true)
            || !in_array($operation, ['start', 'advance', 'native_task', 'context', 'queue', 'dns_readback', 'dns_write', 'dns_external_read',
                'native_dns_admission', 'native_dns_prepare', 'native_dns_binding', 'native_dns_complete', 'native_address_verify', 'native_address_read',
                'dns_set_read', 'dns_set_write', 'dns_set_start', 'dns_set_advance', 'dns_set_transport'], true)) {
            throw new CloudException('isolated_operation_not_authorized');
        }
    }
}

function keli_live_consume_callback(MachineProvisionTask $task): bool
{
    $GLOBALS['candidate_probe_callback_calls']++;
    if (!($GLOBALS['candidate_probe_ready'] ?? false)) return false;
    return app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $GLOBALS['candidate_probe_secret'], 'succeeded');
}

final class CandidateReadbackDatabaseHarness extends Tests\Unit\Services\MachineFailoverNativeBridgeTest
{
    public function exercise(string $mode): array
    {
        parent::setUp();
        try {
            $reflection = new ReflectionClass(Tests\Unit\Services\MachineFailoverNativeBridgeTest::class);
            $invoke = fn ($name, ...$args) => $reflection->getMethod($name)->invoke($this, ...$args);
            app()->instance(App\Services\ServerMachine\Cloud\LinodeProvisionClient::class,
                new class extends App\Services\ServerMachine\Cloud\LinodeProvisionClient {
                    public function buildRequest(array $plan, string $id, string $marker, string $bootstrap): array
                    {
                        return array_replace(parent::buildRequest($plan, $id, $marker, $bootstrap), ['label' => KeliLiveScope::RUN . '-candidate']);
                    }
                });
            $run = $invoke('advance', $invoke('start')); $task = $invoke('task');
            $this->assertSame('waiting_initialization', $task->status);
            $ids = ['execution_id' => $run['id'], 'policy_id' => $reflection->getProperty('fixtures')->getValue($this)[0]['policy']->id,
                'admin_id' => $reflection->getProperty('admin')->getValue($this)->id];
            $task->fill(['next_check_at' => 0])->save();
            app()->instance(MachineFailoverExecutionGate::class, new CandidateProbeGate());
            $GLOBALS['argv'] = ['offline-fixture', $mode === 'legacy' ? 'process' : 'native-advance', $task->id];
            KeliLiveScope::$mode = $mode === 'legacy' ? 'source' : 'native';
            $GLOBALS['candidate_probe_callback_calls'] = 0;
            $GLOBALS['candidate_probe_ready'] = $mode === 'ready';
            if ($mode === 'ready') {
                $GLOBALS['candidate_probe_secret'] = $invoke('callbackSecret', $task);
                $task->fill(['dispatched_at' => time() - 2])->save();
                $invoke('report', $reflection->getProperty('fixtures')->getValue($this)[0], $task, $task->public_ips[0], true, time());
            }
            if ($mode === 'missing-reference') $GLOBALS['argv'][2] = null;
            if ($mode === 'source-mode') KeliLiveScope::$mode = 'source';
            if ($mode === 'foreign-run') $ids['execution_id'] = '10000000-0000-4000-8000-000000000001';
            if ($mode === 'foreign-policy') $ids['policy_id']++;
            if ($mode === 'foreign-admin') $ids['admin_id']++;
            if ($mode === 'no-provider-id') $task->fill(['external_id' => null])->save();
            if ($mode === 'no-dispatch') $task->fill(['dispatched_at' => null])->save();
            if ($mode === 'stopped') KeliLiveScope::$stopped = true;
            if ($mode === 'denied') CandidateProbeGate::$denied = true;
            $before = Http::recorded()->count(); $caught = null; $result = null;
            try {
                if ($mode === 'legacy') app(MachineProvisionService::class)->process($task->id);
                elseif ($mode === 'forbidden-op') app(MachineFailoverExecutionGate::class)->assertAllowed('runner');
                else $result = keli_acceptance_candidate_readback($ids, $task->id);
            } catch (CloudException $error) {
                $caught = $error->getMessage();
            }
            $task->refresh();
            $diagnostic = App\Services\ServerMachine\Cloud\MachineProvisionDiagnostic::safe($task->evidence['task_diagnostic'] ?? null);
            return ['ok' => true, 'mode' => $mode, 'task_status' => $task->status, 'task_reason' => $task->reason,
                'result' => $result, 'caught' => $caught, 'task_diagnostic' => $diagnostic,
                'http_reads_after_route' => Http::recorded()->count() - $before,
                'create_posts_total' => count($reflection->getProperty('posts')->getValue($this)),
                'dns_writes' => $reflection->getProperty('dnsWrites')->getValue($this),
                'verifications' => $reflection->getProperty('verifications')->getValue($this),
                'callback_calls' => $GLOBALS['candidate_probe_callback_calls'],
                'operations_after_route' => CandidateProbeGate::$operations,
                'execution_status' => App\Models\MachineFailoverExecution::query()->findOrFail($run['id'])->status,
                'runtime_rows' => Illuminate\Support\Facades\Schema::hasTable('v2_machine_failover_runtime')
                    ? DB::table('v2_machine_failover_runtime')->count() : 0];
        } finally { parent::tearDown(); }
    }
}

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['legacy', 'fixed', 'ready', 'missing-reference', 'source-mode', 'foreign-run', 'foreign-policy',
        'foreign-admin', 'no-provider-id', 'no-dispatch', 'stopped', 'denied', 'forbidden-op'], true)) throw new RuntimeException('invalid_fixture_mode');
    $result = (new CandidateReadbackDatabaseHarness('test_default_gates_are_closed'))->exercise($mode);
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error_class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()]);
    exit(1);
}

<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverDnsRun as DnsRun;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetHttpTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** This transport binds an existing grant. It cannot issue grants or arm provider writes. */
final class MachineUsdo1Runtime
{
    public function __construct(private MachineFailoverExecutionGate $gate, private MachineUsdo1TimedExecution $entry,
        private MachineFailoverRunner $runner, private MachineProvisionService $provision, private ProvisionBootstrap $bootstrap) {}

    public function pulse(MachineUsdo1LaunchScope $scope): array
    {
        $this->gate->assertAllowed('usdo1_execution_tick');
        return Cache::lock('usdo1-launch-worker:' . $scope->grant, 120)->block(2, fn () => $this->advance($scope));
    }

    private function advance(MachineUsdo1LaunchScope $scope): array
    {
        [$view, $grant] = $this->inspect($scope);
        if ($this->stopping($view, $grant) || $view['status'] !== 'running' || $this->publicationBoundary($view['execution_id']))
            return $this->entry->stop($scope->grant, $scope->admin);
        $scope->assertIndependent($this->bootstrap->configuredOrigin());
        // Never inherit the shared panel transport, even if a caller supplies a scoped gate.
        $transport = app()->bound(MachineDnsRecordSetHttpTransport::class) ? app(MachineDnsRecordSetHttpTransport::class) : null;
        if (!$transport instanceof MachineDnsRecordSetHttpTransport || get_class($transport) === MachineDnsRecordSetHttpTransport::class)
            throw new CloudException('usdo1_transport_unconfigured');

        if (app()->bound(MachineUsdo1LaunchScope::class)) throw new CloudException('usdo1_launch_scope_in_use');
        app()->instance(MachineUsdo1LaunchScope::class, $scope);
        try {
            $result = $this->runner->pulseUsdo1($scope->grant, $scope->admin);
        } finally {
            app()->forgetInstance(MachineUsdo1LaunchScope::class);
        }
        return $result['status'] !== 'running' || $this->publicationBoundary($view['execution_id'])
            ? $this->entry->stop($scope->grant, $scope->admin) : $result;
    }

    public function callback(MachineUsdo1LaunchScope $scope, string $id, string $installation, string $secret, string $state): bool
    {
        $this->gate->assertAllowed('usdo1_execution_callback');
        [$view, $grant] = $this->inspect($scope);
        if ($this->stopping($view, $grant) || $view['status'] !== 'running') throw new CloudException('invalid_callback');
        $scope->assertIndependent($this->bootstrap->configuredOrigin());
        $attempt = $this->attempt($view['execution_id']);
        $task = $attempt?->task_id === $id ? Task::query()->useWritePdo()->find($id) : null;
        if (!$task || !$task->dispatched_at || $task->machine_id !== MachineUsdo1HandoverScope::MACHINE
            || ($task->payload['coordinated_attempt'] ?? null) !== $attempt->id
            || ($task->payload['usdo1_callback_origin'] ?? null) !== $scope->origin) throw new CloudException('invalid_callback');
        return $this->provision->callback($id, $installation, $secret, $state, $scope);
    }

    public static function receive(MachineUsdo1LaunchScope $scope, array $server, string $body, \Closure $accept): int
    {
        $host = substr($scope->origin, strlen('https://'));
        if (($server['HTTPS'] ?? '') !== 'on' || !in_array(strtolower($server['HTTP_HOST'] ?? ''), [$host, $host . ':443'], true)
            || ($server['REQUEST_URI'] ?? '') !== MachineUsdo1LaunchScope::CALLBACK_PATH) return 404;
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return 405;
        if (strlen($body) > 2048) return 413;
        if (!preg_match('/\Aapplication\/json(?:;\s*charset=utf-8)?\z/i', $server['CONTENT_TYPE'] ?? '')) return 415;
        if (!preg_match('/\ABearer ([a-f0-9]{64})\z/D', $server['HTTP_AUTHORIZATION'] ?? '', $match)) return 401;
        try {
            $receipt = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 400;
        }
        if (!is_array($receipt) || count($receipt) !== 3 || !is_string($id = $receipt['task_id'] ?? null)
            || !Str::isUuid($id) || $id !== strtolower($id)
            || !is_string($installation = $receipt['installation_id'] ?? null)
            || !preg_match('/\A[a-f0-9]{32}\z/D', $installation)
            || !in_array($receipt['state'] ?? null, ['succeeded', 'failed'], true)) return 400;
        try {
            return $accept($scope, $id, $installation, $match[1], $receipt['state']) ? 200 : 401;
        } catch (CloudException) {
            return 401;
        } catch (\Throwable) {
            return 503;
        }
    }

    private function inspect(MachineUsdo1LaunchScope $scope): array
    {
        $view = $this->entry->status($scope->grant, $scope->admin);
        if (!$id = $view['execution_id']) throw new CloudException('usdo1_execution_not_started');
        $grant = Grant::query()->useWritePdo()->findOrFail($scope->grant);
        if ($grant->execution_id !== $id) throw new CloudException('usdo1_launch_execution_changed');
        $scope->assertExecution(Execution::query()->useWritePdo()->findOrFail($id));
        return [$view, $grant];
    }

    private function stopping(array $view, Grant $grant): bool
    {
        return $view['expired'] || $view['stop_requested'] || $grant->revoked_at !== null || $grant->released_at !== null
            || $grant->writer_slot === null || now()->timestamp >= $grant->expires_at - MachineUsdo1LaunchScope::RECOVERY_SECONDS;
    }

    private function attempt(string $execution): ?Attempt
    {
        $members = Member::query()->useWritePdo()->where('execution_id', $execution)->limit(2)->get();
        if ($members->count() !== 1) throw new CloudException('usdo1_launch_execution_changed');
        $member = $members->first();
        $attempts = Attempt::query()->useWritePdo()->where('execution_member_id', $member->id)->limit(2)->get();
        $attempt = $attempts->first();
        if ($attempts->count() > 1 || $attempt?->id !== $member->attempt_id || ($attempt && $attempt->ordinal !== 1))
            throw new CloudException('usdo1_launch_execution_changed');
        return $attempt;
    }

    private function publicationBoundary(string $execution): bool
    {
        $runs = $this->attempt($execution)?->payload['dns_set_runs'] ?? [];
        if (!$runs) return false;
        if (count($runs) !== 1) throw new CloudException('usdo1_launch_execution_changed');
        $dns = DnsRun::query()->useWritePdo()->findOrFail(reset($runs));
        $action = $dns->payload['plan']['actions'][0] ?? [];
        // Allow readback after a known acknowledgement but never advance to promotion.
        return $dns->status !== 'running' || $dns->cursor > 0
            || !in_array($action['state'] ?? null, ['prepared', 'awaiting_readback'], true)
            || in_array('unknown', array_column($dns->payload['events'] ?? [], 'outcome'), true);
    }
}

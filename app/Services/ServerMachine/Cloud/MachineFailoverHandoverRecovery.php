<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
use App\Models\MachineDnsSetRuntime;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverDnsRun as DnsRun;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineOwnedDns;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetHttpTransport;
use Illuminate\Database\Eloquent\Model;

// Deliberately separate from worker advancement: observations do not change any run or resource.
final class MachineFailoverHandoverRecovery
{
    public function __construct(private MachineFailoverHandover $handover, private MachineFailoverExecutionGate $gate,
        private MachineFailoverNativeBridge $native) {}

    public function cloud(string $executionId, string $attemptId, int $admin): array
    {
        [$run, $attempt, $member, $task] = $this->original($executionId, $attemptId, $admin);
        $account = MachineCloudAccount::query()->useWritePdo()->find($task->account_id);
        if (!$account || $account->revision !== $task->account_revision) throw new CloudException('account_changed');
        if ($task->external_id !== null) {
            $receipt = app(MachineCandidateCleanupClient::class)->inspect($task);
            $result = $receipt === null ? 'exact_not_found' : 'matched_original';
        } else {
            $payload = $task->payload;
            $receipt = app(MachineProvisionProviders::class)->client($account, $payload['plan'])
                ->reconcile($account, $payload['request'], $task->dispatched_at);
            $result = $receipt === null ? 'unconfirmed' : 'matched_original';
        }
        $this->handover->stoppedOriginal($executionId, $admin);
        $this->unchanged($run, $attempt, $member, $task, $account);
        return $this->observation('cloud_create', $task->id, $result, $receipt);
    }

    public function dns(string $executionId, string $attemptId, int $admin): array
    {
        [$run, $attempt, $member, $task] = $this->original($executionId, $attemptId, $admin);
        $id = $attempt->payload['transition_id'] ?? null;
        $transition = is_string($id) ? MachineDnsTransition::query()->useWritePdo()->find($id) : null;
        $pin = $member->payload['dns'] ?? null;
        if (!$transition || !$transition->dispatched_at || $transition->write_kind !== 'patch' || !$pin
            || !$this->native->transitionCurrent($attempt, $task, $transition)) throw new CloudException('handover_readback_identity');
        $record = MachineOwnedDns::query()->useWritePdo()->find($transition->dns_id);
        $scope = collect($run->payload['snapshot']['members'])->firstWhere('id', $member->member_id);
        if (!$record || !$record->record_id || $record->policy_id !== $member->policy_id || !$scope
            || !MachineIntentJson::same($pin, $record->only(array_keys($pin)))
            || $record->hostname !== $scope['policy']['dns_hostname'] || $record->credential_id !== $scope['policy']['dns_credential_id']
            || $record->credential_revision !== $scope['policy']['dns_credential_revision']) throw new CloudException('dns_ownership_changed');
        $credential = MachineDnsCredential::query()->useWritePdo()->find($record->credential_id);
        if (!$credential || $credential->provider !== 'cloudflare' || $credential->revision !== $record->credential_revision)
            throw new CloudException('dns_credential_changed');

        $rows = app(DnsProviderClient::class)->ownedRecords($credential, $record->zone, $record->hostname);
        $row = count($rows) === 1 ? $rows[0] : null;
        $matched = $row && $row['id'] === $record->record_id && $row['hostname'] === $record->hostname
            && $row['type'] === 'A' && $row['ttl'] === 60 && $row['proxied'] === false && $row['private_routing'] === false
            && !$row['locked'] && hash_equals($record->marker, $row['comment']) && $row['content'] === $transition->to_ip;
        $this->handover->stoppedOriginal($executionId, $admin);
        $this->unchanged($run, $attempt, $member, $task, $transition, $record, $credential);
        return $this->observation('dns_patch', $transition->id, $matched ? 'matched_original' : 'unconfirmed', $rows);
    }

    public function dnsSet(string $executionId, string $attemptId, string $dnsRunId, int $admin): array
    {
        [$run, $attempt, $member, $task] = $this->original($executionId, $attemptId, $admin);
        $dns = DnsRun::query()->useWritePdo()->find($dnsRunId);
        $runtime = MachineDnsSetRuntime::query()->useWritePdo()->find($dnsRunId);
        if (!$dns || !$runtime || $runtime->mode !== 'native' || !$runtime->finished_at
            || (!$runtime->stop_requested_at && $dns->status !== 'completed')
            || $runtime->lease_hash !== null || $runtime->lease_until !== null
            || $dns->service_id !== $run->service_id || $dns->service_revision !== $run->service_revision || $dns->admin_id !== $run->admin_id
            || ($attempt->payload['dns_set_runs'][$dns->set_id] ?? null) !== $dns->id
            || !in_array($dns->status, ['needs_review', 'stopped', 'completed'], true)) throw new CloudException('handover_readback_identity');
        $pin = app(MachineNativeDnsAdmission::class)->publicationBinding($dns);
        if ($pin['attempt_id'] !== $attempt->id || ($pin['context']['execution_id'] ?? null) !== $run->id
            || ($pin['context']['execution_member_id'] ?? null) !== $member->id
            || ($pin['context']['task']['id'] ?? null) !== $task->id) throw new CloudException('handover_readback_identity');
        $engine = app(MachineDnsRecordSetExecution::class);
        $set = $dns->payload['snapshot']['set'];
        $actions = $dns->payload['plan']['actions'];
        foreach ($actions as $action) $engine->assertActionJournal($action, $set);
        if (!array_filter($actions, fn ($action) => $action['state'] !== 'prepared')) throw new CloudException('handover_readback_identity');
        $credential = MachineDnsCredential::query()->useWritePdo()->find($set['credential_id']);
        if (!$credential || $credential->revision !== $set['credential_revision'] || $credential->provider !== $set['provider'])
            throw new CloudException('dns_credential_changed');
        $records = app(MachineDnsRecordSetHttpTransport::class)->inventory($dns->id, $set);
        $this->handover->stoppedOriginal($executionId, $admin);
        $this->unchanged($run, $attempt, $member, $task, $dns, $runtime, $credential);
        return $this->observation('dns_set', $dns->id, $engine->stoppedReadback($dns, $records), $records);
    }

    private function original(string $executionId, string $attemptId, int $admin): array
    {
        $this->gate->assertAllowed('handover_readback');
        $run = $this->handover->stoppedOriginal($executionId, $admin);
        $attempt = Attempt::query()->useWritePdo()->find($attemptId);
        $member = $attempt ? Member::query()->useWritePdo()->find($attempt->execution_member_id) : null;
        $task = $attempt?->task_id ? Task::query()->useWritePdo()->find($attempt->task_id) : null;
        if (!$member || $member->execution_id !== $run->id || !$task || $task->admin_id !== $run->admin_id
            || !$task->dispatched_at || $task->dispatched_at > time()
            || !$this->native->bindingCurrent($attempt, $task)) throw new CloudException('handover_readback_identity');
        return [$run, $attempt, $member, $task];
    }

    private function unchanged(Model ...$rows): void
    {
        foreach ($rows as $row) {
            $current = $row->newQuery()->useWritePdo()->find($row->getKey());
            if (!$current || $current->getRawOriginal() !== $row->getRawOriginal()) throw new CloudException('handover_readback_changed');
        }
    }

    private function observation(string $kind, string $id, string $result, ?array $observed): array
    {
        return ['kind' => $kind, 'original_id' => $id, 'result' => $result,
            'observation_sha256' => hash('sha256', MachineIntentJson::encode([$kind, $id, $result, $observed]))];
    }
}

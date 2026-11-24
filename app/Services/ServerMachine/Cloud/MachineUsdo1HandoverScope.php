<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudResource;
use App\Models\MachineDnsBinding;
use App\Models\MachineFailoverAttempt;
use App\Models\MachineFailoverExecutionMember;
use App\Models\MachineOwnedDns;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use App\Services\ServerMachine\MachineEntryProbe;

final class MachineUsdo1HandoverScope
{
    public const MACHINE = 17;
    public const ZONE = 'dnscloudcloud.top';
    public const NAME = 'usdo1.' . self::ZONE;
    public const TTL = 600;

    public static function baseline(array $row): array
    {
        $record = (new MachineDnsRecordSetProvider)->record(
            ['provider' => 'aliyun', 'zone' => self::ZONE, 'hostname' => self::NAME, 'ttl' => self::TTL], $row
        );
        self::require(($row['RR'] ?? null) === 'usdo1' && $record['type'] === 'A' && $record['line'] === 'default'
            && $record['ttl'] === self::TTL && !$record['locked'] && is_string($row['Remark'] ?? null)
            && !str_starts_with($row['Remark'], 'keli-set-') && !preg_match('/[\x00-\x1f\x7f]/', $row['Remark'])
            && MachineEntryProbe::publicIp($record['content']) === $record['content']);
        return ['RecordId' => $record['id'], 'DomainName' => self::ZONE, 'RR' => 'usdo1', 'Type' => 'A', 'Line' => 'default',
            'Value' => $record['content'], 'TTL' => self::TTL, 'Remark' => $row['Remark'], 'Status' => 'Enable', 'Locked' => false];
    }

    public static function pin(array $source, array $record): array
    {
        $record = self::baseline($record);
        $pin = ['source' => $source, 'record_id' => $record['RecordId'], 'baseline_sha256' => MachineFailoverHandoverEvidence::hash($record)];
        self::validate($pin);
        self::require($source['ipv4'] === $record['Value']);
        return $pin;
    }

    public static function validate(array $pin): void
    {
        $source = $pin['source'] ?? null;
        self::require(count($pin) === 3 && is_array($source) && count($source) === 5 && ($source['machine_id'] ?? null) === self::MACHINE
            && is_string($source['external_id'] ?? null) && preg_match('/^[1-9][0-9]{0,15}$/D', $source['external_id'])
            && is_string($source['installation_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $source['installation_id'])
            && is_string($source['created_at'] ?? null) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $source['created_at'])
            && is_string($source['ipv4'] ?? null) && filter_var($source['ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && MachineEntryProbe::publicIp($source['ipv4']) === $source['ipv4']
            && is_string($pin['record_id'] ?? null) && preg_match('/^[0-9]{1,40}$/D', $pin['record_id'])
            && is_string($pin['baseline_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $pin['baseline_sha256']));
    }

    public static function assertCurrent(array $pin, array $snapshot, ?string $execution = null): void
    {
        self::validate($pin);
        $members = $snapshot['members'] ?? [];
        $sets = $snapshot['dns_sets'] ?? [];
        $member = $members[0] ?? [];
        $set = $sets[0] ?? [];
        $slots = $set['slots'] ?? [];
        $slot = $slots[0] ?? [];
        self::require(count($members) === 1 && ($member['machine_id'] ?? null) === self::MACHINE && ($member['provider'] ?? null) === 'linode'
            && ($member['attempt_limit'] ?? 1) === 1 && ($snapshot['service']['max_parallel'] ?? null) === 1 && ($snapshot['service']['attempt_limit'] ?? null) === 1
            && config('machine_failover.allow_rotation') === false && config('machine_failover.allow_candidate_retry') === false
            && count($sets) === 1 && ($set['provider'] ?? null) === 'aliyun' && ($set['zone'] ?? null) === self::ZONE
            && ($set['zone_name'] ?? null) === self::ZONE && ($set['hostname'] ?? null) === self::NAME && ($set['ttl'] ?? null) === self::TTL
            && count($slots) === 1 && ($slot['member_id'] ?? null) === ($member['id'] ?? null)
            && ($slot['type'] ?? null) === 'A' && ($slot['line'] ?? null) === 'default');
        $source = $pin['source'];
        $rows = MachineCloudResource::query()->useWritePdo()->where('machine_id', self::MACHINE)->limit(3)->get();
        $row = $rows->first(fn ($r) => $r->provider === 'linode' && $r->external_id === $source['external_id']);
        self::require($row !== null && $rows->count() <= ($execution === null ? 1 : 2)
            && $row->installation_id === $source['installation_id'] && $row->provider_created_at === $source['created_at']
            && in_array($source['ipv4'], $row->public_ips ?? [], true) && !$row->auto_boot && !$row->armed && $row->active_task_id === null);
        if ($rows->count() === 2) self::assertCandidate($rows->first(fn ($r) => $r->id !== $row->id), $member, $execution);
        $matches = fn ($q) => $q->where('hostname', self::NAME)->orWhere('record_id', $pin['record_id']);
        self::require(!MachineDnsBinding::query()->useWritePdo()->where($matches)->exists()
            && !MachineOwnedDns::query()->useWritePdo()->where($matches)->exists());
    }

    private static function assertCandidate(MachineCloudResource $resource, array $member, string $execution): void
    {
        $runMember = MachineFailoverExecutionMember::query()->useWritePdo()->where('execution_id', $execution)
            ->where('member_id', $member['id'])->first();
        $attempt = $runMember?->attempt_id ? MachineFailoverAttempt::query()->useWritePdo()->find($runMember->attempt_id) : null;
        $task = $attempt?->task_id ? MachineProvisionTask::query()->useWritePdo()->find($attempt->task_id) : null;
        $owned = MachineOwnedInstance::query()->useWritePdo()->where('resource_id', $resource->id)->first();
        self::require($runMember && $attempt && $task && $owned && $attempt->ordinal === 1 && $attempt->execution_member_id === $runMember->id
            && ($task->payload['coordinated_attempt'] ?? null) === $attempt->id && $owned->task_id === $task->id
            && $owned->policy_id === $member['policy_id'] && $resource->provider === 'linode'
            && $resource->account_id === $task->account_id && $resource->installation_id === $task->installation_id
            && $resource->external_id === $task->external_id && $resource->provider_created_at === $task->provider_created_at
            && !$resource->auto_boot && !$resource->armed && $resource->active_task_id === null);
    }

    private static function require(bool $valid): void
    {
        if (!$valid) throw new CloudException('usdo1_handover_scope_changed');
    }
}

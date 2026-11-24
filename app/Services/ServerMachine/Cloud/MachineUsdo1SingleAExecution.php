<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineDnsSetRuntime;
use App\Models\MachineFailoverDnsRun;
use App\Models\MachineFailoverExecution;
use App\Models\MachineFailoverHandoverGrant;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution;
use App\Services\ServerMachine\MachineEntryProbe;

/** A scoped entry to the existing native journal, not a generic DNS write API. */
final class MachineUsdo1SingleAExecution
{
    public function __construct(private MachineFailoverExecutionGate $gate) {}

    public function publish(string $runId, int $admin): bool
    {
        $this->gate->assertAllowed('usdo1_single_a_publish');
        $run = MachineFailoverDnsRun::query()->useWritePdo()->find($runId);
        if (!$run || $run->admin_id !== $admin || MachineDnsSetRuntime::query()->whereKey($runId)->value('mode') !== 'native')
            $this->refuse();
        $binding = app(MachineNativeDnsAdmission::class)->publicationBinding($run);
        $parent = MachineFailoverExecution::query()->useWritePdo()->find($binding['context']['execution_id']);
        if (!$parent || $this->guard($parent, $run, $binding, true) !== true) $this->refuse();
        // The engine owns the lease, exact readback, journal and no-resend semantics.
        return app(MachineDnsRecordSetExecution::class)->tick($runId, $admin);
    }

    public function guard(MachineFailoverExecution $parent, MachineFailoverDnsRun $run, array $binding, bool $dispatch): bool
    {
        $id = $parent->payload['handover_grant_id'] ?? null;
        if ($id === null) return false;
        $grant = MachineFailoverHandoverGrant::query()->useWritePdo()->find($id);
        if (!$grant || $grant->execution_id !== $parent->id) $this->refuse();
        $pin = $grant->stop_receipt['manual_usdo1'] ?? null;
        if ($pin === null) return false;
        if ($dispatch) {
            $this->gate->assertAllowed('usdo1_single_a_publish');
            app(MachineFailoverHandover::class)->assertNewWork($parent->id);
        }
        $p = $run->payload;
        $sets = $parent->payload['snapshot']['dns_sets'] ?? [];
        if ($parent->service_id !== $run->service_id || $parent->service_revision !== $run->service_revision
            || $parent->admin_id !== $run->admin_id || count($sets) !== 1 || $sets[0]['id'] !== $run->set_id
            || $sets[0]['revision'] !== $run->set_revision || $run->scenario !== 'native_replace'
            || $binding['context']['execution_id'] !== $parent->id || $binding['context']['families'] !== ['A']) $this->refuse();
        self::assertPlan($pin, $sets[0], $p['plan']['actions'], $binding['context']['addresses']['A'] ?? null);
        return true;
    }

    public static function assertPlan(array $pin, array $set, array $actions, mixed $candidate): void
    {
        MachineUsdo1HandoverScope::validate($pin);
        $slots = $set['slots'] ?? [];
        $slot = $slots[0] ?? [];
        $a = $actions[0] ?? [];
        $before = $a['before'] ?? [];
        $after = $a['after'] ?? [];
        $marker = $slot['marker'] ?? null;
        $record = static fn (array $r): bool => ($r['hostname'] ?? null) === MachineUsdo1HandoverScope::NAME
            && ($r['type'] ?? null) === 'A' && ($r['line'] ?? null) === 'default' && ($r['ttl'] ?? null) === MachineUsdo1HandoverScope::TTL
            && ($r['locked'] ?? null) === false && ($r['proxied'] ?? null) === false && ($r['marker'] ?? null) === $marker;
        if (($set['provider'] ?? null) !== 'aliyun' || ($set['zone'] ?? null) !== MachineUsdo1HandoverScope::ZONE
            || ($set['hostname'] ?? null) !== MachineUsdo1HandoverScope::NAME || ($set['ttl'] ?? null) !== MachineUsdo1HandoverScope::TTL
            || count($slots) !== 1 || ($slot['type'] ?? null) !== 'A' || ($slot['line'] ?? null) !== 'default'
            || !is_string($marker) || !preg_match('/^keli-set-[a-f0-9]{48}$/D', $marker)
            || !array_is_list($actions) || count($actions) !== 1 || ($a['kind'] ?? null) !== 'update'
            || !is_int($slot['id'] ?? null) || ($a['slot_id'] ?? null) !== $slot['id']
            || !is_int($slot['member_id'] ?? null) || ($a['member_id'] ?? null) !== $slot['member_id'] || !is_array($before) || !is_array($after)
            || !$record($before) || !$record($after) || ($before['id'] ?? null) !== $pin['record_id']
            || array_key_exists('id', $after) || ($a['known_id'] ?? null) !== null
            || ($before['content'] ?? null) !== $pin['source']['ipv4'] || !is_string($candidate)
            || $candidate === $pin['source']['ipv4'] || !filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || MachineEntryProbe::publicIp($candidate) !== $candidate || ($after['content'] ?? null) !== $candidate)
            throw new CloudException('usdo1_single_a_scope_changed');
    }

    private function refuse(): never { throw new CloudException('usdo1_single_a_scope_changed'); }
}

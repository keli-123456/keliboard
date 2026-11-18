<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineDnsSetRuntime;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverDnsRun as DnsRun;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution;

/** Recover only the original address or an exact, authenticated native A2 update. */
final class MemberNativeDnsReceipt
{
    private static function need(bool $ok, string $code): void
    {
        if (!$ok) throw new \RuntimeException('member_native_dns_' . $code);
    }

    public static function pins(array $scope, array $original, string $role): array
    {
        self::need(in_array($role, ['control', 'edge-a', 'edge-b'], true), 'role_invalid');
        $pins = [$original]; $proofs = [];
        if ($role !== 'edge-a') return compact('pins', 'proofs');
        $d = $scope['driver'];
        $request = substr(hash('sha256', Json::encode([$d['run_id'], 'native-a'])), 0, 32);
        $key = hash('sha256', json_encode([$d['admin_id'], $request], JSON_THROW_ON_ERROR));
        $runs = Execution::query()->where('service_id', $d['service_id'])->get();
        if ($runs->isEmpty()) return compact('pins', 'proofs');
        self::need($runs->count() === 1 && $runs[0]->request_key === $key
            && $runs[0]->service_revision === $d['service_revision'] && $runs[0]->admin_id === $d['admin_id'], 'execution_changed');
        $run = $runs[0]; $members = Member::query()->where('execution_id', $run->id)->get();
        self::need($members->count() === 1 && $members[0]->policy_id === $d['policy_a'], 'member_changed');
        $member = $members[0];
        $sets = array_values(array_filter($run->payload['snapshot']['dns_sets'] ?? [],
            static fn ($set) => $set['hostname'] === $original['name']));
        self::need(count($sets) === 1, 'set_changed'); $set = $sets[0];
        self::need($set['provider'] === 'aliyun' && $set['ttl'] === 600 && count($set['slots']) === 1
            && $set['slots'][0]['member_id'] === $member->member_id && $set['slots'][0]['type'] === 'A'
            && $set['slots'][0]['line'] === 'default' && $set['slots'][0]['marker'] === $original['marker'], 'set_changed');
        $linked = [];
        foreach (Attempt::query()->where('execution_member_id', $member->id)->orderBy('ordinal')->get() as $attempt) {
            self::need(in_array($attempt->ordinal, [1, 2], true) && $attempt->account_id === $d['account_id'], 'attempt_changed');
            $links = $attempt->payload['dns_set_runs'] ?? [];
            if (!$links) continue;
            self::need($attempt->ordinal === 2 && count($links) === 1 && isset($links[$set['id']]), 'publication_not_permitted');
            $task = Task::query()->findOrFail($attempt->task_id);
            self::need(app(MachineFailoverNativeBridge::class)->bindingCurrent($attempt, $task), 'candidate_changed');
            $dns = DnsRun::query()->findOrFail($links[$set['id']]); $linked[] = $dns->id;
            $runtime = MachineDnsSetRuntime::query()->findOrFail($dns->id);
            self::need($runtime->mode === 'native' && (!$runtime->lease_hash || $runtime->lease_until <= time()), 'publication_busy');
            $b = app(MachineNativeDnsAdmission::class)->publicationBinding($dns); $c = $b['context']; $p = $dns->payload;
            self::need($dns->scenario === 'native_replace' && $dns->set_id === $set['id'] && $dns->set_revision === $set['revision']
                && $dns->service_id === $d['service_id'] && $dns->service_revision === $d['service_revision']
                && $dns->admin_id === $d['admin_id'] && $p['target'] === $member->member_id
                && Json::same($p['snapshot']['set'], array_diff_key($set, ['slots' => true]) + ['service_id' => $d['service_id']])
                && Json::same($p['snapshot']['slots'], $set['slots']) && $b['attempt_id'] === $attempt->id
                && $c['execution_id'] === $run->id && $c['execution_member_id'] === $member->id
                && $c['member_id'] === $member->member_id && $c['policy_id'] === $d['policy_a']
                && $c['attempt_id'] === $attempt->id && $c['ordinal'] === 2
                && $c['source']['task']['id'] === $scope['sources']['source-a']['task_id'], 'publication_binding_changed');
            foreach (['id', 'account_id', 'account_revision', 'external_id', 'installation_id', 'provider_created_at', 'machine_id', 'marker'] as $field)
                self::need(($c['task'][$field] ?? null) === $task->{$field}, 'candidate_changed');
            self::need(count($p['plan']['actions']) === 1 && count($p['initial']) === 1, 'publication_shape_changed');
            $a = $p['plan']['actions'][0];
            $before = ['id' => $original['record_id'], 'hostname' => $original['name'], 'type' => $original['type'],
                'line' => $original['line'], 'content' => $original['value'], 'ttl' => 600,
                'marker' => $original['marker'], 'proxied' => false, 'locked' => false];
            self::need($a['kind'] === 'update' && $a['slot_id'] === $set['slots'][0]['id'] && $a['member_id'] === $member->member_id
                && Json::same($a['before'], $before) && Json::same($p['initial'][0], $before), 'original_record_changed');
            $next = $c['addresses']['A'] ?? null;
            self::need(is_string($next) && filter_var($next, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && \App\Services\ServerMachine\MachineEntryProbe::publicIp($next) === $next
                && $next !== $original['value'] && in_array($next, $task->public_ips, true)
                && Json::same($a['after'], array_replace(array_diff_key($before, ['id' => true]), ['content' => $next])), 'candidate_address_changed');
            self::need(in_array($a['state'], ['prepared', 'dispatched', 'awaiting_readback', 'verified'], true), 'dispatch_state_changed');
            app(MachineDnsRecordSetExecution::class)->assertActionJournal($a, $set);
            $proofs[] = ['dns_run_id' => $dns->id, 'attempt_id' => $attempt->id, 'seal' => $b['seal'],
                'action_sha256' => MemberProviderBindings::digest($a)];
            if ($a['state'] !== 'prepared') $pins[] = array_replace($original, ['value' => $next]);
        }
        self::need(DnsRun::query()->where('service_id', $d['service_id'])->whereNotIn('id', $linked)->count() === 0, 'unexpected_publication');
        return compact('pins', 'proofs');
    }
}

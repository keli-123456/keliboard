<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use App\Services\ServerMachine\Cloud\MachineUsdo1HandoverScope;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use App\Services\ServerMachine\MachineEntryProbe;

/** Offline adapter. The caller must hold one exclusive journal/writer lock across all I/O. */
final class Usdo1ExistingA
{
    public const ZONE = 'dnscloudcloud.top';
    public const NAME = 'usdo1.' . self::ZONE;
    private const SET = ['provider' => 'aliyun', 'zone' => self::ZONE, 'hostname' => self::NAME, 'ttl' => 600];
    private const STEPS = [
        'claim' => ['baseline', 'claimed'], 'publish-source' => ['claimed', 'source'],
        'native-cutover' => ['source', 'candidate'], 'restore-source' => ['source', 'restored'],
        'restore-candidate' => ['candidate', 'restored'], 'unclaim-baseline' => ['claimed', 'baseline'],
        'unclaim-restored' => ['restored', 'baseline'],
    ];

    private array $baseline;
    private string $wireMarker;
    private string $binding;
    private MachineDnsRecordSetProvider $contract;

    public function __construct(
        private string $run,
        array $baseline,
        private string $source,
        string $marker,
        int $startedAt,
        private int $deadline,
        private \Closure $read,
        private \Closure $send,
        private \Closure $authorize,
        private \Closure $load,
        private \Closure $save,
        private \Closure $clock,
        private bool $manualBaseline = false,
    ) {
        $this->contract = new MachineDnsRecordSetProvider;
        $this->baseline = $this->snapshot($baseline);
        $this->wireMarker = MachineDnsRecordSetProvider::aliyunMarker($marker);
        self::need(preg_match('/^keli-accept-[a-z0-9-]{8,70}$/D', $run) === 1
            && ($manualBaseline
                ? Json::same($this->baseline, MachineUsdo1HandoverScope::baseline($baseline)) && $this->baseline['Value'] === $source
                : $this->baseline['TTL'] === 60 && $this->baseline['Value'] !== $source)
            && self::isIPv4($source)
            && !str_starts_with($this->baseline['Remark'], 'keli-set-')
            && 0 < $startedAt && $startedAt + 2100 < $deadline && $deadline <= $startedAt + 7200, 'scope_invalid');
        $this->binding = hash('sha256', Json::encode([$run, $this->baseline, $source, $marker, $startedAt, $deadline,
            ...($manualBaseline ? ['manual-usdo1-a-v1'] : [])]));
    }

    private static function need(bool $ok, string $code): void
    {
        if (!$ok) throw new \RuntimeException('usdo1_existing_a_' . $code);
    }

    private static function isIPv4(string $address): bool
    {
        return !str_contains($address, ':') && MachineEntryProbe::publicIp($address) === $address;
    }

    private function snapshot(array $raw): array
    {
        $record = $this->contract->record(self::SET, $raw);
        self::need(($raw['RR'] ?? null) === 'usdo1' && $record['type'] === 'A' && $record['line'] === 'default'
            && !$record['locked'] && array_key_exists('Remark', $raw) && self::isIPv4($record['content'])
            && preg_match('/[\x00-\x1f\x7f]/', $raw['Remark']) === 0, 'record_invalid');
        // Keep the exact original wire remark, not the ownership decoder's canonical marker.
        return ['RecordId' => $record['id'], 'DomainName' => self::ZONE, 'RR' => 'usdo1', 'Type' => 'A', 'Line' => 'default',
            'Value' => $record['content'], 'TTL' => $record['ttl'], 'Remark' => $raw['Remark'], 'Status' => 'Enable', 'Locked' => false];
    }

    private function current(): array
    {
        // The transport must reconcile the complete exact-name inventory with a detail read of the pinned ID.
        $rows = ($this->read)(self::NAME, $this->baseline['RecordId']);
        self::need(is_array($rows) && array_is_list($rows) && count($rows) === 1 && is_array($rows[0]), 'inventory_changed');
        $row = $this->snapshot($rows[0]);
        self::need($row['RecordId'] === $this->baseline['RecordId'], 'record_id_changed');
        return $row;
    }

    private function expected(string $phase, ?string $candidate): array
    {
        return match ($phase) {
            'baseline' => $this->baseline,
            'claimed', 'restored' => array_replace($this->baseline, ['Remark' => $this->wireMarker]),
            'source', 'candidate' => array_replace($this->baseline, ['Remark' => $this->wireMarker, 'TTL' => 600,
                'Value' => $phase === 'source' ? $this->source : $candidate]),
        };
    }

    private function state(): array
    {
        $state = ($this->load)();
        if ($state === null) return [
            'binding' => $this->binding, 'stopped' => false, 'phase' => 'baseline', 'candidate' => null,
            'attempted' => [], 'pending' => null, 'published' => false, 'restored_at' => null,
        ];
        self::need(is_array($state) && count($state) === 8 && ($state['binding'] ?? null) === $this->binding
            && is_bool($state['stopped'] ?? null) && is_bool($state['published'] ?? null)
            && in_array($state['phase'] ?? null, ['baseline', 'claimed', 'source', 'candidate', 'restored'], true)
            && is_array($state['attempted'] ?? null) && array_is_list($state['attempted'])
            && array_key_exists('candidate', $state) && array_key_exists('pending', $state) && array_key_exists('restored_at', $state), 'journal_changed');
        foreach ($state['attempted'] as $step) self::need(is_string($step) && isset(self::STEPS[$step]), 'journal_changed');
        self::need(count(array_unique($state['attempted'])) === count($state['attempted'])
            && (in_array('native-cutover', $state['attempted'], true)
                ? is_string($state['candidate']) && self::isIPv4($state['candidate'])
                    && !in_array($state['candidate'], [$this->source, $this->baseline['Value']], true)
                : $state['candidate'] === null)
            && ($state['restored_at'] === null || is_int($state['restored_at']) && $state['restored_at'] > 0)
            && (!in_array($state['phase'], $this->manualBaseline ? ['candidate', 'restored'] : ['source', 'candidate', 'restored'], true) || $state['published'])
            && ($state['phase'] !== 'candidate' || $state['candidate'] !== null), 'journal_changed');
        if ($state['pending'] !== null) {
            $step = $state['pending']['step'] ?? null;
            self::need(is_string($step) && ($state['attempted'][count($state['attempted']) - 1] ?? null) === $step
                && self::STEPS[$step][0] === $state['phase']
                && Json::same($state['pending'], $this->intent($step, $state['candidate'])), 'journal_changed');
        }
        return $state;
    }

    private function persist(array $state): void
    {
        ($this->save)($state);
        self::need(Json::same($state, ($this->load)()), 'journal_unconfirmed');
    }

    private function permit(string $operation, array $context): void
    {
        self::need(($this->authorize)($operation, ['run_id' => $this->run, 'binding' => $this->binding] + $context) === true, 'guard_refused');
    }

    private function complete(array $state, string $phase): array
    {
        $state['phase'] = $phase;
        $state['pending'] = null;
        if ($phase === 'candidate' || ($phase === 'source' && !$this->manualBaseline)) $state['published'] = true;
        if ($phase === 'restored') $state['restored_at'] = ($this->clock)();
        $this->persist($state);
        return $state;
    }

    private function intent(string $step, ?string $candidate): array
    {
        [$from, $to] = self::STEPS[$step];
        $before = $this->expected($from, $candidate);
        $after = $this->expected($to, $candidate);
        $id = $before['RecordId'];
        $wire = $step === 'native-cutover' ? $this->contract->mutation(self::SET, ['kind' => 'update',
            'before' => $this->contract->record(self::SET, $before), 'after' => $this->contract->record(self::SET, $after)], $id)
            : ($before['Remark'] !== $after['Remark']
            ? ['action' => 'UpdateDomainRecordRemark', 'parameters' => ['RecordId' => $id, 'Remark' => $after['Remark']]]
            : ['action' => 'UpdateDomainRecord', 'parameters' => ['RecordId' => $id, 'RR' => 'usdo1', 'Type' => 'A',
                'Line' => 'default', 'Value' => $after['Value'], 'TTL' => $after['TTL']]]);
        return ['step' => $step, 'before' => $before, 'after' => $after, 'wire' => ['method' => 'POST'] + $wire];
    }

    private function write(array $state, string $step): array
    {
        [$from, $to] = self::STEPS[$step];
        $rollingBack = in_array($step, ['restore-source', 'restore-candidate', 'unclaim-baseline', 'unclaim-restored'], true);
        self::need($state['phase'] === $from && $state['stopped'] === $rollingBack && $state['pending'] === null
            && !in_array($step, $state['attempted'], true), 'write_not_available');
        self::need($rollingBack || ($this->clock)() < $this->deadline - 2100, 'rollback_reserve_reached');
        $intent = $this->intent($step, $state['candidate']);
        ['before' => $before, 'after' => $after, 'wire' => $wire] = $intent;
        $id = $before['RecordId'];
        $this->permit($step, $intent);
        self::need($this->current() === $before, 'snapshot_changed');
        $state['pending'] = $intent;
        $state['attempted'][] = $step;
        // A crash after this point is read back under stop; this step is never replayed.
        $this->persist($state);
        self::need($this->current() === $before, 'snapshot_changed');
        $this->permit($step, $intent);
        self::need($rollingBack || ($this->clock)() < $this->deadline - 2100, 'rollback_reserve_reached');
        $response = ($this->send)($wire);
        self::need(is_array($response) && is_string($response['RequestId'] ?? null) && $response['RequestId'] !== ''
            && strlen($response['RequestId']) <= 100
            && ($response['RecordId'] ?? ($wire['action'] === 'UpdateDomainRecordRemark' ? $id : null)) === $id,
            'acknowledgment_unknown');
        self::need($this->current() === $after, 'write_readback_unconfirmed');
        return $this->complete($state, $to);
    }

    public function adopt(): array
    {
        $state = $this->state();
        if ($state['phase'] === 'baseline') $state = $this->write($state, 'claim');
        if ($state['phase'] === 'claimed') {
            if ($this->manualBaseline) {
                self::need(!$state['stopped'] && $state['pending'] === null && ($this->clock)() < $this->deadline - 2100
                    && $this->current() === $this->expected('source', null), 'source_not_ready');
                // The original A already points to #17 at TTL 600; claiming must not rewrite its address.
                $state = $this->complete($state, 'source');
            } else $state = $this->write($state, 'publish-source');
        }
        self::need($state['phase'] === 'source' && !$state['stopped'] && $state['pending'] === null
            && $this->current() === $this->expected('source', null), 'source_not_ready');
        return $this->contract->record(self::SET, $this->expected('source', null));
    }

    public function cutover(array $nativeAction): string
    {
        $state = $this->state();
        $candidate = $nativeAction['after']['content'] ?? null;
        self::need(is_string($candidate) && self::isIPv4($candidate) && $candidate !== $this->source
            && $candidate !== $this->baseline['Value'] && ($nativeAction['kind'] ?? null) === 'update', 'native_plan_invalid');
        $before = $this->contract->record(self::SET, $this->expected('source', null));
        $after = $this->contract->record(self::SET, $this->expected('candidate', $candidate));
        unset($after['id']); // The native planner's desired record does not carry a provider ID.
        self::need(Json::same($nativeAction['before'] ?? null, $before) && Json::same($nativeAction['after'] ?? null, $after), 'native_plan_changed');
        $this->permit('native-evidence', ['plan' => $nativeAction]);
        $state['candidate'] = $candidate;
        $this->write($state, 'native-cutover');
        return $this->baseline['RecordId'];
    }

    public function candidateRecord(): array
    {
        $state = $this->state();
        self::need($state['phase'] === 'candidate' && !$state['stopped'] && $state['pending'] === null,
            'candidate_unconfirmed');
        $row = $this->current();
        self::need($row === $this->expected('candidate', $state['candidate']), 'candidate_unconfirmed');
        return $row;
    }

    public function stopAndSettle(): void
    {
        $state = $this->state();
        $state['stopped'] = true;
        $this->persist($state);
        $this->permit('stop-and-drain', ['pending' => $state['pending']]);
        $row = $this->current();
        if ($state['pending'] === null) {
            self::need($row === $this->expected($state['phase'], $state['candidate']), 'snapshot_changed');
            return;
        }
        $pending = $state['pending'];
        $applied = Json::same($row, $pending['after']);
        self::need($applied || Json::same($row, $pending['before']), 'snapshot_changed');
        // A matching record alone cannot prove a lost or late provider request has settled.
        $this->permit('settle-intent', ['intent' => $pending, 'observation' => $row]);
        [, $to] = self::STEPS[$pending['step']];
        $this->complete($state, $applied ? $to : $state['phase']);
    }

    public function rollback(): void
    {
        $this->stopAndSettle();
        $state = $this->state();
        if ($state['phase'] === 'source' && $this->manualBaseline) {
            self::need($this->current() === $this->expected('claimed', null), 'snapshot_changed');
            $state = $this->complete($state, 'claimed');
        }
        if ($state['phase'] === 'source' || $state['phase'] === 'candidate') $state = $this->write($state, 'restore-' . $state['phase']);
        if ($state['phase'] === 'restored') $state = $this->write($state, 'unclaim-restored');
        if ($state['phase'] === 'claimed') $state = $this->write($state, 'unclaim-baseline');
        self::need($state['phase'] === 'baseline' && $this->current() === $this->baseline, 'rollback_unconfirmed');
    }

    public function confirmDnsClearForCleanup(): void
    {
        $state = $this->state();
        self::need($state['stopped'] && $state['pending'] === null && $state['phase'] === 'baseline'
            && $this->current() === $this->baseline, 'rollback_unconfirmed');
        self::need(!$state['published'] || is_int($state['restored_at']) && ($this->clock)() - $state['restored_at'] >= 600, 'cache_drain_pending');
        $this->permit('external-rollback-readback', ['baseline' => $this->baseline, 'restored_at' => $state['restored_at']]);
        self::need($this->current() === $this->baseline, 'rollback_unconfirmed');
    }
}

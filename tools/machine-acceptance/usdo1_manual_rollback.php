<?php

declare(strict_types=1);

namespace Keli\Acceptance;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence as Evidence;
use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use App\Services\ServerMachine\Cloud\MachineUsdo1HandoverScope as Scope;

require_once __DIR__ . '/usdo1_existing_a.php';

/** Transport-free preparation. The caller owns the exclusive provider write lock and the durable journal. */
final class Usdo1ManualRollback
{
    private array $baseline;
    private array $pin;

    public function __construct(private string $run, array $record, array $source, private string $marker,
        private int $startedAt, private int $deadline, private Evidence $evidence, private \Closure $stoppedScope,
        private \Closure $read, private \Closure $recover, private \Closure $load, private \Closure $save,
        private \Closure $sourceHealthy, private \Closure $resolversRestored, private \Closure $clock)
    {
        $this->baseline = Scope::baseline($record);
        $this->pin = Scope::pin($source, $this->baseline);
    }

    public function closureScope(): array
    {
        return $this->forClosure($this->parent());
    }

    public function approvalScope(array $closureProof): array
    {
        $scope = $this->closureScope();
        return $this->approval($scope, $this->evidence->closed($scope, $closureProof, ($this->clock)()));
    }

    public function restore(array $closureProof, array $rollbackProof): void
    {
        $this->adapter($closureProof, $rollbackProof)->rollback();
    }

    public function confirmClearForCleanup(array $closureProof): void
    {
        $this->adapter($closureProof, null)->confirmDnsClearForCleanup();
    }

    private function parent(): array
    {
        // Use the stopped grant journal (including late receipts), never a worker's self-report.
        $scope = ($this->stoppedScope)();
        self::need(is_array($scope) && ($scope['manual_scope_sha256'] ?? null) === Evidence::hash($this->pin)
            && is_int($scope['settled_at'] ?? null) && is_array($scope['operations'] ?? null)
            && array_is_list($scope['operations']), 'stop_unconfirmed');
        return $scope;
    }

    private function forClosure(array $scope): array
    {
        $pending = (($this->load)())['pending'] ?? null;
        if ($pending !== null) {
            self::need(is_array($pending) && ($pending['before']['RecordId'] ?? null) === $this->pin['record_id']
                && ($pending['after']['RecordId'] ?? null) === $this->pin['record_id'], 'pending_changed');
            $digest = Evidence::hash($pending);
            $scope['operations'][] = ['operation_id' => 'usdo1_a:' . $digest, 'intent_sha256' => $digest, 'known_outcome' => null];
        }
        return $scope;
    }

    private function approval(array $scope, array $closed): array
    {
        return ['scope_sha256' => Evidence::hash($scope), 'closure_proof_sha256' => $closed['proof_sha256'],
            'baseline_sha256' => $this->pin['baseline_sha256'], 'settled_at' => max($scope['settled_at'], $closed['observed_at'])];
    }

    private function adapter(array $closureProof, ?array $rollbackProof): Usdo1ExistingA
    {
        $parent = $this->parent();
        $scope = $this->forClosure($parent);
        $closed = $this->evidence->closed($scope, $closureProof, ($this->clock)());
        $approval = $this->approval($scope, $closed);
        if ($rollbackProof !== null) $this->evidence->manualRollback($approval, $rollbackProof, ($this->clock)());
        $baseline = $this->baseline;
        return new Usdo1ExistingA($this->run, $baseline, $baseline['Value'], $this->marker, $this->startedAt, $this->deadline,
            $this->read, function (array $wire) use ($baseline): array {
                $expected = match ($wire['action']) {
                    'UpdateDomainRecord' => ['RecordId' => $baseline['RecordId'], 'RR' => 'usdo1', 'Type' => 'A',
                        'Line' => 'default', 'Value' => $baseline['Value'], 'TTL' => Scope::TTL],
                    'UpdateDomainRecordRemark' => ['RecordId' => $baseline['RecordId'], 'Remark' => $baseline['Remark']],
                    default => null,
                };
                self::need($expected !== null && ($wire['method'] ?? null) === 'POST'
                    && Json::same($expected, $wire['parameters']), 'wire_forbidden');
                return ($this->recover)($wire);
            }, function (string $op, array $details) use ($parent, $scope, $closed, $closureProof, $approval, $rollbackProof): bool {
                self::need(Json::same($this->parent(), $parent), 'stopped_journal_changed');
                $now = ($this->clock)();
                $this->evidence->closed($scope, $closureProof, $now);
                if ($op === 'stop-and-drain') return true;
                if ($op === 'external-rollback-readback') return ($this->sourceHealthy)($this->pin['source']) === true
                    && ($this->resolversRestored)($details['baseline']) === true;
                if ($op === 'settle-intent') {
                    $intent = $details['intent'];
                    $outcome = array_column($closed['outcomes'], 'result', 'operation_id')['usdo1_a:' . Evidence::hash($intent)] ?? null;
                    return match ($outcome) {
                        'confirmed_applied' => Json::same($intent['after'], $details['observation']),
                        'confirmed_not_applied' => Json::same($intent['before'], $details['observation']),
                        default => false,
                    };
                }
                if (!in_array($op, ['restore-candidate', 'unclaim-baseline', 'unclaim-restored'], true) || $rollbackProof === null
                    || ($this->sourceHealthy)($this->pin['source']) !== true) return false;
                // Check again after the health read, immediately before the underlying adapter can send.
                self::need(Json::same($this->parent(), $parent), 'stopped_journal_changed');
                $now = ($this->clock)();
                $this->evidence->closed($scope, $closureProof, $now);
                $this->evidence->manualRollback($approval, $rollbackProof, $now);
                return true;
            }, $this->load, $this->save, $this->clock, manualBaseline: true);
    }

    private static function need(bool $valid, string $reason): void
    {
        if (!$valid) throw new CloudException('usdo1_manual_rollback_' . $reason);
    }
}

<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence;
use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;

require_once __DIR__ . '/usdo1_native.php';

/** Separate stop/readback authority and recovery transport; no native advance or delete operation. */
final class Usdo1Rollback
{
    public function __construct(private Usdo1Native $native, private array $scope, private MachineFailoverHandoverEvidence $evidence,
        private \Closure $read, private \Closure $recover, private \Closure $loadDns, private \Closure $saveDns,
        private \Closure $verifyTarget, private \Closure $verifyResolvers, private \Closure $faultReceipt, private \Closure $clock)
    {
        $baseline = $scope['baseline'] ?? [];
        self::need(($scope['target']['machine_id'] ?? null) === 17 && is_string($scope['target']['external_id'] ?? null)
            && preg_match('/^[1-9][0-9]{0,15}$/D', $scope['target']['external_id']) === 1
            && is_string($scope['target']['ipv4'] ?? null) && ($baseline['Value'] ?? null) === $scope['target']['ipv4']
            && ($baseline['DomainName'] ?? null) === Usdo1ExistingA::ZONE && ($baseline['RR'] ?? null) === 'usdo1'
            && ($baseline['TTL'] ?? null) === 60 && is_string($baseline['Remark'] ?? null), 'scope_invalid');
    }

    private static function need(bool $ok, string $reason): void
    { if (!$ok) throw new CloudException('usdo1_rollback_' . $reason); }

    private static function hash(array $data): string { return hash('sha256', Json::encode($data)); }

    private function restoredFaultDigest(): string
    {
        $receipt = ($this->faultReceipt)();
        self::need(is_string($receipt) && preg_match('/^[a-f0-9]{64}$/D', $receipt) === 1, 'fault_restoration_unconfirmed');
        return $receipt;
    }

    public function closureScope(): array
    {
        $stop = $this->native->stoppedSnapshot();
        self::need($stop !== null && $stop['record_id'] === $this->scope['baseline']['RecordId'], 'stop_not_confirmed');
        $pending = (($this->loadDns)())['pending'] ?? null;
        if ($pending !== null) {
            self::need(is_array($pending) && ($pending['before']['RecordId'] ?? null) === $stop['record_id']
                && ($pending['after']['RecordId'] ?? null) === $stop['record_id'], 'pending_changed');
            $stop['operations'][] = ['operation_id' => self::hash($pending), 'known_outcome' => null];
        }
        return $stop + ['target_sha256' => self::hash($this->scope['target']), 'baseline_sha256' => self::hash($this->scope['baseline']),
            'fault_receipt_sha256' => $this->restoredFaultDigest()];
    }

    public function restore(array $proof): void
    {
        $this->adapter($proof)->rollback();
    }

    public function confirmClearForCleanup(array $proof): void
    {
        $this->adapter($proof)->confirmDnsClearForCleanup();
    }

    private function adapter(array $proof): Usdo1ExistingA
    {
        $scope = $this->closureScope();
        $closed = $this->evidence->closed($scope, $proof, ($this->clock)());
        self::need(($this->verifyTarget)($this->scope['target']) === true, 'target_unconfirmed');
        $baseline = $this->scope['baseline'];
        return new Usdo1ExistingA($this->scope['run_id'], $baseline, $this->scope['source_ipv4'], $this->scope['marker'],
            $this->scope['started_at'], $this->scope['deadline'], $this->read, function (array $wire) use ($baseline): array {
                $parameters = $wire['parameters'];
                $expected = match ($wire['action']) {
                    'UpdateDomainRecord' => ['RecordId' => $baseline['RecordId'], 'RR' => 'usdo1', 'Type' => 'A',
                        'Line' => 'default', 'Value' => $baseline['Value'], 'TTL' => 60],
                    'UpdateDomainRecordRemark' => ['RecordId' => $baseline['RecordId'], 'Remark' => $baseline['Remark']],
                    default => null,
                };
                self::need($expected !== null && ($wire['method'] ?? null) === 'POST' && Json::same($parameters, $expected), 'wire_forbidden');
                return ($this->recover)($wire);
            }, function (string $op, array $details) use ($scope, $closed, $proof): bool {
                // The provider-intent list may change within this call. The stopped native lineage may not.
                $native = $this->native->stoppedSnapshot();
                if ($native === null || $native['native_sha256'] !== $scope['native_sha256']
                    || $this->restoredFaultDigest() !== $scope['fault_receipt_sha256'] || ($this->clock)() >= $proof['body']['valid_until']) return false;
                return match ($op) {
                    'stop-and-drain' => true,
                    'settle-intent' => $this->settle($closed['outcomes'], $details['intent'], $details['observation']),
                    'restore-source', 'restore-candidate', 'unclaim-baseline', 'unclaim-restored' => ($this->verifyTarget)($this->scope['target']) === true,
                    'external-rollback-readback' => ($this->verifyResolvers)($details['baseline']) === true,
                    default => false,
                };
            }, $this->loadDns, $this->saveDns, $this->clock);
    }

    private function settle(array $outcomes, array $intent, array $row): bool
    {
        $result = array_column($outcomes, 'result', 'operation_id')[self::hash($intent)] ?? null;
        return match ($result) {
            'confirmed_applied' => Json::same($row, $intent['after']),
            'confirmed_not_applied' => Json::same($row, $intent['before']),
            default => false,
        };
    }
}

<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\Dns\MachineDnsRecordSetPlanner;
use Keli\Acceptance\Usdo1ExistingA;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/usdo1_existing_a.php';

final class MachineUsdo1ExistingATest extends TestCase
{
    private const START = 1000000;
    private const ORIGINAL = '192.0.1.10';
    private const SOURCE = '192.0.1.11';
    private const CANDIDATE = '192.0.1.12';
    private const MARKER = 'keli-set-1234567890abcdef1234567890abcdef1234567890abcdef';

    private int $now = self::START;
    private ?array $journal = null;
    private array $wire = [];
    private array $denied = [];
    private int $lostAt = 0;
    private bool $lostApplied = true;
    private bool $extra = false;
    private array $original = [
        'RecordId' => '5580', 'DomainName' => Usdo1ExistingA::ZONE, 'RR' => 'usdo1', 'Type' => 'A', 'Line' => 'default',
        'Value' => self::ORIGINAL, 'TTL' => 60, 'Remark' => '', 'Status' => 'Enable', 'Locked' => false,
    ];
    private array $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->record = $this->original;
    }

    private function driver(): Usdo1ExistingA
    {
        return new Usdo1ExistingA('keli-accept-test-usdo1', $this->original, self::SOURCE, self::MARKER, self::START,
            self::START + 7200, function (string $name, string $id): array {
                self::assertSame('usdo1.dnscloudcloud.top', $name);
                self::assertSame('5580', $id);
                return $this->extra ? [$this->record, ['RecordId' => '5581'] + $this->record] : [$this->record];
            }, function (array $wire): array {
                self::assertSame('POST', $wire['method']);
                self::assertSame('5580', $wire['parameters']['RecordId']);
                self::assertSame($wire, $this->journal['pending']['wire']);
                $this->wire[] = $wire;
                $lost = count($this->wire) === $this->lostAt;
                if (!$lost || $this->lostApplied) {
                    $p = $wire['parameters'];
                    if ($wire['action'] === 'UpdateDomainRecordRemark') {
                        self::assertSame(['RecordId', 'Remark'], array_keys($p));
                        $this->record['Remark'] = $p['Remark'];
                    } else {
                        self::assertSame('UpdateDomainRecord', $wire['action']);
                        self::assertSame(['RR' => 'usdo1', 'Type' => 'A', 'Line' => 'default'],
                            array_intersect_key($p, ['RR' => true, 'Type' => true, 'Line' => true]));
                        $this->record['Value'] = $p['Value'];
                        $this->record['TTL'] = $p['TTL'];
                    }
                }
                if ($lost) throw new \RuntimeException('fixture_ack_lost');
                return $wire['action'] === 'UpdateDomainRecordRemark' ? ['RequestId' => 'fixture-request']
                    : ['RecordId' => '5580', 'RequestId' => 'fixture-request'];
            }, fn (string $operation): bool => !in_array($operation, $this->denied, true),
            fn () => $this->journal, function (array $state): void { $this->journal = $state; }, fn () => $this->now);
    }

    private function plan(array $rows, string $ip): array
    {
        return (new MachineDnsRecordSetPlanner)->plan([
            'set' => ['hostname' => Usdo1ExistingA::NAME, 'ttl' => 600],
            'slots' => [['id' => 1, 'member_id' => 2, 'marker' => self::MARKER, 'type' => 'A', 'line' => 'default']],
            'members' => [['id' => 2, 'enabled' => true]],
        ], [2 => ['A' => $ip]], $rows, 'replace', 2)['actions'];
    }

    private function refuses(string $code, \Closure $operation): void
    {
        try {
            $operation();
            self::fail('Expected isolation guard: ' . $code);
        } catch (\RuntimeException $error) {
            self::assertSame($code, $error->getMessage());
        }
    }

    public function testNativePlanUsesOnlySameAAndRollbackRestoresBlankRemarkAndTtl(): void
    {
        $driver = $this->driver();
        $source = $driver->adopt();
        self::assertSame($source, $driver->adopt());
        self::assertSame([], $this->plan([$source], self::SOURCE));
        $plan = $this->plan([$source], self::CANDIDATE);
        self::assertCount(1, $plan);
        self::assertSame('5580', $driver->cutover($plan[0]));
        self::assertSame(self::CANDIDATE, $this->record['Value']);
        $this->refuses('usdo1_existing_a_write_not_available', fn () => $driver->cutover($plan[0]));
        $driver->rollback();
        $driver->rollback();
        self::assertSame($this->original, $this->record);
        self::assertSame(['UpdateDomainRecordRemark', 'UpdateDomainRecord', 'UpdateDomainRecord', 'UpdateDomainRecord', 'UpdateDomainRecordRemark'],
            array_column($this->wire, 'action'));
        self::assertSame([600, 600, 60], array_column(array_column($this->wire, 'parameters'), 'TTL'));
        self::assertSame('', $this->wire[4]['parameters']['Remark']);
        $this->now += 599;
        $this->refuses('usdo1_existing_a_cache_drain_pending', $driver->confirmDnsClearForCleanup(...));
        $this->now++;
        $this->denied = ['external-rollback-readback'];
        $this->refuses('usdo1_existing_a_guard_refused', $driver->confirmDnsClearForCleanup(...));
        $this->denied = [];
        $driver->confirmDnsClearForCleanup();
    }

    public function testNativeCannotWriteBeforeEvidenceOrWithDifferentPlan(): void
    {
        $driver = $this->driver();
        $source = $driver->adopt();
        $plan = $this->plan([$source], self::CANDIDATE)[0];
        $wrong = $plan; $wrong['before']['id'] = '5581';
        $this->refuses('usdo1_existing_a_native_plan_changed', fn () => $driver->cutover($wrong));
        $wrong = $plan; $wrong['after']['ttl'] = 60;
        $this->refuses('usdo1_existing_a_native_plan_changed', fn () => $driver->cutover($wrong));
        $wrong = $plan; $wrong['kind'] = 'create';
        $this->refuses('usdo1_existing_a_native_plan_invalid', fn () => $driver->cutover($wrong));
        $this->denied = ['native-evidence'];
        $this->refuses('usdo1_existing_a_guard_refused', fn () => $driver->cutover($plan));
        self::assertCount(2, $this->wire);
        self::assertSame(self::SOURCE, $this->record['Value']);
    }

    public function testLiveDriftOrSiblingRecordPreventsClaimAndRollback(): void
    {
        $driver = $this->driver();
        $this->record['RecordId'] = '5582';
        $this->refuses('usdo1_existing_a_record_id_changed', $driver->adopt(...));
        $this->record = $this->original;
        $this->extra = true;
        $this->refuses('usdo1_existing_a_inventory_changed', $driver->adopt(...));
        $this->extra = false;
        $driver->adopt();
        $this->record['Remark'] = 'other-operator';
        $this->refuses('usdo1_existing_a_snapshot_changed', $driver->rollback(...));
        self::assertCount(2, $this->wire);
        self::assertSame('other-operator', $this->record['Remark']);
    }

    public function testLostCutoverResponseIsNotReplayedAndRequiresFinalEvidence(): void
    {
        $driver = $this->driver();
        $plan = $this->plan([$driver->adopt()], self::CANDIDATE)[0];
        $this->lostAt = 3;
        $this->refuses('fixture_ack_lost', fn () => $driver->cutover($plan));
        ksort($this->journal['pending']['before']);
        ksort($this->journal['pending']['after']);
        $restarted = $this->driver();
        $this->refuses('usdo1_existing_a_write_not_available', fn () => $restarted->cutover($plan));
        $this->denied = ['stop-and-drain'];
        $this->refuses('usdo1_existing_a_guard_refused', $restarted->rollback(...));
        self::assertTrue($this->journal['stopped']);
        $this->denied = ['settle-intent'];
        $this->refuses('usdo1_existing_a_guard_refused', $restarted->rollback(...));
        self::assertCount(3, $this->wire);
        $this->denied = [];
        $restarted->rollback();
        self::assertSame($this->original, $this->record);
        self::assertCount(5, $this->wire);
    }

    public function testPartiallyClaimedRecordRestoresExactNonemptyRemarkWithoutAddressWrite(): void
    {
        $this->original['Remark'] = $this->record['Remark'] = 'prior-Remark_01';
        $driver = $this->driver();
        $this->lostAt = 2;
        $this->lostApplied = false;
        $this->refuses('fixture_ack_lost', $driver->adopt(...));
        $this->now += 7201;
        $driver->rollback();
        $driver->confirmDnsClearForCleanup();
        self::assertSame($this->original, $this->record);
        self::assertCount(3, $this->wire);
        self::assertSame('UpdateDomainRecordRemark', $this->wire[2]['action']);
        self::assertSame('prior-Remark_01', $this->wire[2]['parameters']['Remark']);
    }

    public function testUncertainRollbackWriteCannotBeRepeatedOrPermitCleanup(): void
    {
        $driver = $this->driver();
        $driver->adopt();
        $this->lostAt = 3;
        $this->lostApplied = false;
        $this->refuses('fixture_ack_lost', $driver->rollback(...));
        $this->refuses('usdo1_existing_a_write_not_available', $driver->rollback(...));
        $this->refuses('usdo1_existing_a_rollback_unconfirmed', $driver->confirmDnsClearForCleanup(...));
        self::assertCount(3, $this->wire);
        self::assertSame(self::SOURCE, $this->record['Value']);
    }

    public function testReserveStopsAdoptionAndJournalIntentTamperCannotReconcile(): void
    {
        $driver = $this->driver();
        $this->now = self::START + 5100;
        $this->refuses('usdo1_existing_a_rollback_reserve_reached', $driver->adopt(...));
        self::assertSame([], $this->wire);
        $this->now--;
        $this->lostAt = 1;
        $this->refuses('fixture_ack_lost', $driver->adopt(...));
        $this->journal['pending']['after']['Value'] = self::CANDIDATE;
        $this->refuses('usdo1_existing_a_journal_changed', $driver->rollback(...));
        self::assertCount(1, $this->wire);
    }
}

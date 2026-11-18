<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use Keli\Acceptance\Usdo1ExistingA;
use Keli\Acceptance\Usdo1Round;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/usdo1_round.php';

final class MachineUsdo1RoundTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private const START = 1000000;
    private const ROLES = ['control', 'source', 'candidate'];
    private const IP = ['rollback' => '192.0.1.17', 'control' => '192.0.1.21', 'source' => '192.0.1.22', 'candidate' => '192.0.1.23'];
    private const TARGETS = ['192.0.1.31', '192.0.1.32', '192.0.1.33', '192.0.1.34'];
    private const ORIGINAL = ['RecordId' => '6718', 'DomainName' => Usdo1ExistingA::ZONE, 'RR' => 'usdo1', 'Type' => 'A',
        'Line' => 'default', 'Value' => self::IP['rollback'], 'TTL' => 60, 'Remark' => 'Before_test', 'Status' => 'Enable', 'Locked' => false];

    private int $now = self::START;
    private array $record = self::ORIGINAL;
    private array $journal = [];
    private array $creates = [];
    private array $pins = [];
    private array $present = [];
    private array $writes = [];
    private array $dnsOutcomes = [];
    private array $deletes = [];
    private array $absentReads = [];
    private int $stops = 0;
    private bool $stopDrained = true;
    private bool $loseStop = false;
    private bool $dnsSettled = true;
    private ?string $loseCreate = null;
    private ?string $loseWrite = null;
    private bool $applyLostWrite = true;
    private ?string $loseDelete = null;
    private ?string $pendingRole = null;
    private ?string $badReadRole = null;
    private ?string $notReadyRole = null;
    private ?string $recursiveOverride = null;
    private bool $badRollbackPin = false;
    private string $sourceSignal = 'failed';
    private string $candidateSignal = 'healthy';
    private int $sourceFailures = 0;
    private ?int $lastSourceRound = null;
    private bool $nativeThreshold = true;
    private string $episodeDigit = 'd';
    private ?int $freezeCandidateAgentAt = null;
    private ?int $freezeSourceProbeAt = null;
    private ?int $addressRestoredAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('env', 'testing');
        $this->setUpInMemoryDatabase();
        config(['machine_failover.runner_enabled' => false, 'machine_failover.allow_rotation' => false]);
    }

    private function scope(): array
    {
        return ['run_id' => 'keli-accept-test-usdo1-round', 'account_id' => 31, 'protected_ids' => ['78017', '78008'],
            'rollback' => ['machine_id' => 17, 'external_id' => '78017', 'ipv4' => self::IP['rollback']],
            'baseline' => self::ORIGINAL, 'marker' => 'keli-set-' . str_repeat('a', 48),
            'started_at' => self::START, 'deadline' => self::START + 7200, 'probe_ipv4_targets' => self::TARGETS,
            'binary_sha256' => str_repeat('b', 64)];
    }

    private function tick(int $seconds = 5, bool $stop = false): array
    {
        $this->now += $seconds;
        // Reconstruct on every tick from independently read, key-normalized JSON.
        return (new Usdo1Round($this->scope(), $this->port(...), fn ($key) => $this->journal[$key] ?? null,
            function ($key, $value) { $this->journal[$key] = json_decode(Json::encode($value), true, flags: JSON_THROW_ON_ERROR); },
            fn () => $this->now))->step($stop);
    }

    private function reach(string $phase, int $ticks = 24): array
    {
        $state = $this->journal['round'] ?? [];
        for ($i = 0; $i < $ticks && ($state['phase'] ?? null) !== $phase; $i++) $state = $this->tick();
        self::assertSame($phase, $state['phase'], Json::encode(array_intersect_key($state, ['first_error' => true, 'recovery_error' => true])));
        return $state;
    }

    private function port(string $operation, array $args): array
    {
        self::assertSame($this->scope()['run_id'], $args['run_id']);
        self::assertSame($this->journal['round']['binding'], $args['binding']);
        $role = $args['role'] ?? null;
        return match ($operation) {
            'dispatch-provision' => $this->dispatchCreate($role, $args),
            'read-provision' => ['intent' => $this->journal['round']['roles'][$role]['intent'],
                'status' => $this->pendingRole === $role ? 'pending' : (isset($this->pins[$role]) ? 'created' : 'not-created')]
                + ($this->pendingRole !== $role && isset($this->pins[$role]) ? ['pin' => $this->pins[$role], 'ready' => $role !== $this->notReadyRole] : []),
            'read-observation' => $this->observe($role, $args),
            'stop-writer' => $this->dispatchStop(),
            'read-stop' => ['binding' => $args['binding'], 'write_fenced' => $this->stops === 1,
                'in_flight' => $this->stopDrained ? 0 : 1, 'fault_restored' => $this->stopDrained],
            'read-dns' => $this->readRecord($args),
            'update-dns' => $this->writeRecord($args['wire']),
            'settle-dns' => ['intent_sha256' => $args['intent_sha256'], 'in_flight' => !$this->dnsSettled,
                'applied' => $this->dnsOutcomes[$args['intent_sha256']] ?? null],
            'read-rollback-target' => ['pin' => $this->badRollbackPin ? ['machine_id' => 8] : $this->scope()['rollback'], 'healthy' => true],
            'read-external-dns' => ['authority_1' => [$this->record['Value']], 'authority_2' => [$this->record['Value']],
                'recursive_1' => [$this->record['Value']], 'recursive_2' => [$this->recursiveOverride ?? $this->record['Value']]],
            'read-resource' => $this->inspectResource($role, $args['pin']),
            'delete-resource' => $this->deleteResource($role, $args['pin']),
            default => throw new \LogicException('Unexpected fixture port.'),
        };
    }

    private function dispatchCreate(string $role, array $args): array
    {
        $intent = $this->journal['round']['roles'][$role]['intent'];
        self::assertTrue(Json::same($intent, array_intersect_key($args, $intent)));
        self::assertFalse(isset($this->pins[$role]));
        self::assertSame(0, $this->stops);
        if ($role === 'candidate') {
            self::assertSame('native', $intent['kind']);
            self::assertSame('agent_readiness_v1', $args['workflow']);
            self::assertTrue(Json::same($this->pins['source'], $args['source']));
            self::assertSame(str_repeat($this->episodeDigit, 64), $args['episode_key']);
            self::assertCount(3, $this->journal['round']['windows']['source']);
            self::assertSame(hash('sha256', Json::encode($this->journal['round']['windows']['source'])), $args['episode_sha256']);
        }
        $number = array_search($role, self::ROLES, true) + 1;
        $this->creates[] = $role;
        $this->present[$role] = true;
        $this->pins[$role] = ['external_id' => (string) (51000 + $number), 'account_id' => $intent['account_id'],
            'created_at' => $this->now, 'request_id' => $intent['request_id'], 'label' => $intent['label'], 'ipv4' => self::IP[$role],
            'installation_id' => $role === 'control' ? null : str_repeat((string) $number, 32)];
        if ($role === $this->loseCreate) throw new \RuntimeException('fixture private create acknowledgement');
        return [];
    }

    private function observe(string $role, array $args): array
    {
        self::assertEquals($this->pins[$role], $args['pin']);
        $signal = $role === 'source' ? $this->sourceSignal : $this->candidateSignal;
        if ($signal === 'missing') return [];
        $at = ($role === 'source' ? $this->freezeSourceProbeAt : null) ?? $this->now;
        if ($role === 'source' && $at !== $this->lastSourceRound) {
            $this->sourceFailures = $signal === 'failed' ? $this->sourceFailures + 1 : 0;
            $this->lastSourceRound = $at;
        }
        $targetRows = [];
        foreach (self::TARGETS as $index => $ip) {
            if ($signal === 'partial' && $index === 3) continue;
            $targetRows[] = ['id' => substr(hash('sha256', $ip), 0, 16), 'source_ip' => $this->pins[$role]['ipv4'],
                'status' => $index === 0 && $signal === 'healthy' ? 'reachable'
                    : ($index === 0 && $signal === 'error' ? 'error' : 'unreachable')];
        }
        return ['pin' => $this->pins[$role], 'binary_sha256' => $this->scope()['binary_sha256'], 'session' => str_repeat('c', 32),
            'agent_at' => ($role === 'candidate' ? $this->freezeCandidateAgentAt : null) ?? $this->now,
            'probe' => ['schema' => 1, 'revision' => $args['probe_revision'], 'state' => 'ready', 'checked_at' => $at, 'targets' => $targetRows],
            'native' => $role === 'source' ? ['checked_at' => $at, 'state' => $signal, 'context' => str_repeat('f', 64),
                'episode_key' => $this->nativeThreshold && $this->sourceFailures >= 3 ? str_repeat($this->episodeDigit, 64) : null,
                'failures' => $this->sourceFailures, 'threshold_reached' => $this->nativeThreshold && $this->sourceFailures >= 3] : null];
    }

    private function dispatchStop(): array
    {
        self::assertSame('drain', $this->journal['round']['phase']);
        self::assertTrue($this->journal['round']['stop_sent']);
        self::assertSame(1, ++$this->stops);
        if ($this->loseStop) throw new \RuntimeException('fixture private stop acknowledgement');
        return [];
    }

    private function readRecord(array $args): array
    {
        self::assertSame(Usdo1ExistingA::NAME, $args['name']);
        self::assertSame(self::ORIGINAL['RecordId'], $args['record_id']);
        return [$this->record];
    }

    private function writeRecord(array $wire): array
    {
        $pending = $this->journal['dns']['pending'];
        self::assertTrue(Json::same($pending['wire'], $wire));
        self::assertTrue(Json::same($pending['before'], $this->record));
        $step = $pending['step'];
        $this->writes[] = $step;
        self::assertSame(self::ORIGINAL['RecordId'], $wire['parameters']['RecordId']);
        self::assertContains($wire['action'], ['UpdateDomainRecord', 'UpdateDomainRecordRemark']);
        if ($step === 'native-cutover') self::assertCount(3, $this->journal['round']['windows']['candidate']);
        if (str_starts_with($step, 'restore-') || str_starts_with($step, 'unclaim-')) self::assertSame(1, $this->stops);
        $applied = $step !== $this->loseWrite || $this->applyLostWrite;
        if ($applied) {
            $this->record = $pending['after'];
            if (str_starts_with($step, 'restore-')) $this->addressRestoredAt = $this->now;
        }
        $this->dnsOutcomes[hash('sha256', Json::encode($pending))] = $applied;
        if ($step === $this->loseWrite) throw new \RuntimeException('fixture private DNS acknowledgement');
        return ['RequestId' => 'fixture-ack', 'RecordId' => self::ORIGINAL['RecordId']];
    }

    private function inspectResource(string $role, array $pin): array
    {
        self::assertEquals($this->pins[$role], $pin);
        if ($role === $this->badReadRole) return ['pin' => $pin, 'status' => 'unknown'];
        if ($this->present[$role]) return ['pin' => $pin, 'status' => 'present'];
        $this->absentReads[$role][] = $this->now;
        return ['pin' => $pin, 'status' => 'absent'];
    }

    private function deleteResource(string $role, array $pin): array
    {
        self::assertEquals($this->pins[$role], $pin);
        self::assertNotContains($pin['external_id'], $this->scope()['protected_ids']);
        self::assertTrue(Json::same(self::ORIGINAL, $this->record));
        self::assertTrue($this->journal['round']['roles'][$role]['delete_sent']);
        self::assertSame(1, $this->stops);
        if ($this->addressRestoredAt !== null) self::assertGreaterThanOrEqual($this->addressRestoredAt + 600, $this->now);
        self::assertNotContains($role, $this->deletes);
        $this->deletes[] = $role;
        $this->present[$role] = false;
        if ($role === $this->loseDelete) throw new \RuntimeException('fixture private delete acknowledgement');
        return [];
    }

    public function testSingleCandidateCutoverFullRollbackAndCleanup(): void
    {
        $this->reach('qualify-source');
        $this->tick(); $this->tick();
        self::assertSame(['control', 'source'], $this->creates);
        $this->reach('qualify-candidate');
        $this->candidateSignal = 'failed';
        $this->tick();
        self::assertSame(['claim', 'publish-source'], $this->writes);
        $this->candidateSignal = 'healthy';
        $this->tick(); $this->tick();
        self::assertSame(self::IP['source'], $this->record['Value']);
        $this->reach('confirm-cutover');
        self::assertSame(self::IP['candidate'], $this->record['Value']);
        self::assertSame(600, $this->record['TTL']);
        $this->recursiveOverride = self::IP['source'];
        self::assertFalse($this->tick()['cutover_verified']);
        $this->recursiveOverride = null;
        $this->reach('cleanup');
        self::assertTrue(Json::same(self::ORIGINAL, $this->record));
        $this->tick(599);
        self::assertSame([], $this->deletes);
        $final = $this->reach('closed');
        self::assertTrue($final['passed']);
        self::assertSame(self::ROLES, $this->creates);
        self::assertSame(['claim', 'publish-source', 'native-cutover', 'restore-candidate', 'unclaim-restored'], $this->writes);
        self::assertSame(array_reverse(self::ROLES), $this->deletes);
        foreach ($this->absentReads as $reads) self::assertGreaterThanOrEqual(10, end($reads) - $reads[0]);
        self::assertFalse(config('machine_failover.runner_enabled'));
        self::assertFalse(config('machine_failover.allow_rotation'));
    }

    public function testIncompleteFailedRoundsAndRepeatedReportsCannotAdvance(): void
    {
        $this->reach('qualify-source');
        $this->freezeSourceProbeAt = $this->now;
        $this->tick(); $this->tick(); $this->tick();
        self::assertCount(1, $this->journal['round']['windows']['source']);
        $this->freezeSourceProbeAt = null;
        foreach (['partial', 'error', 'missing'] as $signal) {
            $this->sourceSignal = $signal;
            $this->tick();
            self::assertSame([], $this->journal['round']['windows']['source']);
        }
        $this->sourceSignal = 'failed';
        $this->nativeThreshold = false;
        for ($i = 0; $i < 4; $i++) $this->tick();
        self::assertSame(['control', 'source'], $this->creates);
        $this->nativeThreshold = true;
        $this->reach('qualify-candidate');
        $this->freezeCandidateAgentAt = $this->now;
        $this->tick(); $this->tick(); $this->tick();
        self::assertCount(1, $this->journal['round']['windows']['candidate']);
        self::assertNotContains('native-cutover', $this->writes);
        $this->sourceSignal = 'healthy';
        self::assertSame('usdo1_round_source_recovered', $this->tick()['first_error']);
        $this->reach('cleanup');
        $this->tick(600);
        self::assertFalse($this->reach('closed')['passed']);
    }

    #[DataProvider('abortCases')]
    public function testDeadlineOrStopFencesTheRoundAndRollsBackBeforeDelete(bool $stop, string $phase): void
    {
        $this->reach($phase);
        $this->stopDrained = false;
        $state = $this->tick($stop ? 5 : self::START + 5100 - $this->now, $stop);
        self::assertSame('drain', $state['phase']);
        self::assertSame($stop ? 'usdo1_round_stop_requested' : 'usdo1_round_deadline', $state['first_error']);
        $this->tick();
        self::assertSame([], $this->deletes);
        self::assertSame(1, $this->stops);
        $this->stopDrained = true;
        $this->reach('cleanup');
        self::assertTrue(Json::same(self::ORIGINAL, $this->record));
        $this->tick(600);
        self::assertFalse($this->reach('closed')['passed']);
        self::assertNotContains('native-cutover', $this->writes);
        self::assertSame($phase === 'candidate' ? ['control', 'source'] : self::ROLES, $this->creates);
    }

    public static function abortCases(): array { return ['timeout before dispatch' => [false, 'candidate'], 'stop after create' => [true, 'qualify-candidate']]; }

    public function testLostCreateAndStopReceiptsOnlyReconcileOriginalIntent(): void
    {
        $this->reach('candidate');
        $this->loseCreate = $this->pendingRole = 'candidate';
        $this->loseStop = true;
        self::assertSame('usdo1_round_port_failed', $this->tick()['first_error']);
        $this->reach('cleanup');
        $this->tick(600);
        $this->tick(10);
        self::assertSame([], $this->deletes);
        self::assertSame(self::ROLES, $this->creates);
        $this->pendingRole = null;
        $final = $this->reach('closed');
        self::assertFalse($final['passed']);
        self::assertSame('usdo1_round_port_failed', $final['first_error']);
        self::assertSame(array_reverse(self::ROLES), $this->deletes);
        self::assertSame(1, $this->stops);
    }

    #[DataProvider('dnsLossCases')]
    public function testUncertainDnsIsNotReplayedAndBlocksDeleteUntilRollback(string $step, bool $applied): void
    {
        $this->loseWrite = $step;
        $this->applyLostWrite = $applied;
        $this->dnsSettled = false;
        $this->reach('rollback');
        $this->tick(); $this->tick();
        self::assertSame('rollback', $this->journal['round']['phase']);
        self::assertSame([], $this->deletes);
        self::assertSame($step, $this->journal['dns']['pending']['step']);
        self::assertSame(1, array_count_values($this->writes)[$step]);
        $this->dnsSettled = true;
        $this->reach('cleanup');
        self::assertTrue(Json::same(self::ORIGINAL, $this->record));
        $this->tick(600);
        self::assertFalse($this->reach('closed')['passed']);
        self::assertSame(1, array_count_values($this->writes)[$step]);
    }

    public static function dnsLossCases(): array
    { return ['cutover applied' => ['native-cutover', true], 'cutover not applied' => ['native-cutover', false], 'restore address applied' => ['restore-candidate', true]]; }

    public function testUnverifiedRollbackAndExternalReadbackNeverAllowDelete(): void
    {
        $this->reach('rollback');
        $this->badRollbackPin = true;
        $this->tick(60);
        self::assertSame(self::IP['candidate'], $this->record['Value']);
        $this->badRollbackPin = false;
        $this->reach('cleanup');
        $this->recursiveOverride = self::IP['candidate'];
        $this->tick(600);
        self::assertSame([], $this->deletes);
        $this->recursiveOverride = null;
        $this->record['Remark'] = 'external-change';
        $this->tick();
        self::assertSame([], $this->deletes);
        self::assertSame('external-change', $this->record['Remark']);
    }

    public function testLostDeleteAndInterruptedAbsenceReadKeepControlUntilExactConfirmation(): void
    {
        $this->reach('cleanup');
        $this->loseDelete = 'candidate';
        $this->tick(600);
        self::assertSame(['candidate'], $this->deletes);
        $this->tick();
        $this->badReadRole = 'candidate';
        $this->tick(10);
        $this->badReadRole = null;
        $this->tick(10);
        $this->tick(9);
        self::assertFalse($this->journal['round']['roles']['candidate']['confirmed']);
        self::assertSame(['candidate'], $this->deletes);
        $this->tick(1);
        self::assertTrue($this->journal['round']['roles']['candidate']['confirmed']);
        $this->reach('closed');
        self::assertSame(array_reverse(self::ROLES), $this->deletes);
    }

    public function testChangedFailureEpisodeCannotUseAlreadyCreatedCandidateToCutOver(): void
    {
        $this->reach('qualify-candidate');
        $this->tick(); $this->tick();
        $this->episodeDigit = 'e';
        self::assertSame('usdo1_round_source_episode_changed', $this->tick()['first_error']);
        self::assertSame(self::ROLES, $this->creates);
        self::assertNotContains('native-cutover', $this->writes);
        $this->reach('cleanup');
        $this->tick(600);
        self::assertFalse($this->reach('closed')['passed']);
    }

    public function testProtectedInstanceCannotBeClaimedByCandidateReceiptOrDeleted(): void
    {
        $this->reach('candidate');
        $this->tick();
        $this->pins['candidate']['external_id'] = $this->scope()['rollback']['external_id'];
        self::assertSame('usdo1_round_provision_pin_invalid', $this->tick()['first_error']);
        $this->reach('cleanup');
        $this->tick(600);
        self::assertSame([], $this->deletes);
        self::assertTrue(Json::same(self::ORIGINAL, $this->record));
        self::assertSame('usdo1_round_provision_pin_invalid', $this->journal['round']['recovery_error']);
    }

    public function testDefinitiveNonApplicationOfRollbackCannotPermitAnotherWriteOrCleanup(): void
    {
        $this->reach('rollback');
        $this->loseWrite = 'restore-candidate';
        $this->applyLostWrite = false;
        $this->tick(); $this->tick(600); $this->tick(600);
        self::assertSame('rollback', $this->journal['round']['phase']);
        self::assertSame(1, array_count_values($this->writes)['restore-candidate']);
        self::assertSame(self::IP['candidate'], $this->record['Value']);
        self::assertSame([], $this->deletes);
    }

    public function testStopWithoutReadySourceDoesNotWriteDns(): void
    {
        $this->notReadyRole = 'source';
        $this->reach('source');
        $this->tick(); $this->tick();
        $this->tick(5, true);
        $this->reach('closed');
        self::assertSame([], $this->writes);
        self::assertTrue(Json::same(self::ORIGINAL, $this->record));
        self::assertSame(['source', 'control'], $this->deletes);
    }
}

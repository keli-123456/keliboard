<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence as Evidence;
use Illuminate\Support\Carbon;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HandoverEvidenceFixture as Signer;
use Tests\Support\HandoverSingleADnsFixture as Dns;
use Tests\Support\HandoverSingleAGatewayFixture as Gateway;

final class MachineFailoverSingleARehearsalTest extends TestCase
{
    private const NOW = 1700000000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestampUTC(self::NOW));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_one_record_switch_and_rollback_require_separate_writers_and_closures(): void
    {
        [$gateway, $dns, $legacy] = $this->setupGateway();
        $neighbor = $dns->inventory()[1];
        $gateway->assertAccess($legacy, self::NOW);
        $this->denied('rehearsal_write_out_of_scope', fn () => $gateway->write($legacy, 'wrong-mode', Dns::switched(), self::NOW));
        $this->denied('rehearsal_legacy_not_fenced', fn () => $gateway->stopScope());

        $forward = $this->takeOver($gateway);
        $this->assertGreaterThan($legacy['epoch'], $forward['epoch']);
        $gateway->assertAccess($forward, self::NOW);
        $this->denied('rehearsal_writer_fenced', fn () => $gateway->write($legacy, 'stale-task', Dns::switched(), self::NOW));
        $this->denied('rehearsal_writer_fenced', fn () => $gateway->assertAccess(['token' => $legacy['token']] + $forward, self::NOW));
        $this->assertTrue($gateway->write($forward, 'switch', Dns::switched(), self::NOW));
        $this->assertFalse($gateway->write($forward, 'switch', Dns::switched(), self::NOW));
        $this->denied('rehearsal_write_already_dispatched', fn () => $gateway->write($forward, 'retry', Dns::switched(), self::NOW));
        $this->assertSame(Dns::switched(), $dns->record());
        $this->assertSame(Dns::OLD_IP, $dns->resolvedIp());
        $dns->propagate();
        $this->assertSame(Dns::NEW_IP, $dns->resolvedIp());
        $this->assertTrue($dns->healthy($dns->resolvedIp()));

        $gateway->stopWrites(self::NOW);
        $this->denied('rehearsal_writer_fenced', fn () => $gateway->assertAccess($forward, self::NOW));
        $this->denied('rehearsal_rollback_not_ready', fn () => $gateway->allowRollback(self::NOW));
        $gateway->close($this->closeProof($gateway), self::NOW);
        $this->assertNull($gateway->status()['token_sha256']);
        $this->denied('rehearsal_handback_not_ready', fn () => $gateway->handBack(self::NOW));
        $this->assertSame(1, $dns->writes());

        $rollback = $gateway->allowRollback(self::NOW);
        $this->assertGreaterThan($forward['epoch'], $rollback['epoch']);
        $this->denied('rehearsal_writer_fenced', fn () => $gateway->write($forward, 'undo', Dns::ORIGINAL, self::NOW));
        $this->denied('rehearsal_write_out_of_scope', fn () => $gateway->write($rollback, 'undo', Dns::switched(), self::NOW));
        $this->assertTrue($gateway->write($rollback, 'undo', Dns::ORIGINAL, self::NOW));
        $gateway->stopWrites(self::NOW);
        $gateway->close($this->closeProof($gateway), self::NOW);
        $this->assertSame(Dns::ORIGINAL, $dns->record());
        $this->assertSame(Dns::NEW_IP, $dns->resolvedIp());
        $this->denied('rehearsal_dns_not_propagated', fn () => $gateway->handBack(self::NOW));
        $dns->propagate();
        $this->assertSame(Dns::OLD_IP, $dns->resolvedIp());
        $restored = $gateway->handBack(self::NOW);
        $gateway->assertAccess($restored, self::NOW);
        $this->assertSame($legacy['writer'], $restored['writer']);
        $this->assertGreaterThan($rollback['epoch'], $restored['epoch']);
        $this->denied('rehearsal_writer_fenced', fn () => $gateway->assertAccess($legacy, self::NOW));
        $this->denied('rehearsal_writer_fenced', fn () => $gateway->write($rollback, 'undo', Dns::ORIGINAL, self::NOW));
        $this->assertSame(2, $dns->dispatches());
        $this->assertSame(2, $dns->writes());
        $this->assertSame($neighbor, $dns->inventory()[1]);
        $this->assertSame(['forward', 'rollback'], array_keys($gateway->status()['closures']));
        $this->assertStringNotContainsString($forward['token'], json_encode($gateway->status(), JSON_THROW_ON_ERROR));
    }

    public static function oldWork(): array { return [['queued'], ['in_flight'], ['unresolved']]; }

    #[DataProvider('oldWork')]
    public function test_a_signature_cannot_override_observed_old_work(string $field): void
    {
        [$gateway, $dns] = $this->setupGateway([$field => 1]);
        $gateway->stopWrites(self::NOW);
        $proof = $this->stopProof($gateway);
        $this->denied('rehearsal_stop_not_observed', fn () => $gateway->takeOver($proof, self::NOW));
        $this->assertSame('legacy_stopped', $gateway->status()['phase']);
        $this->assertSame(0, $dns->dispatches());
    }

    public function test_stop_proof_must_be_fresh_signed_bound_to_this_run_and_match_observation(): void
    {
        [$gateway, $dns] = $this->setupGateway();
        [$other] = $this->setupGateway();
        $gateway->stopWrites(self::NOW);
        $other->stopWrites(self::NOW);
        $proof = $this->stopProof($gateway);
        $this->denied('handover_evidence_scope_changed', fn () => $gateway->takeOver($this->stopProof($other), self::NOW));
        $this->denied('handover_evidence_expired', fn () => $gateway->takeOver($proof, self::NOW + 60));
        $oldProof = $this->stopProof($gateway, ['observed_at' => self::NOW - 1]);
        $this->denied('rehearsal_stop_not_observed', fn () => $gateway->takeOver($oldProof, self::NOW));
        $wrongBaseline = $this->stopProof($gateway, ['baseline_sha256' => str_repeat('a', 64)]);
        $this->denied('rehearsal_stop_not_observed', fn () => $gateway->takeOver($wrongBaseline, self::NOW));
        $wrongFence = $this->stopProof($gateway, ['fence_id' => 'other-fence']);
        $this->denied('rehearsal_stop_not_observed', fn () => $gateway->takeOver($wrongFence, self::NOW));
        $busyProof = $this->stopProof($gateway, ['unresolved' => 1]);
        $this->denied('handover_stop_unconfirmed', fn () => $gateway->takeOver($busyProof, self::NOW));
        $tampered = $proof;
        $tampered['body']['valid_until']++;
        $this->denied('handover_evidence_invalid', fn () => $gateway->takeOver($tampered, self::NOW));
        $this->assertNull($gateway->status()['token_sha256']);
        $gateway->takeOver($proof, self::NOW);
        $this->denied('rehearsal_legacy_not_fenced', fn () => $gateway->takeOver($proof, self::NOW));
        $this->assertSame(0, $dns->dispatches());
    }

    public function test_closure_reviewer_key_cannot_authorize_old_writer_stop(): void
    {
        $keys = Signer::publicKeys();
        $gateway = new Gateway(new PDO('sqlite::memory:'), new Dns(),
            new Evidence([Evidence::STOP => [array_key_first($keys[Evidence::STOP]) => array_values($keys[Evidence::CLOSE])[0]]]));
        $gateway->initialize(self::NOW);
        $gateway->stopWrites(self::NOW);
        $proof = $this->stopProof($gateway);
        $this->denied('handover_evidence_invalid', fn () => $gateway->takeOver($proof, self::NOW));
        $this->assertNull($gateway->status()['token_sha256']);
    }

    public function test_scope_target_and_health_are_checked_before_dispatch(): void
    {
        [$gateway, $dns] = $this->setupGateway();
        $forward = $this->takeOver($gateway);
        foreach ([$dns->inventory()[1], ['type' => 'AAAA'] + Dns::switched(), ['ttl' => 300] + Dns::switched(),
            ['line' => 'alternate'] + Dns::switched()] as $record) {
            $this->denied('rehearsal_write_out_of_scope', fn () => $gateway->write($forward, 'switch', $record, self::NOW));
        }
        $dns->setHealthy(Dns::NEW_IP, false);
        $this->denied('rehearsal_endpoint_unhealthy', fn () => $gateway->write($forward, 'switch', Dns::switched(), self::NOW));
        $dns->setHealthy(Dns::NEW_IP, true);
        $dns->drift(['marker' => 'unexpected-owner']);
        $this->denied('rehearsal_dns_drift', fn () => $gateway->write($forward, 'switch', Dns::switched(), self::NOW));
        $this->assertSame([], $gateway->status()['operations']);
        $this->assertSame(0, $dns->dispatches());
    }

    public function test_pending_write_blocks_closure_even_if_original_record_is_still_visible(): void
    {
        [$gateway, $dns, $legacy] = $this->setupGateway();
        $forward = $this->takeOver($gateway);
        $dns->nextReply('pending');
        $this->assertTrue($gateway->write($forward, 'switch', Dns::switched(), self::NOW));
        $this->assertFalse($gateway->write($forward, 'switch', Dns::switched(), self::NOW));
        $gateway->stopWrites(self::NOW);
        $this->assertSame(Dns::ORIGINAL, $dns->record());
        $this->denied('rehearsal_outcome_unknown', fn () => $gateway->reviewClosure());
        $this->denied('rehearsal_rollback_not_ready', fn () => $gateway->allowRollback(self::NOW));
        $this->denied('rehearsal_handback_not_ready', fn () => $gateway->handBack(self::NOW));
        $this->denied('rehearsal_writer_fenced', fn () => $gateway->write($legacy, 'again', Dns::switched(), self::NOW));
        $this->assertSame(1, $dns->dispatches());
        $this->assertSame([], $gateway->status()['closures']);

        $dns->finish($this->operationId($gateway), false);
        $gateway->close($this->closeProof($gateway), self::NOW);
        $this->assertSame('confirmed_not_applied', $gateway->status()['closures']['forward']['outcomes'][0]['result']);
        $this->denied('rehearsal_rollback_not_ready', fn () => $gateway->allowRollback(self::NOW));
        $gateway->assertAccess($gateway->handBack(self::NOW), self::NOW);
        $this->assertSame(0, $dns->writes());
        $this->assertSame(1, $dns->dispatches());
    }

    public function test_lost_ack_is_closed_by_exact_final_observation_not_by_resending(): void
    {
        [$gateway, $dns] = $this->setupGateway();
        $forward = $this->takeOver($gateway);
        $dns->nextReply('lost_ack');
        $gateway->write($forward, 'switch', Dns::switched(), self::NOW);
        $gateway->stopWrites(self::NOW);
        $op = $this->operationId($gateway);
        $this->assertNull($gateway->status()['operations']['forward']['receipt']);
        $this->assertSame(Dns::switched(), $dns->record());
        $gateway->close($this->closeProof($gateway), self::NOW);
        $this->assertSame('confirmed_applied', $gateway->status()['closures']['forward']['outcomes'][0]['result']);
        $closed = $gateway->status();
        $this->assertFalse($gateway->recordReceipt($op, self::NOW + 1));
        $this->assertSame($closed, $gateway->status());
        $this->assertSame(1, $dns->dispatches());
        $this->assertSame(1, $dns->writes());
    }

    public function test_closure_rejects_unobserved_outcomes_incomplete_proofs_and_stale_receipts(): void
    {
        [$gateway, $dns] = $this->setupGateway();
        $forward = $this->takeOver($gateway);
        $dns->nextReply('lost_ack');
        $gateway->write($forward, 'switch', Dns::switched(), self::NOW);
        $this->denied('rehearsal_close_requires_stop', fn () => $gateway->reviewClosure());
        $gateway->stopWrites(self::NOW);
        $review = $gateway->reviewClosure();
        $this->denied('handover_closure_incomplete', fn () => $gateway->close($this->closeProof($gateway, ['outcomes' => []]), self::NOW));
        $unobserved = ['result' => 'confirmed_not_applied'] + $review['outcomes'][0];
        $this->denied('rehearsal_closure_not_observed', fn () => $gateway->close($this->closeProof($gateway, ['outcomes' => [$unobserved]]), self::NOW));
        $oldProof = $this->closeProof($gateway);
        $this->assertTrue($gateway->recordReceipt($this->operationId($gateway), self::NOW));
        $this->denied('handover_evidence_scope_changed', fn () => $gateway->close($oldProof, self::NOW));
        $this->assertEmpty($gateway->status()['closures']);
        $gateway->close($this->closeProof($gateway), self::NOW);
        $this->assertSame(1, $dns->dispatches());
    }

    public function test_rollback_is_refused_if_original_endpoint_fails_or_record_identity_changes(): void
    {
        [$gateway, $dns] = $this->setupGateway();
        $forward = $this->takeOver($gateway);
        $gateway->write($forward, 'switch', Dns::switched(), self::NOW);
        $gateway->stopWrites(self::NOW);
        $proof = $this->closeProof($gateway);
        $dns->drift(['ttl' => 120]);
        $this->denied('rehearsal_outcome_unknown', fn () => $gateway->close($proof, self::NOW));
        $dns->drift(['ttl' => Dns::ORIGINAL['ttl']]);
        $gateway->close($this->closeProof($gateway), self::NOW);

        $dns->setHealthy(Dns::OLD_IP, false);
        $this->denied('rehearsal_endpoint_unhealthy', fn () => $gateway->allowRollback(self::NOW));
        $dns->setHealthy(Dns::OLD_IP, true);
        $rollback = $gateway->allowRollback(self::NOW);
        $dns->drift(['id' => 'recreated-record']);
        $this->denied('rehearsal_dns_drift', fn () => $gateway->write($rollback, 'undo', Dns::ORIGINAL, self::NOW));
        $this->assertSame(1, $dns->dispatches());
        $this->assertSame(1, $dns->writes());
    }

    public function test_pending_rollback_neither_reissues_the_write_nor_releases_the_writer(): void
    {
        [$gateway, $dns, $legacy] = $this->setupGateway();
        $forward = $this->takeOver($gateway);
        $gateway->write($forward, 'switch', Dns::switched(), self::NOW);
        $gateway->stopWrites(self::NOW);
        $gateway->close($this->closeProof($gateway), self::NOW);
        $rollback = $gateway->allowRollback(self::NOW);
        $dns->nextReply('pending');
        $this->assertTrue($gateway->write($rollback, 'undo', Dns::ORIGINAL, self::NOW));
        $this->assertFalse($gateway->write($rollback, 'undo', Dns::ORIGINAL, self::NOW));
        $gateway->stopWrites(self::NOW);
        $this->denied('rehearsal_outcome_unknown', fn () => $gateway->reviewClosure());
        $this->denied('rehearsal_handback_not_ready', fn () => $gateway->handBack(self::NOW));
        $this->denied('rehearsal_writer_fenced', fn () => $gateway->assertAccess($legacy, self::NOW));
        $this->denied('rehearsal_writer_fenced', fn () => $gateway->assertAccess($rollback, self::NOW));
        $this->assertSame(2, $dns->dispatches());
        $this->assertSame(1, $dns->writes());
        $this->assertSame(Dns::switched(), $dns->record());
    }

    public function test_a_new_gateway_connection_cannot_reuse_old_epochs_or_resume_on_expiry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'keli-gate-');
        $this->assertIsString($path);
        try {
            $dns = new Dns();
            $evidence = new Evidence(Signer::publicKeys());
            $first = new Gateway(new PDO('sqlite:' . $path), $dns, $evidence);
            $legacy = $first->initialize(self::NOW);
            $forward = $this->takeOver($first);
            $first->write($forward, 'switch', Dns::switched(), self::NOW);
            unset($first);

            $reopened = new Gateway(new PDO('sqlite:' . $path), $dns, $evidence);
            $expired = $reopened->status()['deadline'];
            $this->assertSame($forward['epoch'], $reopened->status()['epoch']);
            $this->assertFalse($reopened->write($forward, 'switch', Dns::switched(), self::NOW));
            $this->denied('rehearsal_writer_fenced', fn () => $reopened->assertAccess($legacy, $expired));
            $this->denied('rehearsal_window_expired', fn () => $reopened->write($forward, 'switch', Dns::switched(), $expired));
            $reopened->stopWrites($expired);
            $this->assertGreaterThan($forward['epoch'], $reopened->status()['epoch']);
            Carbon::setTestNow(Carbon::createFromTimestampUTC($expired));
            $reopened->close($this->closeProof($reopened), $expired);
            $this->assertNull($reopened->status()['token_sha256']);
            $this->denied('rehearsal_window_expired', fn () => $reopened->allowRollback($expired));
            $this->assertSame(1, $dns->dispatches());
        } finally {
            unset($first, $reopened);
            unlink($path);
        }
    }

    public function test_a_stop_committed_before_the_dispatch_reservation_prevents_the_write(): void
    {
        $db = new class('sqlite::memory:') extends PDO {
            public ?\Closure $beforeUpdate = null;

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if ($this->beforeUpdate !== null && str_starts_with($query, 'UPDATE rehearsal_gateway')) {
                    $stop = $this->beforeUpdate;
                    $this->beforeUpdate = null;
                    $stop();
                }
                return parent::prepare($query, $options);
            }
        };
        $dns = new Dns();
        $evidence = new Evidence(Signer::publicKeys());
        $writer = new Gateway($db, $dns, $evidence);
        $operator = new Gateway($db, $dns, $evidence);
        $writer->initialize(self::NOW);
        $grant = $this->takeOver($writer);
        $db->beforeUpdate = fn () => $operator->stopWrites(self::NOW);

        $this->denied('rehearsal_state_changed', fn () => $writer->write($grant, 'switch', Dns::switched(), self::NOW));
        $this->assertSame('forward_stopped', $operator->status()['phase']);
        $this->assertSame([], $operator->status()['operations']);
        $this->assertSame(0, $dns->dispatches());
        $operator->close($this->closeProof($operator), self::NOW);
        $this->assertNull($operator->status()['token_sha256']);
    }

    private function setupGateway(array $legacyWork = []): array
    {
        $dns = new Dns();
        $gateway = new Gateway(new PDO('sqlite::memory:'), $dns, new Evidence(Signer::publicKeys()));
        return [$gateway, $dns, $gateway->initialize(self::NOW, $legacyWork)];
    }

    private function takeOver(Gateway $gateway): array
    {
        $gateway->stopWrites(self::NOW);
        return $gateway->takeOver($this->stopProof($gateway), self::NOW);
    }

    private function stopProof(Gateway $gateway, array $overrides = []): array
    {
        $scope = $gateway->stopScope();
        return Signer::stop($scope, Gateway::OPERATOR, $overrides + ['fence_id' => $scope['fence_id'], 'baseline_sha256' => $scope['baseline_sha256']]);
    }

    private function closeProof(Gateway $gateway, array $overrides = []): array
    {
        $review = $gateway->reviewClosure();
        return Signer::close($review['scope'], $overrides + ['outcomes' => $review['outcomes']]);
    }

    private function operationId(Gateway $gateway): string { return $gateway->status()['operations']['forward']['intent']['operation_id']; }

    private function denied(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected ' . $code);
        } catch (CloudException $e) {
            $this->assertSame($code, $e->getMessage());
        }
    }
}

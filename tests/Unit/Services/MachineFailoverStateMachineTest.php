<?php
namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverSimulationDriver as Driver;
use App\Services\ServerMachine\Cloud\MachineFailoverStateMachine as Machine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MachineFailoverStateMachineTest extends TestCase
{
    private function context(): array
    {
        return ['context_hash' => str_repeat('a', 64), 'source_installation_id' => str_repeat('b', 32), 'source_ip' => '192.0.2.1',
            'candidate_installation_id' => str_repeat('c', 32), 'candidate_ip' => '198.51.100.1', 'candidate_id' => 'sim-' . str_repeat('d', 24),
            'binary_sha256' => str_repeat('e', 64), 'configuration_sha256' => str_repeat('f', 64), 'echo_sha256' => str_repeat('9', 64),
            'dns_hostname' => 'synthetic.example.test', 'dns_credential_revision' => 1, 'failure_threshold' => 2, 'stale_after_seconds' => 30];
    }
    public function test_only_distinct_fresh_same_source_samples_can_trigger_a_replacement(): void
    {
        $machine = new Machine(); $driver = new Driver(); $context = $this->context(); $state = $machine->initial();
        $state = $machine->source($state, $driver->source($context, $state, 'replace', 0, 100), $context, 100);
        $fact = $driver->source($context, $state, 'replace', 1, 105);
        $state = $machine->source($state, $fact, $context, 105); $this->assertSame(1, $state['failures']);
        $this->assertSame($state, $machine->source($state, $fact, $context, 110));
        $next = $machine->source($state, $driver->source($context, $state, 'replace', 2, 110), $context, 110);
        $this->assertSame('queued', $next['phase']); $this->assertFalse($next['create_dispatched']);
        $expired = $machine->source($state, $driver->source($context, $state, 'replace', 2, 200), $context, 200);
        $this->assertSame('blocked', $expired['phase']); $this->assertSame('healthy_baseline_required', $expired['reason']);
    }
    public static function badSource(): array { return [['origin', 'real'], ['at', 999], ['installation_id', 'wrong'], ['ip', '192.0.2.2'], ['context_hash', 'wrong'], ['targets', [['unreachable']]]]; }
    #[DataProvider('badSource')]
    public function test_invalid_source_is_never_counted_as_blocking(string $key, mixed $value): void
    {
        $machine = new Machine(); $context = $this->context(); $state = $machine->initial();
        $fact = (new Driver())->source($context, $state, 'replace', 2, 100); $fact[$key] = $value;
        $next = $machine->source($state, $fact, $context, 100);
        $this->assertSame('blocked', $next['phase']); $this->assertSame(0, $next['failures']);
    }
    public static function badReceipts(): array
    {
        return [['create', 'candidate_id'], ['initialize', 'installation_id'], ['reports', 'configuration_sha256'], ['ipv4', 'context_hash'],
            ['forwarding', 'cleanup_complete'], ['forwarding', 'udp'], ['prepare_dns', 'owned'], ['prepare_dns', 'current_ip'],
            ['switch_dns', 'operation_id'], ['verify_dns', 'current_ip'], ['verify_dns', 'credential_revision'], ['verify_dns', 'at']];
    }
    #[DataProvider('badReceipts')]
    public function test_success_label_does_not_override_mismatched_evidence(string $stage, string $bad): void
    {
        $machine = new Machine(); $driver = new Driver(); $context = $this->context();
        $state = $machine->reserve(array_replace($machine->initial(), ['phase' => 'queued']), true); $reached = false;
        for ($i = 0; $i < 15 && !$machine->terminal($state); $i++) {
            $kind = Machine::ACTIONS[$state['phase']]; $state = $machine->dispatched($state, $kind);
            $fact = $driver->execute($kind, $context, $state, 'replace', 'operation-' . $i, 100 + $i);
            if ($kind === $stage) {
                $reached = true;
                if ($bad === 'candidate_id') $fact['candidate']['id'] = 'sim-' . str_repeat('0', 24);
                elseif ($bad === 'udp') $fact['udp']['duplicates'] = 1;
                else $fact[$bad] = match ($bad) { 'cleanup_complete', 'owned' => false, 'at' => 0, 'credential_revision' => 2, default => 'wrong' };
            }
            $state = $machine->receipt($state, $kind, $fact, $context, 'operation-' . $i, 100 + $i);
        }
        $this->assertTrue($reached); $this->assertContains($state['phase'], ['failed', 'needs_review']); $this->assertNotSame('completed', $state['phase']);
    }
    public function test_source_recovery_during_ambiguous_creation_stays_unresolved(): void
    {
        $machine = new Machine(); $context = $this->context();
        $state = array_replace($machine->initial(), ['phase' => 'create_unknown', 'create_dispatched' => true]);
        $fact = (new Driver())->source($context, $state, 'healthy', 3, 100);
        $next = $machine->source($state, $fact, $context, 100);
        $this->assertSame('needs_review', $next['phase']); $this->assertSame('create_outcome_unknown', $next['reason']);
    }
    public function test_phase_order_and_terminal_idempotence_are_enforced(): void
    {
        $machine = new Machine(); $context = $this->context();
        $terminal = array_replace($machine->initial(), ['phase' => 'completed']);
        $this->assertSame($terminal, $machine->receipt($terminal, 'create', [], $context, 'wrong', 100));
        $this->expectException(CloudException::class);
        $machine->dispatched($machine->initial(), 'switch_dns');
    }
}

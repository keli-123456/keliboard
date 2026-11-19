<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\ExampleRehearsal\Grant;
use App\Services\ServerMachine\ExampleRehearsal\ScratchRound;
use App\Services\ServerMachine\ExampleRehearsal\Wire;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MachineFailoverExampleRoundTest extends TestCase
{
    private string $path;
    private Grant $grant;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'keli-example-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->path, 0700));
        $this->grant = Grant::parse(['id' => bin2hex(random_bytes(16)), 'admin' => 42, 'opens' => time() - 2, 'expires' => time() + 200]);
    }

    protected function tearDown(): void
    {
        if (DB::getFacadeApplication()?->bound('db')) DB::disconnect();
        gc_collect_cycles();
        $this->assertSame('keli-example-', substr(basename($this->path), 0, 13));
        foreach (glob($this->path . '/rehearsal-' . $this->grant->id . '.*') as $file) $this->assertTrue(unlink($file));
        $this->assertTrue(rmdir($this->path));
    }

    public function test_v2_precedes_dns_and_lost_receipts_are_read_back_without_reexecution(): void
    {
        $round = $this->round();
        $this->assertSame(Wire::report('ready', null, false, false), $round->handle('status'));
        $this->assertSame(['error' => 'phase_conflict'], $round->handle('dns'));
        $this->assertFileDoesNotExist($this->database());
        $v2 = $round->handle('v2');
        $this->assertSame(Wire::report('v2_passed', null, true, false), $v2);
        $this->assertSame(0, $this->rowCount('v2_machine_failover_dns_set'));
        $this->assertSame($v2, $this->round()->handle('status'));
        $this->assertSame($v2, $this->round()->handle('v2'));
        // Discard the DNS response to model a gateway timeout after the scratch transaction commits.
        $round->handle('dns');
        $done = $this->round()->handle('status');
        $this->assertSame(Wire::report('passed', null, true, true), $done);
        $this->assertSame($done, $this->round()->handle('dns'));
        $this->assertSame(1, $this->rowCount('v2_machine_failover_run'));
        $this->assertSame(1, $this->rowCount('v2_machine_failover_dns_run', true));
        $this->assertSame(8, $this->rowCount('v2_machine_failover_dns_slot', true));
        $this->assertSame(0, $this->rowCount('v2_machine_failover_execution', true));
    }

    public function test_deadline_rolls_back_dns_after_dispatching_a_synthetic_step(): void
    {
        $this->assertSame('v2_passed', $this->round()->handle('v2')['phase']);
        $ticks = 0;
        $round = $this->round(ticks: function () use (&$ticks) {
            return ++$ticks < 6 ? 0 : 12_000_000_000;
        });
        $this->assertSame(Wire::report('stopped', 'timeout', true, false), $round->handle('dns'));
        $this->assertGreaterThanOrEqual(6, $ticks);
        $this->assertSame(0, $this->rowCount('v2_machine_failover_dns_set'));
        $this->assertSame(0, $this->rowCount('v2_machine_failover_dns_run'));
        $this->assertSame(1, $this->rowCount('v2_machine_failover_run'));
        $this->assertFileDoesNotExist($this->database(true));
        $this->assertSame(Wire::report('stopped', 'timeout', true, false), $this->round()->handle('dns'));
    }

    public function test_concurrent_stop_seals_pending_stage_and_expiry_cannot_start_a_fresh_stage(): void
    {
        $this->assertSame('v2_passed', $this->round()->handle('v2')['phase']);
        $ticks = 0;
        $stop = null;
        $round = $this->round(ticks: function () use (&$ticks, &$stop) {
            if (++$ticks === 5) $stop = $this->round()->handle('stop');
            return 0;
        });
        $this->assertSame(Wire::report('stopped', 'operator_stop', true, false), $round->handle('dns'));
        $this->assertSame(Wire::report('stopping', 'operator_stop', false, false), $stop);
        $this->assertSame(0, $this->rowCount('v2_machine_failover_dns_slot'));
        $this->assertFileDoesNotExist($this->database(true));
        $this->assertSame('stopped', $this->round()->handle('v2')['phase']);
        $expired = $this->round(clock: fn () => $this->grant->expires);
        $this->assertSame('stopped', $expired->handle('dns')['phase']);
        $this->assertSame(0, $this->rowCount('v2_machine_failover_dns_run'));
    }

    public function test_expiry_without_fixture_data_never_initializes_the_database(): void
    {
        $expired = $this->round(clock: fn () => $this->grant->expires);
        $this->assertSame(Wire::report('stopped', 'expired', false, false), $expired->handle('v2'));
        $this->assertFileDoesNotExist($this->database());
    }

    public function test_interrupted_copy_is_not_resumed_or_mistaken_for_a_completed_run(): void
    {
        $this->assertSame('v2_passed', $this->round()->handle('v2')['phase']);
        DB::disconnect();
        $this->assertTrue(copy($this->database(), $this->database(true)));
        $this->assertSame(Wire::report('failed', 'verification_failed', true, false), $this->round()->handle('status'));
        $this->assertSame('failed', $this->round()->handle('dns')['phase']);
        $this->assertSame(0, $this->rowCount('v2_machine_failover_dns_run', true));
    }

    private function round(?\Closure $clock = null, ?\Closure $ticks = null): ScratchRound
    {
        return new ScratchRound(dirname(__DIR__, 3), $this->path, $this->grant, $clock, $ticks);
    }

    private function rowCount(string $table, bool $dns = false): int
    {
        return (int) (new PDO('sqlite:' . $this->database($dns)))->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function database(bool $dns = false): string { return $this->path . '/rehearsal-' . $this->grant->id . ($dns ? '.dns' : '') . '.sqlite3'; }
}

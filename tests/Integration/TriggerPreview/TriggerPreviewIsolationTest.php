<?php
declare(strict_types=1);

namespace Tests\Integration\TriggerPreview;

use App\Models\MachineFailoverTriggerPreview as Preview;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Support\NativeFailoverConcurrentFixture as Native;
use Tests\Support\TriggerPreviewFixture as Fixture;

final class TriggerPreviewIsolationTest extends TestCase
{
    private Fixture $fixture;
    private array $workers = [];
    private int $killed = 0;
    private float $started;

    protected function setUp(): void { $this->started = microtime(true); $this->fixture = new Fixture(); $this->fixture->seed(); }
    protected function tearDown(): void
    {
        Native::control('release', true, true);
        foreach (array_keys($this->workers) as $id) $this->join($id, true);
        foreach ($this->workers as $worker) {
            $until = microtime(true) + 3;
            while ($this->sessionActive($worker['sid']) && microtime(true) < $until) usleep(30000);
            if ($this->sessionActive($worker['sid'])) posix_kill(-$worker['sid'], SIGKILL);
        }
        $this->wait(fn () => !array_filter($this->workers, fn ($worker) => $this->sessionActive($worker['sid'])), 3);
        $summary = ['case' => $this->name(), 'isolation' => getenv('KELI_NATIVE_ISOLATION'), 'seconds' => round(microtime(true) - $this->started, 3),
            'owned_sessions' => count($this->workers), 'killed_collectors' => $this->killed,
            'collections' => DB::table('trigger_fixture_result')->where('kind', 'collect')->count(),
            'scheduler_ticks' => $this->events('scheduler_tick'), 'scheduler_finishes' => $this->events('schedule_finished'),
            'records' => Preview::query()->count(), 'transitions' => DB::table('v2_machine_failover_trigger_event')->count(),
            'preview_states' => Preview::query()->get()->map(fn ($row) => $row->only(['decision', 'reasons', 'source_state']))->all(),
            'executions' => DB::table('v2_machine_failover_execution')->count(), 'attempts' => DB::table('v2_machine_failover_attempt')->count(),
            'unexpected_http' => $this->events('forbidden_http'), 'unexpected_sql' => $this->events('forbidden_sql'),
            'process_errors' => $this->events('process_error'), 'scheduler_errors' => $this->events('scheduler_error'),
            'scheduler_diagnostics' => DB::table('native_fixture_event')->whereIn('kind', ['schedule_probe', 'schedule_output', 'scheduler_error', 'process_error'])->get(['kind', 'reference'])->all(),
            'console_diagnostic' => $this->consoleDiagnostic(),
            'cloud_mocked' => true, 'dns_mocked' => true, 'production_connections' => 0, 'live_owned_processes' => 0, 'cleanup' => false];
        try {
            $this->assertSame(0, $summary['executions']); $this->assertSame(0, $summary['attempts']);
            $this->assertSame(0, $summary['unexpected_http']); $this->assertSame(0, $summary['unexpected_sql']);
            $this->assertSame(0, $summary['process_errors']); $this->assertSame(0, $summary['scheduler_errors']);
            $this->assertSame(2, DB::table('v2_machine_provision_task')->count());
            $this->assertSame(0, DB::table('v2_machine_failover_budget_release')->count());
            $this->assertSame(0, DB::table('native_fixture_cloud')->count()); $this->assertSame(0, DB::table('native_fixture_dns')->count());
            $this->assertSame([], app('redis')->connection()->keys('queues:*'));
            $this->assertFalse(config('machine_failover.runner_enabled')); $this->assertFalse(config('machine_failover.allow_replacement'));
            $this->assertFalse(config('machine_provision.allow_live')); $this->assertFalse(config('machine_acceptance.allow_dns'));
        } finally {
            try { $summary['cleanup_details'] = $this->fixture->cleanup(); $summary['cleanup'] = !in_array(false, $summary['cleanup_details'], true); }
            finally {
                $dir = dirname(__DIR__, 3) . '/artifacts'; if (!is_dir($dir)) mkdir($dir, 0700, true);
                file_put_contents($dir . '/trigger-preview-summary.jsonl', json_encode($summary, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
            }
        }
        $this->assertTrue($summary['cleanup']);
    }
    private function events(string $kind): int { return DB::table('native_fixture_event')->where('kind', $kind)->count(); }
    private function consoleDiagnostic(): string
    {
        if (!is_file(Fixture::consolePath())) return 'absent';
        $text = file_get_contents(Fixture::consolePath());
        if (preg_match('/Trigger fixture failed: ([A-Za-z0-9_:.]+)/', $text, $match)) return $match[1];
        return $text === '' ? 'empty' : (str_contains($text, 'Fatal error') ? 'php_fatal' : 'output_present');
    }
    private function sessionActive(int $sid): bool
    {
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
            $raw = @file_get_contents($path); if (!$raw) continue;
            $end = strrpos($raw, ') '); if ($end === false) continue;
            $fields = explode(' ', substr($raw, $end + 2));
            if ((int) ($fields[3] ?? 0) === $sid && !in_array($fields[0], ['Z', 'X'], true)) return true;
        }
        return false;
    }
    private function spawn(string $mode = 'once'): string
    {
        $id = 'w' . bin2hex(random_bytes(6));
        $process = proc_open(['setsid', PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/trigger_preview_console.php', Native::$owner, $id, $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        $this->assertIsResource($process); fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $pid = proc_get_status($process)['pid']; $until = microtime(true) + 2;
        while (posix_getsid($pid) !== $pid && microtime(true) < $until) usleep(10000);
        $this->assertSame($pid, posix_getsid($pid), 'Owned session required before any signal.');
        $this->workers[$id] = ['process' => $process, 'pipes' => $pipes, 'sid' => $pid, 'joined' => false, 'killed' => false]; return $id;
    }
    private function join(string $id, bool $cleanup = false): void
    {
        $worker = &$this->workers[$id]; if ($worker['joined']) return;
        $until = microtime(true) + ($cleanup ? 2 : 20); $status = proc_get_status($worker['process']);
        while ($status['running'] && microtime(true) < $until) { usleep(30000); $status = proc_get_status($worker['process']); }
        if ($status['running']) { posix_kill(-$worker['sid'], SIGKILL); $worker['killed'] = true; usleep(100000); }
        foreach ([1, 2] as $pipe) { stream_get_contents($worker['pipes'][$pipe]); fclose($worker['pipes'][$pipe]); }
        $exit = proc_close($worker['process']); $worker['joined'] = true;
        if (!$cleanup && !$worker['killed']) $this->assertTrue($status['exitcode'] === 0 || $exit === 0, 'Child process failed; sanitized codes: ' .
            DB::table('native_fixture_event')->where('kind', 'process_error')->pluck('reference')->implode(', '));
        if (!$cleanup) $this->assertFalse($status['running'], 'Bounded fixture lifetime exceeded.');
    }
    private function wait(callable $condition, int $seconds = 15): void
    {
        $until = microtime(true) + $seconds;
        do { if ($condition()) return; usleep(50000); } while (microtime(true) < $until);
        $this->fail('Timed out waiting for isolated fixture condition; ' . $this->consoleDiagnostic() . '; ' . json_encode(DB::table('native_fixture_event')
            ->whereIn('kind', ['schedule_probe', 'schedule_output', 'scheduler_error', 'process_error'])->get(['kind', 'reference'])->all()));
    }
    private function collectOnce(): array
    {
        $before = $this->business(); $id = $this->spawn(); $this->join($id); $this->assertSame($before, $this->business());
        return json_decode(DB::table('trigger_fixture_result')->where('worker', $id)->value('payload'), true, 512, JSON_THROW_ON_ERROR);
    }
    private function business(): array
    {
        $hashes = [];
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = (string) array_values((array) $row)[0];
            if (str_starts_with($table, 'v2_') && !str_starts_with($table, 'v2_machine_failover_trigger_'))
                $hashes[$table] = hash('sha256', json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR));
        }
        return $hashes;
    }

    public function test_a_fresh_cli_reads_real_cached_finance_and_only_writes_audit_tables(): void
    {
        $result = $this->collectOnce(); $this->assertSame(0, $result['errors']); $this->assertSame(1, $result['services_checked']);
        $this->assertSame(['would_start', 'waiting'], Preview::query()->orderBy('member_id')->pluck('decision')->all(),
            Preview::query()->get()->map(fn ($row) => implode(',', $row->reasons))->implode(';'));
        $this->assertSame(2, $result['events_written']); $this->assertSame(0, $this->collectOnce()['events_written']);
        $this->assertFalse($result['dispatch_allowed']); $this->assertSame(0, $result['cloud_writes']);
    }
    public function test_one_real_schedule_event_launches_its_background_preview_and_finishes(): void
    {
        $id = $this->spawn('schedule'); $this->join($id);
        $this->wait(fn () => DB::table('trigger_fixture_result')->count() === 1 && $this->events('schedule_finished') === 1);
        $this->assertSame(2, Preview::query()->count()); $this->assertSame(1, $this->events('ScheduledTaskStarting'));
    }
    public function test_two_real_schedulers_run_across_natural_minutes_without_duplicate_transitions(): void
    {
        $ids = [$this->spawn('watch'), $this->spawn('watch')]; $until = microtime(true) + 126; $sample = 0;
        while (microtime(true) < $until) {
            if (microtime(true) >= $sample) { $this->fixture->sample(false); $sample = microtime(true) + 5; }
            usleep(100000);
        }
        foreach ($ids as $id) $this->join($id);
        $this->wait(fn () => $this->events('schedule_finished') === DB::table('trigger_fixture_result')->count());
        $this->assertGreaterThanOrEqual(2, DB::table('trigger_fixture_result')->count());
        $this->assertSame(2, Preview::query()->count()); $this->assertSame(2, DB::table('v2_machine_failover_trigger_event')->count());
        $this->assertSame(['would_start', 'waiting'], Preview::query()->orderBy('member_id')->pluck('decision')->all());
        $this->assertSame(1, DB::table('trigger_fixture_result')->select('at')->get()->groupBy(fn ($row) => intdiv($row->at, 60))->map->count()->max());
    }
    public function test_four_simultaneous_cli_collectors_share_the_real_sweep_lock(): void
    {
        Native::control('fault', 'before_collect', true); $ids = [];
        for ($i = 0; $i < 4; $i++) $ids[] = $this->spawn();
        $this->wait(fn () => (int) app('redis')->connection()->get('fixture-barrier:before_collect') === 4);
        $lock = app('cache')->lock('machine-failover:write', 120); $this->assertTrue($lock->get());
        try {
            Native::control('release', true, true);
            $this->wait(fn () => DB::table('trigger_fixture_result')->count() >= 3, 1);
        } finally { $lock->release(); }
        foreach ($ids as $id) $this->join($id);
        $this->assertSame(2, Preview::query()->count()); $this->assertSame(2, DB::table('v2_machine_failover_trigger_event')->count());
        $results = DB::table('trigger_fixture_result')->pluck('payload')->map(fn ($value) => json_decode($value, true));
        $this->assertSame(2, $results->sum('events_written')); $this->assertGreaterThanOrEqual(1, $results->where('busy', true)->count());
    }
    public function test_source_recovery_withdraws_the_episode_and_later_failure_gets_one_new_episode(): void
    {
        $this->collectOnce(); $old = Preview::query()->where('decision', 'would_start')->firstOrFail();
        $this->fixture->sample(true); $this->collectOnce(); $this->assertSame('cancelled', $old->fresh()->decision);
        $this->assertSame(['source_recovered'], $old->fresh()->reasons);
        sleep(5); $this->fixture->sample(false); sleep(5); $this->fixture->sample(false); $this->collectOnce();
        $this->assertSame(2, Preview::query()->where('member_id', $old->member_id)->whereNotNull('episode_key')->count());
        $this->assertSame('cancelled', $old->fresh()->decision); $events = DB::table('v2_machine_failover_trigger_event')->count();
        $this->collectOnce(); $this->assertSame($events, DB::table('v2_machine_failover_trigger_event')->count());
    }
    public function test_mysql_statement_failure_rolls_back_the_whole_audit_and_the_same_episode_resumes(): void
    {
        $member = DB::table('v2_machine_failover_member')->max('id');
        DB::unprepared('CREATE TRIGGER trigger_fixture_failure BEFORE INSERT ON v2_machine_failover_trigger_event FOR EACH ROW BEGIN IF NEW.member_id = '
            . (int) $member . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic audit failure'; END IF; END");
        $result = $this->collectOnce(); $this->assertSame(1, $result['errors']); $this->assertSame(0, $result['events_written']);
        $this->assertSame(0, $result['records_changed']); $this->assertSame(0, Preview::query()->count());
        DB::unprepared('DROP TRIGGER trigger_fixture_failure'); $this->collectOnce();
        $this->assertSame(2, Preview::query()->count()); $this->assertSame(2, DB::table('v2_machine_failover_trigger_event')->count());
    }
    public function test_sigkill_inside_transaction_recovers_after_natural_redis_leases_without_partial_records(): void
    {
        Native::control('fault', 'audit_inserted', true); $id = $this->spawn();
        $this->wait(fn () => (int) app('redis')->connection()->get('fixture-barrier:audit_inserted') > 0);
        posix_kill(-$this->workers[$id]['sid'], SIGKILL); $this->workers[$id]['killed'] = true; $this->killed++; $this->join($id);
        $this->wait(fn () => Preview::query()->count() === 0); $this->assertSame(0, DB::table('v2_machine_failover_trigger_event')->count());
        Native::control('fault', null, true); $blocked = $this->collectOnce(); $this->assertTrue($blocked['busy']);
        $wait = microtime(true);
        $this->wait(fn () => app('cache')->lock('machine-failover:write', 120)->get(fn () => true), 135);
        $this->assertGreaterThanOrEqual(110, microtime(true) - $wait);
        $this->fixture->sample(true); sleep(5); $this->fixture->sample(false); sleep(5); $this->fixture->sample(false); $this->collectOnce();
        $this->assertSame(2, Preview::query()->count()); $this->assertSame(2, DB::table('v2_machine_failover_trigger_event')->count());
        $this->assertSame(['would_start', 'waiting'], Preview::query()->orderBy('member_id')->pluck('decision')->all());
    }
    public function test_budget_and_permission_changes_block_and_cannot_be_overridden_by_a_process_restart(): void
    {
        $this->collectOnce(); $this->fixture->baseline(4000); $this->collectOnce();
        $this->assertContains('shared_monthly_budget', Preview::query()->orderBy('member_id')->firstOrFail()->reasons);
        DB::table('v2_user')->where('id', $this->fixture->scope['admin'])->update(['banned' => true]);
        $this->assertSame(1, $this->collectOnce()['errors']);
        $this->assertSame(0, Preview::query()->where('decision', 'would_start')->whereNull('closed_at')->count());
        $this->assertSame(0, $this->collectOnce()['events_written']);
    }
    public function test_expired_evidence_is_historical_until_fresh_collected_samples_requalify_it(): void
    {
        $this->collectOnce(); $old = Preview::query()->where('decision', 'would_start')->firstOrFail(); sleep(42);
        $this->collectOnce(); $this->assertSame('expired', $old->fresh()->decision);
        $this->fixture->sample(false); $this->collectOnce();
        $this->assertSame(0, Preview::query()->where('decision', 'would_start')->whereNull('closed_at')->count());
        sleep(5); $this->fixture->sample(false); $this->collectOnce();
        $this->assertSame(1, Preview::query()->where('decision', 'would_start')->whereNull('closed_at')->count());
    }
}

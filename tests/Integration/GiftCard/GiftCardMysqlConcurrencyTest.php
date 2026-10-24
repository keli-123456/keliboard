<?php

declare(strict_types=1);

namespace Tests\Integration\GiftCard;

use App\Models\AgentLedger;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Support\GiftCardMysqlFixture;

final class GiftCardMysqlConcurrencyTest extends TestCase
{
    private GiftCardMysqlFixture $fixture;
    private string $owner;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException('Run MySQL acceptance in the dedicated Linux CI service job.');
        }
        $this->fixture = new GiftCardMysqlFixture();
        $this->owner = $this->fixture->createSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function test_single_use_card_rewards_only_one_of_three_simultaneous_users(): void
    {
        $code = $this->fixture->card();
        $users = [$this->fixture->user(), $this->fixture->user(), $this->fixture->user()];
        $results = $this->race(array_map(fn (User $user): array => [$code, $user], $users));
        $this->assertSame(1, $this->successes($results));
        $this->assertSame(100, (int) User::query()->sum('balance'));
        $this->assertSame(1000, (int) User::query()->sum('transfer_enable'));
        $this->assertSame(1, (int) $code->fresh()->usage_count);
        $this->assertSame(1, GiftCardUsage::query()->count());
    }

    public function test_agent_card_debits_the_agent_and_writes_the_ledger_only_once(): void
    {
        $agent = $this->fixture->agent();
        $code = $this->agentCard($agent);
        $users = [$this->fixture->subordinate($agent), $this->fixture->subordinate($agent), $this->fixture->subordinate($agent)];
        $results = $this->race(array_map(fn (User $user): array => [$code, $user], $users));
        $this->assertSame(1, $this->successes($results));
        $this->assertSame(9500, (int) $agent->fresh()->balance);
        $this->assertSame(200, (int) User::whereKey(array_map(fn (User $user) => $user->id, $users))->sum('balance'));
        $this->assertSame(1, AgentLedger::query()->count());
        $this->assertSame(-500, (int) AgentLedger::query()->sum('amount'));
        $this->assertSame(1, GiftCardUsage::query()->count());
    }

    public function test_shared_card_never_exceeds_its_two_use_limit(): void
    {
        $code = $this->fixture->card(['max_usage' => 2]);
        $users = [$this->fixture->user(), $this->fixture->user(), $this->fixture->user()];
        $results = $this->race(array_map(fn (User $user): array => [$code, $user], $users));
        $this->assertSame(2, $this->successes($results));
        $this->assertSame(2, (int) $code->fresh()->usage_count);
        $this->assertSame(2, GiftCardUsage::query()->count());
        $this->assertSame(200, (int) User::query()->sum('balance'));
        $this->assertSame(2000, (int) User::query()->sum('transfer_enable'));
    }

    public function test_insufficient_agent_funds_leave_every_balance_and_card_unchanged(): void
    {
        $agent = $this->fixture->agent(200);
        $code = $this->agentCard($agent);
        $users = [$this->fixture->subordinate($agent), $this->fixture->subordinate($agent)];
        $results = $this->race(array_map(fn (User $user): array => [$code, $user], $users));
        $this->assertSame(0, $this->successes($results));
        $this->assertSame(200, (int) $agent->fresh()->balance);
        $this->assertSame(200, (int) User::query()->sum('balance'));
        $this->assertSame(0, AgentLedger::query()->count());
        $this->assertSame(0, GiftCardUsage::query()->count());
        $this->assertSame(0, (int) $code->fresh()->usage_count);
    }

    public function test_failed_award_rolls_back_agent_debit_before_another_waiter_redeems(): void
    {
        $agent = $this->fixture->agent();
        $code = $this->agentCard($agent);
        $failed = $this->fixture->subordinate($agent);
        $winner = $this->fixture->subordinate($agent);
        $results = $this->race([[$code, $failed, 'fail_award'], [$code, $winner]]);
        $this->assertSame('rejected', $results[0]['status']);
        $this->assertTrue($results[0]['injected_failure_after_debit']);
        $this->assertSame('success', $results[1]['status']);
        $this->assertSame(0, (int) $failed->fresh()->balance);
        $this->assertSame(200, (int) $winner->fresh()->balance);
        $this->assertSame(9500, (int) $agent->fresh()->balance);
        $this->assertSame(1, AgentLedger::query()->count());
        $this->assertSame(-500, (int) AgentLedger::query()->sum('amount'));
        $this->assertSame(1, GiftCardUsage::query()->count());
        $this->assertSame(1, (int) $code->fresh()->usage_count);
    }

    public function test_template_per_user_limit_is_enforced_across_different_codes(): void
    {
        $user = $this->fixture->user();
        $first = $this->fixture->card(['limits' => ['max_use_per_user' => 1]]);
        $second = $this->fixture->code($first->template_id);
        $results = $this->race([[$first, $user], [$second, $user]], false);
        $this->assertSame(1, $this->successes($results));
        $this->assertSame(100, (int) $user->fresh()->balance);
        $this->assertSame(1, GiftCardUsage::query()->count());
        $this->assertSame(1, (int) GiftCardCode::query()->sum('usage_count'));
    }

    public function test_different_templates_do_not_lose_updates_to_the_same_user(): void
    {
        $user = $this->fixture->user();
        $first = $this->fixture->card();
        $second = $this->fixture->card();
        $results = $this->race([[$first, $user], [$second, $user]], false);
        $this->assertSame(2, $this->successes($results));
        $this->assertSame(200, (int) $user->fresh()->balance);
        $this->assertSame(2000, (int) $user->fresh()->transfer_enable);
        $this->assertSame(2, GiftCardUsage::query()->count());
    }

    public function test_locked_redemption_rejects_a_different_site_without_consuming_the_card(): void
    {
        $code = $this->fixture->card(['scope_type' => GiftCardTemplate::SCOPE_SITE, 'site_id' => 1]);
        $outsider = $this->fixture->user(['site_id' => 2]);
        $owner = $this->fixture->user(['site_id' => 1]);
        $results = $this->race([[$code, $outsider, 'normal', false], [$code, $owner, 'normal', false]]);
        $this->assertSame('rejected', $results[0]['status']);
        $this->assertSame('success', $results[1]['status']);
        $this->assertSame(0, (int) $outsider->fresh()->balance);
        $this->assertSame(100, (int) $owner->fresh()->balance);
        $this->assertSame(1, GiftCardUsage::query()->count());
    }

    private function agentCard(User $agent): GiftCardCode
    {
        return $this->fixture->card(['scope_type' => GiftCardTemplate::SCOPE_AGENT, 'agent_user_id' => $agent->id,
            'rewards' => ['balance' => 200, 'expire_days' => 3]]);
    }

    private function successes(array $results): int
    {
        return count(array_filter($results, fn (array $row): bool => $row['status'] === 'success'));
    }

    private function race(array $requests, bool $sameCode = true): array
    {
        $workers = [];
        try {
            foreach ($requests as [$code, $user]) {
                $index = count($workers);
                $request = $requests[$index];
                $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/gift_card_mysql_worker.php',
                    $this->owner, (string) $code->id, (string) $user->id, $request[2] ?? 'normal',
                    ($request[3] ?? true) ? '1' : '0'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                    dirname(__DIR__, 3));
                if (!is_resource($process)) {
                    throw new \RuntimeException('Failed to start an independent acceptance worker.');
                }
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'pipes' => $pipes, 'buffer' => '', 'events' => [], 'stderr' => false];
            }
            foreach (array_keys($workers) as $index) {
                $this->await($workers, $index, 'ready');
            }
            $ready = array_column(array_column($workers, 'events'), 'ready');
            $this->assertCount(count($workers), array_unique(array_column($ready, 'pid')));
            $this->assertCount(count($workers), array_unique(array_column($ready, 'connection_id')));
            // Keep the first real row lock held while the remaining ready processes enter redeem().
            $this->release($workers[0]);
            $this->await($workers, 0, 'locked');
            foreach (array_keys($workers) as $index) {
                if ($index !== 0) {
                    $this->release($workers[$index]);
                }
            }
            $results = [];
            foreach (array_keys($workers) as $index) {
                $this->await($workers, $index, 'result');
                $results[] = $workers[$index]['events']['result'];
                $this->assertIsNumeric($results[$index]['lock_query_ms'], 'Every worker must execute the real row-lock read.');
            }
            if ($sameCode) {
                $this->assertGreaterThan(100, max(array_column($results, 'lock_query_ms')), 'Expected an overlapping MySQL lock wait.');
            }
            foreach ($workers as $worker) {
                $deadline = microtime(true) + 3;
                do {
                    $status = proc_get_status($worker['process']);
                    if (!$status['running']) {
                        $this->assertSame(0, $status['exitcode'], 'The acceptance worker must finish normally.');
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertFalse($status['running'], 'The acceptance worker did not exit after its result.');
            }
            echo 'GIFTCARD_MYSQL ' . json_encode(['case' => $this->name(), 'workers' => count($workers),
                'successes' => $this->successes($results), 'rejected' => count($workers) - $this->successes($results),
                'distinct_connections' => count($workers), 'max_code_lock_ms' => max(array_column($results, 'lock_query_ms')),
                'isolation' => getenv('KELI_GIFTCARD_ISOLATION')], JSON_THROW_ON_ERROR) . "\n";
            return $results;
        } finally {
            foreach ($workers as $worker) {
                if (proc_get_status($worker['process'])['running']) {
                    proc_terminate($worker['process']);
                }
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($worker['process']);
            }
        }
    }

    private function release(array &$worker): void
    {
        fwrite($worker['pipes'][0], "GO\n");
        fflush($worker['pipes'][0]);
        fclose($worker['pipes'][0]);
    }

    private function await(array &$workers, int $index, string $event): void
    {
        $deadline = microtime(true) + 25;
        do {
            foreach ($workers as &$worker) {
                $worker['buffer'] .= stream_get_contents($worker['pipes'][1]);
                $worker['stderr'] = $worker['stderr'] || stream_get_contents($worker['pipes'][2]) !== '';
                if (strlen($worker['buffer']) > 16384 || $worker['stderr']) {
                    throw new \RuntimeException('Acceptance worker emitted unexpected output; raw output omitted.');
                }
                while (($newline = strpos($worker['buffer'], "\n")) !== false) {
                    $packet = json_decode(substr($worker['buffer'], 0, $newline), true, 16, JSON_THROW_ON_ERROR);
                    $worker['buffer'] = substr($worker['buffer'], $newline + 1);
                    if (($packet['event'] ?? '') === 'error') {
                        throw new \RuntimeException('Acceptance worker failed: ' . ($packet['type'] ?? 'unknown'));
                    }
                    $worker['events'][$packet['event']] = $packet;
                }
            }
            unset($worker);
            if (isset($workers[$index]['events'][$event])) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Acceptance worker timed out at the ' . $event . ' barrier.');
    }
}

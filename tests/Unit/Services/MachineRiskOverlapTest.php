<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\MachineRiskOverlap;
use App\Services\ServerMachine\MachineRiskSettings;
use PHPUnit\Framework\TestCase;

final class MachineRiskOverlapTest extends TestCase
{
    public function test_two_independent_waves_flag_without_subscription_evidence_or_queuing_a_reset(): void
    {
        $at = 1800000000;
        $overlap = new MachineRiskOverlap();
        $settings = MachineRiskSettings::OVERLAP_DEFAULTS;
        $current = $this->onset(3, $at);
        $users = [$this->activity($at)];
        $prior = $this->prior(1, 1, $at - 3600);
        $review = $overlap->capture($current, $users, [$prior], $settings, []);

        self::assertSame(2, $review['review_minimum']);
        self::assertSame('matched', $review['state']);
        self::assertTrue($review['accounts'][0]['needs_review']);
        self::assertFalse($review['accounts'][0]['matched']);
        self::assertFalse(MachineRiskOverlap::hasActionCandidate($review));
        self::assertSame([3, 1], array_column($review['accounts'][0]['occurrences'], 'machine_id'));

        $action = $overlap->capture($current, $users, [$prior, $this->prior(2, 2, $at - 1800)], $settings, []);
        self::assertTrue($action['accounts'][0]['matched']);
        self::assertTrue(MachineRiskOverlap::hasActionCandidate($action));
        self::assertCount(3, $action['accounts'][0]['occurrences']);
    }

    public function test_one_wave_one_machine_and_missing_probe_evidence_do_not_flag(): void
    {
        $at = 1800000000;
        $overlap = new MachineRiskOverlap();
        $settings = MachineRiskSettings::OVERLAP_DEFAULTS;
        $current = $this->onset(3, $at);
        $users = [$this->activity($at)];
        $unknown = $this->prior(4, 4, $at - 5400);
        $unknown->last_reachable_at = null;
        foreach ([$this->prior(2, 2, $at - 200), $this->prior(1, 3, $at - 3600), $unknown] as $prior) {
            $review = $overlap->capture($current, $users, [$prior], $settings, []);
            self::assertSame('no_match', $review['state']);
            self::assertFalse($review['accounts'][0]['needs_review']);
        }
    }

    private function onset(int $machine, int $at): array
    {
        return ['machine_id' => $machine, 'first_failure_at' => $at, 'last_reachable_at' => $at - 60, 'detected_at' => $at + 30,
            'probe' => ['status' => 'suspected_blocked', 'checked_at' => $at + 30, 'consecutive_failures' => 2,
                'configured_targets' => 4, 'unreachable_targets' => 4, 'reachable_targets' => 0, 'unknown_targets' => 0]];
    }

    private function prior(int $id, int $machine, int $at): object
    {
        $row = $this->onset($machine, $at);
        return (object) ($row + ['id' => $id, 'review_verdict' => null, 'reviewed_at' => null, 'evidence' => [
            'kind' => 'outbound_probe_failure', 'probe' => $row['probe'], 'users' => [$this->activity($at)],
        ]]);
    }

    private function activity(int $at): array
    {
        return ['ref' => str_repeat('a', 16), 'identity_epoch' => str_repeat('b', 64), 'user_id' => 1, 'site_id' => 2,
            'failure_window_at' => $at - 15];
    }
}

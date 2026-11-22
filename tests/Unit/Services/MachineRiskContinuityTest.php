<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\MachineRiskContinuity;
use PHPUnit\Framework\TestCase;

final class MachineRiskContinuityTest extends TestCase
{
    public function test_latest_same_scope_state_wins_and_current_wins_ties(): void
    {
        $current = ['last_checked_at' => 100, 'episode' => ['detected_at' => 100]];
        $rollback = ['last_checked_at' => 105, 'last_reachable_at' => 105];
        $sameTimestamp = ['last_checked_at' => 100, 'last_reachable_at' => 100];
        $this->assertSame($rollback, MachineRiskContinuity::newest($current, null, $rollback));
        $this->assertSame($current, MachineRiskContinuity::newest($current, $sameTimestamp));
        $this->assertSame([], MachineRiskContinuity::newest(null, null));
    }

    public function test_unchanged_original_waits_for_telemetry_instead_of_failing(): void
    {
        $this->assertSame('awaiting_report', MachineRiskContinuity::originalEpisodeStatus(false, 100, null, null, true));
        $this->assertSame('awaiting_report', MachineRiskContinuity::originalEpisodeStatus(false, 100, 100, 100, true));
        $this->assertSame('awaiting_report', MachineRiskContinuity::originalEpisodeStatus(false, 100, 90, 100, true));
    }

    public function test_an_unapplied_report_or_mismatched_episode_never_counts_as_waiting(): void
    {
        $this->assertSame('sample_not_applied', MachineRiskContinuity::originalEpisodeStatus(false, 100, null, 105, true));
        $this->assertSame('sample_not_applied', MachineRiskContinuity::originalEpisodeStatus(false, 100, 105, 110, true));
        $this->assertSame('mismatch', MachineRiskContinuity::originalEpisodeStatus(false, 100, 110, 110, false));
        $this->assertSame('mismatch', MachineRiskContinuity::originalEpisodeStatus(false, null, null, null, true));
    }

    public function test_new_sample_and_persisted_recovery_have_distinct_outcomes(): void
    {
        $this->assertSame('adopted', MachineRiskContinuity::originalEpisodeStatus(false, 100, 105, 105, true));
        $this->assertSame('recovered', MachineRiskContinuity::originalEpisodeStatus(true, 100, 105, 110, false));
    }
}

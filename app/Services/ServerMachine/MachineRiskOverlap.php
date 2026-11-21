<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

/** Positive, sampled overlaps across machines; never an account attribution. */
final class MachineRiskOverlap
{
    public const HISTORY = 7 * 86400;
    public const REVIEW_MINIMUM = 2;
    public const MAX_BASELINE_AGE = 300;
    private const MIN_WAVE_GAP = 900;
    private const EXAMPLES = 3;

    public static function onset(array $event): ?int
    {
        $first = $event['first_failure_at'] ?? null;
        $good = $event['last_reachable_at'] ?? null;
        $detected = $event['detected_at'] ?? null;
        $probe = $event['probe'] ?? [];
        $targets = $probe['configured_targets'] ?? null;
        if (!is_int($first) || !is_int($good) || $good <= 0 || $good >= $first || $first - $good > self::MAX_BASELINE_AGE
            || !is_int($detected) || $detected < $first || $detected - $first > self::MIN_WAVE_GAP
            || !is_int($targets) || $targets < 1 || ($probe['status'] ?? null) !== 'suspected_blocked'
            || ($probe['checked_at'] ?? null) !== $detected || ($probe['unknown_targets'] ?? null) !== 0
            || ($probe['reachable_targets'] ?? null) !== 0 || ($probe['unreachable_targets'] ?? null) !== $targets
            || ($probe['consecutive_failures'] ?? 2) !== 2) return null;
        return $first;
    }

    /** $prior contains only scoped, already persisted events bounded by the comparison history limit. */
    public function capture(array $episode, array $users, array $prior, array $settings, array $resets): array
    {
        $window = $settings['window_minutes'] * 60;
        $history = $settings['history_days'] * 86400;
        $waveGap = max(self::MIN_WAVE_GAP, $window);
        $minimum = $settings['minimum_machines']; $repeatMinimum = $settings['repeat_minimum'];
        $result = ['kind' => 'cross_machine_presence', 'state' => 'unavailable', 'window_seconds' => $window, 'history_seconds' => $history,
            'wave_gap_seconds' => $waveGap, 'review_minimum' => self::REVIEW_MINIMUM, 'minimum_distinct' => $minimum, 'repeat_minimum' => $repeatMinimum,
            'repeated' => 'none', 'accounts' => []];
        $cutoff = self::onset($episode);
        if ($cutoff === null) return $result;

        $current = ['event_id' => null, 'machine_id' => (int) $episode['machine_id'], 'first_failure_at' => $cutoff];
        $epochs = [];
        foreach ($users as $user) {
            $epoch = $user['identity_epoch'] ?? null;
            if (is_string($epoch) && ($at = $this->activityAt($user, $cutoff, $window)) !== null) {
                $reset = $resets[(int) ($user['user_id'] ?? 0)] ?? null;
                if ($reset && (!hash_equals((string) $reset->post_epoch, $epoch) || (int) $reset->site_id !== $user['site_id'])) $reset = null;
                $epochs[$epoch] = ['ref' => $user['ref'], 'active_at' => $at, 'reset' => $reset,
                    'node_sync' => $user['failure_window_node_sync'] ?? null];
            }
        }
        $result['state'] = 'no_match';
        if ($epochs === []) return $result;

        $events = [];
        foreach ($prior as $row) {
            $evidence = $row->evidence;
            if (($evidence['kind'] ?? null) !== 'outbound_probe_failure'
                || ($row->review_verdict === 'false_positive' && $row->reviewed_at <= $episode['detected_at'])) continue;
            $first = self::onset(['first_failure_at' => $row->first_failure_at, 'last_reachable_at' => $row->last_reachable_at,
                'detected_at' => $row->detected_at, 'probe' => $evidence['probe'] ?? []]);
            if ($first === null || $first < $cutoff - $history || $first > $cutoff) continue;
            $events[] = ['event_id' => (int) $row->id, 'machine_id' => $row->machine_id, 'first_failure_at' => $first,
                'users' => $evidence['users'] ?? []];
        }
        usort($events, fn ($a, $b) => $a['first_failure_at'] <=> $b['first_failure_at'] ?: $a['event_id'] <=> $b['event_id']);

        $wave = 0; $previous = null; $hits = [];
        foreach ($events as $event) {
            $first = $event['first_failure_at'];
            if ($previous !== null && $first - $previous > $waveGap) $wave++;
            $previous = $first;
            $pastUsers = $event['users']; unset($event['users']);
            foreach ($pastUsers as $user) {
                $epoch = $user['identity_epoch'] ?? null;
                if (!is_string($epoch) || !isset($epochs[$epoch]) || ($at = $this->activityAt($user, $first, $window)) === null) continue;
                $hits[$epoch][$wave . ':' . $event['machine_id']] = $event + ['active_at' => $at, 'wave' => $wave,
                    'node_sync' => $user['failure_window_node_sync'] ?? null];
            }
        }
        if ($previous !== null && $cutoff - $previous > $waveGap) $wave++;

        foreach ($epochs as $epoch => $account) {
            $now = $current + ['active_at' => $account['active_at'], 'wave' => $wave, 'node_sync' => $account['node_sync']];
            $past = array_reverse(array_values($hits[$epoch] ?? []));
            $proof = $this->distinct($now, $past, $minimum);
            $reset = $account['reset'];
            $repeat = [];
            if ($reset && $now['active_at'] > (int) $reset->rotated_at + MachineRiskCredentialActions::SYNC_GRACE) {
                $boundary = (int) $reset->rotated_at + MachineRiskCredentialActions::SYNC_GRACE;
                $repeat = $this->distinct($now, array_filter($past, fn ($hit) => $hit['active_at'] > $boundary), $repeatMinimum);
            }
            $observed = [$now, ...$past];
            $review = $repeat ?: $proof ?: $this->distinct($now, $past, self::REVIEW_MINIMUM);
            $result['accounts'][] = ['ref' => $account['ref'], 'matched' => $proof !== [],
                'needs_review' => $review !== [],
                'machines' => count(array_unique(array_column($observed, 'machine_id'))),
                'waves' => count(array_unique(array_column($observed, 'wave'))),
                'after_reset' => $repeat !== [], 'reset_receipt_id' => $reset ? (int) $reset->id : null,
                'reset_at' => $reset ? (int) $reset->rotated_at : null,
                'occurrences' => array_map(fn ($item) => array_diff_key($item, ['wave' => true]), $review ?: array_slice($observed, 0, self::EXAMPLES))];
            if ($review !== []) $result['state'] = 'matched';
            if ($repeat !== []) $result['repeated'] = 'after_reset';
        }
        return $result;
    }

    public static function hasActionCandidate(array $priority): bool
    {
        if (($priority['kind'] ?? null) !== 'cross_machine_presence' || ($priority['state'] ?? null) !== 'matched') return false;
        foreach ($priority['accounts'] ?? [] as $account) {
            if (($account['matched'] ?? false) === true || ($account['after_reset'] ?? false) === true) return true;
        }
        return false;
    }

    private function activityAt(array $user, int $failure, int $window): ?int
    {
        // Legacy snapshots only prove the two retained sample times, not the interval between them.
        $times = array_key_exists('failure_window_at', $user) ? [$user['failure_window_at']]
            : [$user['activity']['last_at'] ?? null, $user['activity']['first_at'] ?? null];
        foreach ($times as $at) {
            if (is_int($at) && $at >= $failure - $window && $at < $failure) return $at;
        }
        return null;
    }

    private function distinct(array $current, array $past, int $minimum): array
    {
        $byWave = [];
        foreach ($past as $hit) {
            if ($hit['machine_id'] === $current['machine_id'] || $hit['wave'] === $current['wave']) continue;
            $byWave[$hit['wave']][] = $hit;
        }
        $chosen = [];
        foreach (array_keys($byWave) as $wave) {
            $seen = [];
            if ($this->assign($wave, $byWave, $chosen, $seen) && count($chosen) === $minimum - 1) return [$current, ...array_values($chosen)];
        }
        return [];
    }

    private function assign(int $wave, array $byWave, array &$chosen, array &$seen): bool
    {
        // Reassign a previous wave if it can use another machine, so every counted pair is distinct.
        foreach ($byWave[$wave] as $hit) {
            $id = $hit['machine_id'];
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            if (!isset($chosen[$id]) || $this->assign($chosen[$id]['wave'], $byWave, $chosen, $seen)) {
                $chosen[$id] = $hit;
                return true;
            }
        }
        return false;
    }
}

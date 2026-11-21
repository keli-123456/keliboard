<?php

namespace App\Services;

use App\Models\SubscriptionControlCaseReview;
use App\Models\User;
use App\Services\ServerMachine\MachineRiskComparison;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SubscriptionControlCaseReviewService
{
    private const REVIEW_TABLE = 'v2_subscription_control_case_review';
    private const EVENT_TABLE = 'v2_subscription_control_event';
    private const USER_TABLE = 'v2_user';
    private const PROOF_TTL = 900;
    private const EVIDENCE_MAX_AGE = 7 * 86400;
    private const CREDENTIAL_EVENT_CODES = ['subscription_leak_guard', 'source_batch_pull'];
    private const BLOCKING_ACTIONS = ['block', 'empty', 'throttle', 'reset_token', 'reset_token_uuid'];
    private const CALIBRATION_VERSION = '1.0.0';
    private const MIN_CALIBRATION_SAMPLES = 5;
    private const MAX_TOTAL_ADJUSTMENT = 12;
    private const STRONG_EVENT_CODES = [
        'subscription_leak_guard',
        'source_batch_pull',
        'source_ip_denylist',
        'online_ip_threshold',
        'multi_ua_pull',
        'multi_region_pull',
    ];

    /** @param array<string, mixed> $modelSnapshot @return array<string, mixed> */
    public function review(
        int $userId,
        string $status,
        ?string $note,
        int $adminId,
        array $modelSnapshot = []
    ): array {
        if (!$this->available()) {
            throw new RuntimeException('case_review_migration_required');
        }
        if (!in_array($status, SubscriptionControlCaseReview::STATUSES, true)) {
            throw new RuntimeException('invalid_case_review_status');
        }
        if ($userId <= 0 || !DB::table(self::USER_TABLE)->where('id', $userId)->exists()) {
            throw new RuntimeException('user_not_found');
        }

        $eventSnapshot = $this->eventSnapshot($userId);
        $snapshot = [
            'model_version' => trim((string) ($modelSnapshot['model_version'] ?? '')) ?: null,
            'verdict' => trim((string) ($modelSnapshot['verdict'] ?? '')) ?: null,
            'confidence' => trim((string) ($modelSnapshot['confidence'] ?? '')) ?: null,
            'case_evidence' => $this->stringList($modelSnapshot['case_evidence'] ?? [], 16),
            'false_positive_factors' => $this->stringList($modelSnapshot['false_positive_factors'] ?? [], 12),
            'post_reset_retrigger_count' => max(0, (int) ($modelSnapshot['post_reset_retrigger_count'] ?? 0)),
            'event_summary' => $eventSnapshot,
        ];
        $score = max(0, min(100, (int) ($modelSnapshot['suspicion_score'] ?? 0)));
        $lastTriggerAt = max(
            (int) ($eventSnapshot['last_trigger_at'] ?? 0),
            max(0, (int) ($modelSnapshot['last_trigger_at'] ?? 0))
        ) ?: null;
        $now = time();

        $proof = $status === SubscriptionControlCaseReview::STATUS_CONFIRMED_LEAK ? ($modelSnapshot['credential_review_proof'] ?? null) : null;
        $review = DB::transaction(function () use ($userId, $status, $note, $adminId, $snapshot, $score, $lastTriggerAt, $now, $proof): SubscriptionControlCaseReview {
            $user = User::query()->lockForUpdate()->find($userId);
            if (!$user) throw new RuntimeException('user_not_found');
            if ($proof !== null) {
                $evidence = $this->verifyCredentialEvidence($user, $proof, time());
                if ($adminId > 0 && trim((string) $note) !== '') {
                    $snapshot['confirmed_credential_epoch'] = $evidence->credential_epoch;
                    $snapshot['confirmed_credential_event'] = $this->describeCredentialEvidence($evidence);
                }
            }
            return SubscriptionControlCaseReview::query()->create([
                'user_id' => $userId,
                'status' => $status,
                'note' => mb_substr(trim((string) ($note ?? '')), 0, 1000) ?: null,
                'evidence_snapshot' => $snapshot,
                'suspicion_score' => $score,
                'evidence_fingerprint' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'baseline_last_trigger_at' => $lastTriggerAt,
                'reviewed_at' => $now,
                'admin_id' => $adminId > 0 ? $adminId : null,
            ]);
        });

        return $this->serialize($review, ['new_event_count' => 0, 'last_new_event_at' => null]);
    }

    /** @param array<string, mixed> $overview @return array<string, mixed> */
    public function attachOverview(array $overview): array
    {
        $overview['case_review_available'] = $this->available();
        if (!$this->available()) {
            $overview['case_review_summary'] = $this->emptySummary();
            return $overview;
        }

        $items = is_array($overview['items'] ?? null) ? $overview['items'] : [];
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn(array $item): int => (int) ($item['user_id'] ?? 0),
            $items
        ))));
        $latest = $this->latestReviewsForUsers($userIds);
        $newEvents = $this->newEventMetrics($latest->values());
        $historyCounts = $this->historyCounts($userIds);
        $users = $userIds !== [] && $this->credentialEvidenceAvailable()
            ? User::query()->whereIn('id', $userIds)->get()->keyBy('id')
            : collect();
        $now = time();

        foreach ($items as $index => $item) {
            $userId = (int) ($item['user_id'] ?? 0);
            $review = $latest->get($userId);
            $items[$index]['case_review'] = $review
                ? $this->serialize($review, $newEvents[(int) $review->id] ?? [], $historyCounts[$userId] ?? 1)
                : null;
            $user = $users->get($userId);
            if ($user && ($event = $this->credentialEvent($user, $now))) {
                $expires = $now + self::PROOF_TTL;
                $items[$index]['credential_review_evidence'] = $this->describeCredentialEvidence($event) + [
                    'proof' => $this->credentialProof($event, (int) ($review?->id ?? 0), $expires),
                ];
            }
        }

        $overview['items'] = $items;
        $overview['case_review_summary'] = $this->summary();
        return $overview;
    }

    /** Only called for decisions on the authenticated subscription request, before any reset. */
    public static function observedCredentialEpoch(User $user, string $code, array $meta): ?string
    {
        if (!config('app.key') || trim((string) $user->token) === '' || trim((string) $user->uuid) === ''
            || !in_array($code, self::CREDENTIAL_EVENT_CODES, true)
            || ($code === 'subscription_leak_guard' && count(array_unique((array) ($meta['signal_families'] ?? []))) < 2)) {
            return null;
        }
        return app(MachineRiskComparison::class)->identity($user);
    }

    private function credentialEvidenceAvailable(): bool
    {
        return (bool) config('app.key') && Schema::hasColumn(self::EVENT_TABLE, 'credential_epoch');
    }

    private function credentialEvent(User $user, int $now, ?int $id = null): ?object
    {
        if (trim((string) $user->token) === '' || trim((string) $user->uuid) === '') return null;
        return DB::table(self::EVENT_TABLE)->where('user_id', $user->id)
            ->where('credential_epoch', app(MachineRiskComparison::class)->identity($user))
            ->whereIn('code', self::CREDENTIAL_EVENT_CODES)
            ->whereBetween('created_at', [$now - self::EVIDENCE_MAX_AGE, $now])
            ->when($id !== null, fn ($query) => $query->where('id', $id))
            ->orderByDesc('id')->first(['id', 'user_id', 'code', 'action', 'created_at', 'credential_epoch']);
    }

    private function describeCredentialEvidence(object $event): array
    {
        return ['id' => (int) $event->id, 'code' => $event->code, 'action' => $event->action, 'created_at' => (int) $event->created_at];
    }

    private function credentialProof(object $event, int $lastReviewId, int $expires): string
    {
        $prefix = $expires . '.' . $event->id;
        $payload = json_encode(['subscription-case-review:v1', $event->user_id, $event->credential_epoch,
            $this->describeCredentialEvidence($event), $lastReviewId, $expires], JSON_THROW_ON_ERROR);
        return $prefix . '.' . hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function verifyCredentialEvidence(User $user, mixed $proof, int $now): object
    {
        if (!is_string($proof) || !preg_match('/^([1-9][0-9]{0,17})\.([1-9][0-9]{0,17})\.[a-f0-9]{64}$/D', $proof, $parts)
            || (int) $parts[1] <= $now || (int) $parts[1] > $now + self::PROOF_TTL || !$this->credentialEvidenceAvailable()
            || !($event = $this->credentialEvent($user, $now, (int) $parts[2]))) {
            throw new RuntimeException('case_evidence_changed');
        }
        $latest = SubscriptionControlCaseReview::query()->where('user_id', $user->id)->orderByDesc('id')->lockForUpdate()->first(['id']);
        if (!hash_equals($this->credentialProof($event, (int) ($latest?->id ?? 0), (int) $parts[1]), $proof)) {
            throw new RuntimeException('case_evidence_changed');
        }
        return $event;
    }

    /** @param array<string, mixed> $overview @return array<string, mixed> */
    public function calibrateOverview(array $overview, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $profile = $this->calibrationProfile();
        $rules = collect($profile['evidence_rules'] ?? [])->keyBy('evidence');
        $items = is_array($overview['items'] ?? null) ? $overview['items'] : [];

        foreach ($items as $index => $item) {
            $baseScore = max(0, min(100, (int) ($item['suspicion_score'] ?? 0)));
            $adjustment = 0;
            $matches = [];
            foreach ($this->stringList($item['case_evidence'] ?? [], 16) as $evidence) {
                $rule = $rules->get($evidence);
                if (!is_array($rule) || !($rule['eligible'] ?? false)) {
                    continue;
                }
                $adjustment += (int) ($rule['adjustment'] ?? 0);
                $matches[] = $rule;
            }

            $adjustment = max(-self::MAX_TOTAL_ADJUSTMENT, min(self::MAX_TOTAL_ADJUSTMENT, $adjustment));
            $items[$index]['base_suspicion_score'] = $baseScore;
            $items[$index]['calibrated_ranking_score'] = max(0, min(100, $baseScore + $adjustment));
            $items[$index]['calibration_adjustment'] = $adjustment;
            $items[$index]['calibration_applied'] = $adjustment !== 0;
            $items[$index]['calibration_matches'] = $matches;
        }

        usort($items, static fn(array $left, array $right): int =>
            ((int) ($right['calibrated_ranking_score'] ?? 0) <=> (int) ($left['calibrated_ranking_score'] ?? 0))
            ?: ((int) ($right['suspicion_score'] ?? 0) <=> (int) ($left['suspicion_score'] ?? 0))
            ?: ((int) ($right['last_trigger_at'] ?? 0) <=> (int) ($left['last_trigger_at'] ?? 0))
        );

        $overview['items'] = array_slice($items, 0, $limit);
        $overview['case_review_calibration'] = $profile;

        return $overview;
    }

    /** @return array<string, mixed> */
    public function calibrationMetrics(): array
    {
        if (!$this->available()) {
            return array_merge($this->emptySummary(), [
                'evidence_calibration' => $this->emptyCalibration(),
            ]);
        }

        return array_merge($this->summary(), [
            'evidence_calibration' => $this->calibrationProfile(),
        ]);
    }

    /** @return array<string, mixed> */
    private function calibrationProfile(): array
    {
        if (!$this->available()) {
            return $this->emptyCalibration();
        }

        $latestIds = DB::table(self::REVIEW_TABLE)
            ->groupBy('user_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->all();
        if ($latestIds === []) {
            $profile = $this->emptyCalibration();
            $profile['available'] = true;
            return $profile;
        }

        $reviews = SubscriptionControlCaseReview::query()
            ->whereIn('id', $latestIds)
            ->whereIn('status', [
                SubscriptionControlCaseReview::STATUS_CONFIRMED_LEAK,
                SubscriptionControlCaseReview::STATUS_FALSE_POSITIVE,
            ])
            ->get();
        $counts = [];
        foreach ($reviews as $review) {
            $snapshot = is_array($review->evidence_snapshot) ? $review->evidence_snapshot : [];
            foreach ($this->stringList($snapshot['case_evidence'] ?? [], 16) as $evidence) {
                $counts[$evidence] ??= ['confirmed' => 0, 'false_positive' => 0];
                $key = $review->status === SubscriptionControlCaseReview::STATUS_CONFIRMED_LEAK
                    ? 'confirmed'
                    : 'false_positive';
                $counts[$evidence][$key]++;
            }
        }

        $rules = [];
        foreach ($counts as $evidence => $count) {
            $confirmed = (int) $count['confirmed'];
            $falsePositive = (int) $count['false_positive'];
            $sampleCount = $confirmed + $falsePositive;
            $eligible = $sampleCount >= self::MIN_CALIBRATION_SAMPLES;
            $confirmationRate = $sampleCount > 0 ? round($confirmed / $sampleCount, 4) : 0.0;
            $falsePositiveRate = $sampleCount > 0 ? round($falsePositive / $sampleCount, 4) : 0.0;
            $smoothedRate = ($confirmed + 2) / ($sampleCount + 4);
            $sampleStrength = min(1.0, $sampleCount / 10);
            $adjustment = $eligible
                ? (int) round(($smoothedRate - 0.5) * 8 * $sampleStrength)
                : 0;

            $rules[] = [
                'evidence' => (string) $evidence,
                'sample_count' => $sampleCount,
                'confirmed_count' => $confirmed,
                'false_positive_count' => $falsePositive,
                'confirmation_rate' => $confirmationRate,
                'false_positive_rate' => $falsePositiveRate,
                'eligible' => $eligible,
                'adjustment' => max(-4, min(4, $adjustment)),
            ];
        }
        usort($rules, static fn(array $left, array $right): int =>
            ($right['sample_count'] <=> $left['sample_count'])
            ?: strcmp((string) $left['evidence'], (string) $right['evidence'])
        );

        return [
            'available' => true,
            'version' => self::CALIBRATION_VERSION,
            'minimum_samples' => self::MIN_CALIBRATION_SAMPLES,
            'maximum_total_adjustment' => self::MAX_TOTAL_ADJUSTMENT,
            'labeled_users' => $reviews->count(),
            'eligible_rule_count' => count(array_filter($rules, static fn(array $rule): bool => (bool) $rule['eligible'])),
            'evidence_rules' => $rules,
            'affects_ranking_only' => true,
            'automatic_enforcement' => false,
        ];
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable(self::REVIEW_TABLE)
                && Schema::hasTable(self::EVENT_TABLE)
                && Schema::hasTable(self::USER_TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<int, int> $userIds @return Collection<int, SubscriptionControlCaseReview> */
    private function latestReviewsForUsers(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        $ids = DB::table(self::REVIEW_TABLE)
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return collect();
        }

        return SubscriptionControlCaseReview::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn(SubscriptionControlCaseReview $review): int => (int) $review->user_id);
    }

    /** @param Collection<int, SubscriptionControlCaseReview> $reviews @return array<int, array<string, int|null>> */
    private function newEventMetrics(Collection $reviews): array
    {
        if ($reviews->isEmpty()) {
            return [];
        }

        $reviewIds = $reviews->pluck('id')->map(static fn(mixed $id): int => (int) $id)->all();
        return DB::table(self::EVENT_TABLE . ' as event')
            ->join(self::REVIEW_TABLE . ' as review', 'review.user_id', '=', 'event.user_id')
            ->whereIn('review.id', $reviewIds)
            ->whereColumn('event.created_at', '>', 'review.reviewed_at')
            ->where(function ($query): void {
                $query->whereIn('event.action', self::BLOCKING_ACTIONS)
                    ->orWhereIn('event.code', self::STRONG_EVENT_CODES);
            })
            ->groupBy('review.id')
            ->selectRaw('review.id as review_id, COUNT(*) as new_event_count, MAX(event.created_at) as last_new_event_at')
            ->get()
            ->mapWithKeys(static fn(object $row): array => [
                (int) $row->review_id => [
                    'new_event_count' => (int) $row->new_event_count,
                    'last_new_event_at' => $row->last_new_event_at === null ? null : (int) $row->last_new_event_at,
                ],
            ])
            ->all();
    }

    /** @param array<int, int> $userIds @return array<int, int> */
    private function historyCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table(self::REVIEW_TABLE)
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as review_count')
            ->get()
            ->mapWithKeys(static fn(object $row): array => [(int) $row->user_id => (int) $row->review_count])
            ->all();
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $latestIds = DB::table(self::REVIEW_TABLE)
            ->groupBy('user_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->all();
        if ($latestIds === []) {
            $summary = $this->emptySummary();
            $summary['available'] = true;
            return $summary;
        }

        $latest = SubscriptionControlCaseReview::query()->whereIn('id', $latestIds)->get();
        $newEvents = $this->newEventMetrics($latest);
        $counts = array_fill_keys(SubscriptionControlCaseReview::STATUSES, 0);
        foreach ($latest as $review) {
            $status = (string) $review->status;
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }
        $reviewed = $latest->count();
        $confirmed = $counts[SubscriptionControlCaseReview::STATUS_CONFIRMED_LEAK];
        $falsePositive = $counts[SubscriptionControlCaseReview::STATUS_FALSE_POSITIVE];

        return [
            'available' => true,
            'reviewed_users' => $reviewed,
            'status_counts' => $counts,
            'confirmed_leak_rate' => $reviewed > 0 ? round($confirmed / $reviewed, 4) : 0.0,
            'false_positive_rate' => $reviewed > 0 ? round($falsePositive / $reviewed, 4) : 0.0,
            'needs_re_review' => count($newEvents),
            'decision_history_count' => (int) DB::table(self::REVIEW_TABLE)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function eventSnapshot(int $userId): array
    {
        $cutoff = time() - (30 * 86400);
        $row = DB::table(self::EVENT_TABLE)
            ->where('user_id', $userId)
            ->where('created_at', '>=', $cutoff)
            ->selectRaw('COUNT(*) as event_count')
            ->selectRaw('MAX(created_at) as last_trigger_at')
            ->selectRaw('MAX(risk_score) as max_risk_score')
            ->selectRaw('COUNT(DISTINCT client_ip) as distinct_client_ip_count')
            ->selectRaw('COUNT(DISTINCT ua_category) as distinct_ua_count')
            ->selectRaw('COUNT(DISTINCT region) as distinct_region_count')
            ->first();

        return [
            'window_days' => 30,
            'event_count' => (int) ($row->event_count ?? 0),
            'last_trigger_at' => isset($row->last_trigger_at) ? (int) $row->last_trigger_at : null,
            'max_risk_score' => isset($row->max_risk_score) ? (int) $row->max_risk_score : null,
            'distinct_client_ip_count' => (int) ($row->distinct_client_ip_count ?? 0),
            'distinct_ua_count' => (int) ($row->distinct_ua_count ?? 0),
            'distinct_region_count' => (int) ($row->distinct_region_count ?? 0),
        ];
    }

    /** @param array<string, int|null> $newEvents @return array<string, mixed> */
    private function serialize(SubscriptionControlCaseReview $review, array $newEvents = [], int $historyCount = 1): array
    {
        $newEventCount = max(0, (int) ($newEvents['new_event_count'] ?? 0));
        return [
            'id' => (int) $review->id,
            'user_id' => (int) $review->user_id,
            'status' => (string) $review->status,
            'note' => $review->note === null ? null : (string) $review->note,
            'suspicion_score' => $review->suspicion_score === null ? null : (int) $review->suspicion_score,
            'evidence_fingerprint' => $review->evidence_fingerprint === null ? null : (string) $review->evidence_fingerprint,
            'baseline_last_trigger_at' => $review->baseline_last_trigger_at === null ? null : (int) $review->baseline_last_trigger_at,
            'reviewed_at' => (int) $review->reviewed_at,
            'admin_id' => $review->admin_id === null ? null : (int) $review->admin_id,
            'new_event_count' => $newEventCount,
            'last_new_event_at' => isset($newEvents['last_new_event_at']) ? (int) $newEvents['last_new_event_at'] : null,
            'needs_re_review' => $newEventCount > 0,
            'history_count' => max(1, $historyCount),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyCalibration(): array
    {
        return [
            'available' => false,
            'version' => self::CALIBRATION_VERSION,
            'minimum_samples' => self::MIN_CALIBRATION_SAMPLES,
            'maximum_total_adjustment' => self::MAX_TOTAL_ADJUSTMENT,
            'labeled_users' => 0,
            'eligible_rule_count' => 0,
            'evidence_rules' => [],
            'affects_ranking_only' => true,
            'automatic_enforcement' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'available' => false,
            'reviewed_users' => 0,
            'status_counts' => array_fill_keys(SubscriptionControlCaseReview::STATUSES, 0),
            'confirmed_leak_rate' => 0.0,
            'false_positive_rate' => 0.0,
            'needs_re_review' => 0,
            'decision_history_count' => 0,
        ];
    }

    /** @return array<int, string> */
    private function stringList(mixed $values, int $limit): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string) $value),
            $values
        )))), 0, $limit);
    }
}

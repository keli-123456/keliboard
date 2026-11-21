<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Models\MachineRiskEvent;
use App\Models\SubscriptionControlCaseReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class MachineRiskUuidReview
{
    private const TTL = 300;
    private const REFERENCE = '/^IR-[0-9]{1,16}$/D';
    private const UUID = '/^(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})$/iD';

    public function available(): bool
    {
        return (bool) config('app.key') && Schema::hasTable('v2_subscription_control_case_review');
    }

    public function challenge(int $eventId, string $ref, int $adminId): array
    {
        $this->requireReviewer($adminId);
        $policy = $this->policy(false);
        $action = MachineRiskCredentialActions::verifiedUuidAction($policy);
        $event = MachineRiskEvent::query()->find($eventId);
        $at = time();
        $user = $this->currentCandidate($event, $ref, $at, false);
        return ['challenge' => $this->proof($event, $ref, $user, $adminId, $this->latestReviewId((int) $user->id, false),
            $at + self::TTL, bin2hex(random_bytes(16)), (int) $policy->revision, $action), 'action' => $action];
    }

    public function confirm(int $eventId, string $ref, int $adminId, string $challenge, string $digest, string $reference,
        ?string $applyAction = null): array
    {
        $this->requireReviewer($adminId);
        if (!preg_match(self::REFERENCE, $reference) || !preg_match('/^[a-f0-9]{64}$/D', $digest)
            || !preg_match('/^([1-9][0-9]{0,11})\.([a-f0-9]{32})\.[a-f0-9]{64}$/D', $challenge, $parts)) {
            throw new RuntimeException('case_evidence_changed');
        }

        $completed = null;
        $save = function () use ($eventId, $ref, $adminId, $challenge, $digest, $reference, $parts, $applyAction, &$completed): array {
            $completed = null;
            // Use the same lock order as automatic reset, including when this is an older event.
            $policy = $this->policy(true);
            $action = MachineRiskCredentialActions::verifiedUuidAction($policy);
            if ($applyAction !== null && $applyAction !== $action) throw new RuntimeException('case_evidence_changed');
            $event = MachineRiskEvent::query()->lockForUpdate()->find($eventId);
            $now = time();
            $expires = (int) $parts[1];
            if ($expires <= $now || $expires > $now + self::TTL) throw new RuntimeException('case_evidence_changed');
            $user = $this->currentCandidate($event, $ref, $now, true);
            $last = $this->latestReviewId((int) $user->id, true);
            if (!hash_equals($this->proof($event, $ref, $user, $adminId, $last, $expires, $parts[2], (int) $policy->revision, $action), $challenge)) {
                throw new RuntimeException('case_evidence_changed');
            }
            if (!hash_equals(hash('sha256', 'node-uuid-review:v1|' . $challenge . '|' . trim((string) $user->uuid)), $digest)) {
                throw new RuntimeException('node_uuid_not_matched');
            }

            // The operator attests the external finding; the panel only verifies the credential match.
            $snapshot = ['confirmed_credential_epoch' => app(MachineRiskComparison::class)->identity($user),
                'confirmed_node_uuid' => ['event_id' => $eventId, 'ref' => $ref, 'reference' => $reference, 'verified_at' => $now,
                    'source_verdict' => $event->review_verdict, 'source_reviewed_at' => $event->reviewed_at]];
            $review = SubscriptionControlCaseReview::query()->create([
                'user_id' => $user->id, 'admin_id' => $adminId, 'reviewed_at' => $now,
                'status' => SubscriptionControlCaseReview::STATUS_CONFIRMED_LEAK, 'note' => $reference,
                'evidence_snapshot' => $snapshot, 'evidence_fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            ]);
            $receipt = null;
            // Older clients only consented to saving a review, even when a reset policy is active.
            if ($applyAction !== null) {
                $receipt = app(MachineRiskCredentialActions::class)->applyVerifiedUuidReview($policy, $event, $user, $review, $applyAction);
                $snapshot['verified_uuid_action'] = $receipt;
                if (!$review->forceFill(['evidence_snapshot' => $snapshot,
                    'evidence_fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))])->save()) {
                    throw new RuntimeException('credential_review_write_failed');
                }
            }
            return $completed = ['review_id' => (int) $review->id, 'reviewed_at' => $now, 'credential_action' => $receipt];
        };

        try {
            return DB::transaction($save, 2);
        } catch (\Throwable $error) {
            // A notification can fail after commit; only a durable receipt is success in that case.
            $receipt = $completed['credential_action'] ?? null;
            if (($receipt['state'] ?? null) === 'applied' && $this->committed($eventId, $completed['review_id'], (int) $receipt['sync_revision'])) {
                return $completed + ['notification_unconfirmed' => true];
            }
            throw $error;
        }
    }

    private function committed(int $eventId, int $reviewId, int $revision): bool
    {
        try {
            $db = DB::connection();
            return $db->transactionLevel() === 0 && !$db->getPdo()->inTransaction()
                && app(MachineRiskCredentialActions::class)->committedUuidReview($eventId, $reviewId, $revision);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Called inside the credential-action transaction, after the target user has been locked. */
    public function stillValid(mixed $proof, int $reviewedAt, User $user, string $epoch): bool
    {
        if (!is_array($proof) || !is_int($proof['event_id'] ?? null) || $proof['event_id'] <= 0
            || !is_string($proof['ref'] ?? null) || !is_string($proof['reference'] ?? null)
            || !preg_match(self::REFERENCE, $proof['reference']) || ($proof['verified_at'] ?? null) !== $reviewedAt) return false;
        $event = MachineRiskEvent::query()->lockForUpdate()->find($proof['event_id']);
        $candidate = $this->candidate($event, $proof['ref'], $reviewedAt);
        return $candidate !== null && array_key_exists('source_verdict', $proof) && array_key_exists('source_reviewed_at', $proof)
            && $event->review_verdict === $proof['source_verdict'] && $event->reviewed_at === $proof['source_reviewed_at']
            && $candidate['user_id'] === (int) $user->id
            && $candidate['site_id'] === (int) ($user->site_id ?? 0) && hash_equals($epoch, $candidate['identity_epoch']);
    }

    private function requireReviewer(int $adminId): void
    {
        if ($adminId <= 0 || !$this->available()) throw new RuntimeException('node_credential_review_unavailable');
    }

    private function candidate(?MachineRiskEvent $event, string $ref, int $now): ?array
    {
        if (!$event || $event->review_verdict === 'false_positive' || $event->detected_at > $now
            || $event->detected_at < $now - MachineRiskOverlap::HISTORY || !preg_match('/^[a-f0-9]{16}$/D', $ref)) return null;
        $evidence = $event->evidence;
        $priority = $evidence['priority_review'] ?? [];
        if (($evidence['kind'] ?? null) !== 'outbound_probe_failure' || ($priority['kind'] ?? null) !== 'cross_machine_presence'
            || ($priority['state'] ?? null) !== 'matched') return null;
        foreach ($priority['accounts'] ?? [] as $lead) {
            if (($lead['ref'] ?? null) !== $ref || !in_array(true, [$lead['needs_review'] ?? null, $lead['matched'] ?? null, $lead['after_reset'] ?? null], true)) continue;
            foreach ($evidence['users'] ?? [] as $user) {
                if (($user['ref'] ?? null) === $ref && is_int($user['user_id'] ?? null) && $user['user_id'] > 0
                    && is_int($user['site_id'] ?? null) && is_string($user['identity_epoch'] ?? null)) return $user;
            }
        }
        return null;
    }

    private function currentCandidate(?MachineRiskEvent $event, string $ref, int $now, bool $lock): User
    {
        $origin = $this->candidate($event, $ref, $now);
        if (!$origin) throw new RuntimeException('case_evidence_changed');
        $query = User::query();
        $user = ($lock ? $query->lockForUpdate() : $query)->find($origin['user_id']);
        if (!$user || $user->is_admin || $user->is_staff || !$user->isActive() || !preg_match(self::UUID, trim((string) $user->uuid))
            || (int) ($user->site_id ?? 0) !== $origin['site_id']
            || !hash_equals($origin['identity_epoch'], app(MachineRiskComparison::class)->identity($user))) {
            throw new RuntimeException('case_evidence_changed');
        }
        return $user;
    }

    private function latestReviewId(int $userId, bool $lock): int
    {
        $query = SubscriptionControlCaseReview::query()->where('user_id', $userId)->orderByDesc('id');
        return (int) (($lock ? $query->lockForUpdate() : $query)->first(['id'])?->id ?? 0);
    }

    private function policy(bool $lock): object
    {
        $query = DB::table('v2_machine_risk_policy')->where('id', 1);
        $policy = ($lock ? $query->lockForUpdate() : $query)->first(['config', 'revision', 'updated_by']);
        if (!$policy) throw new RuntimeException('case_evidence_changed');
        return $policy;
    }

    private function proof(MachineRiskEvent $event, string $ref, User $user, int $adminId, int $lastReviewId, int $expires,
        string $nonce, int $revision, ?string $action): string
    {
        $message = ['node-uuid-review:v2', (int) $event->id, $event->detected_at, $event->review_verdict, $event->reviewed_at,
            $ref, app(MachineRiskComparison::class)->identity($user), $adminId, $lastReviewId, $expires, $nonce, $revision, $action];
        return $expires . '.' . $nonce . '.' . hash_hmac('sha256', json_encode($message, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}

<?php

namespace App\Services;

use App\Models\CommissionLog;
use App\Models\MessageDispatchLog;
use App\Models\Order;
use App\Models\SpamRegistrationCandidate;
use App\Models\StatUser;
use App\Models\TrafficResetLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class SpamRegistrationService
{
    public const MIN_REGISTRATION_AGE_DAYS = 7;
    public const FAILURE_LOOKBACK_DAYS = 30;

    private MessageDispatchService $dispatchService;

    public function __construct(MessageDispatchService $dispatchService)
    {
        $this->dispatchService = $dispatchService;
    }

    public function scanCandidates(): array
    {
        $health = $this->dispatchService->getProviderHealth();
        $summary = [
            'health_status' => $health['status'],
            'scanned' => 0,
            'marked' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        if ($health['status'] !== MessageDispatchLog::HEALTH_HEALTHY) {
            return $summary;
        }

        $cutoff = CarbonImmutable::now()->subDays(self::MIN_REGISTRATION_AGE_DAYS)->timestamp;

        User::query()
            ->whereNotNull('email')
            ->where('created_at', '<=', $cutoff)
            ->chunkById(200, function ($users) use (&$summary, $health): void {
                foreach ($users as $user) {
                    $summary['scanned']++;
                    $result = $this->evaluateAndMarkUser($user, $health['status']);
                    $summary[$result]++;
                }
            });

        return $summary;
    }

    public function evaluateAndMarkUser(User $user, string $healthStatus): string
    {
        $snapshot = $this->buildEvaluationSnapshot($user, $healthStatus);
        $existing = SpamRegistrationCandidate::query()->where('user_id', $user->id)->first();

        if (!$snapshot['is_candidate']) {
            if ($existing) {
                $existing->update([
                    'last_evaluated_at' => time(),
                    'provider_health_status' => $healthStatus,
                    'evaluation_snapshot' => $snapshot,
                    'reason_summary' => $snapshot['reason_summary'],
                    'reason_codes' => $snapshot['reason_codes'],
                    'last_failure_classification' => $snapshot['last_failure_classification'],
                    'last_email_log_id' => $snapshot['last_email_log_id'],
                ]);
                return 'updated';
            }
            return 'skipped';
        }

        if ($existing && in_array($existing->status, [
            SpamRegistrationCandidate::STATUS_PRESERVED,
            SpamRegistrationCandidate::STATUS_RESTORED,
            SpamRegistrationCandidate::STATUS_SOFT_DELETED,
        ], true)) {
            $existing->update([
                'last_evaluated_at' => time(),
                'provider_health_status' => $healthStatus,
                'evaluation_snapshot' => $snapshot,
                'reason_summary' => $snapshot['reason_summary'],
                'reason_codes' => $snapshot['reason_codes'],
                'last_failure_classification' => $snapshot['last_failure_classification'],
                'last_email_log_id' => $snapshot['last_email_log_id'],
            ]);
            return 'updated';
        }

        if (!$existing) {
            $existing = SpamRegistrationCandidate::create([
                'user_id' => $user->id,
                'status' => SpamRegistrationCandidate::STATUS_CANDIDATE,
                'candidate_since' => time(),
            ]);
            $markedResult = 'marked';
        } else {
            $markedResult = 'updated';
        }

        [$freezeApplied, $isLoginFrozen] = $this->freezeUserIfNeeded($user);

        $existing->update([
            'status' => SpamRegistrationCandidate::STATUS_CANDIDATE,
            'freeze_applied' => $freezeApplied,
            'is_login_frozen' => $isLoginFrozen,
            'last_evaluated_at' => time(),
            'provider_health_status' => $healthStatus,
            'evaluation_snapshot' => $snapshot,
            'reason_summary' => $snapshot['reason_summary'],
            'reason_codes' => $snapshot['reason_codes'],
            'last_failure_classification' => $snapshot['last_failure_classification'],
            'last_email_log_id' => $snapshot['last_email_log_id'],
        ]);

        return $markedResult;
    }

    public function getEvaluationSnapshot(User $user): array
    {
        return $this->buildEvaluationSnapshot($user, $this->dispatchService->getProviderHealth()['status']);
    }

    public function preserveCandidate(SpamRegistrationCandidate $candidate, ?int $adminId = null, ?string $note = null): SpamRegistrationCandidate
    {
        $isLoginFrozen = $this->restoreUserIfNeeded($candidate);
        $trimmedNote = trim((string) $note);
        $candidate->update([
            'status' => SpamRegistrationCandidate::STATUS_PRESERVED,
            'manual_note' => $trimmedNote !== '' ? $trimmedNote : null,
            'noted_by_admin_id' => $trimmedNote !== '' ? $adminId : null,
            'noted_at' => $trimmedNote !== '' ? time() : null,
            'preserved_by_admin_id' => $adminId,
            'preserved_at' => time(),
            'is_login_frozen' => $isLoginFrozen,
        ]);

        return $candidate->refresh();
    }

    public function restoreCandidate(SpamRegistrationCandidate $candidate, ?int $adminId = null, ?string $note = null): SpamRegistrationCandidate
    {
        $isLoginFrozen = $this->restoreUserIfNeeded($candidate);
        $trimmedNote = trim((string) $note);
        $candidate->update([
            'status' => SpamRegistrationCandidate::STATUS_RESTORED,
            'manual_note' => $trimmedNote !== '' ? $trimmedNote : null,
            'noted_by_admin_id' => $trimmedNote !== '' ? $adminId : null,
            'noted_at' => $trimmedNote !== '' ? time() : null,
            'restored_by_admin_id' => $adminId,
            'restored_at' => time(),
            'is_login_frozen' => $isLoginFrozen,
        ]);

        return $candidate->refresh();
    }

    public function freezeCandidate(SpamRegistrationCandidate $candidate, ?int $adminId = null, ?string $note = null): SpamRegistrationCandidate
    {
        [$freezeApplied, $isLoginFrozen] = $this->freezeUserIfNeeded($candidate->user);
        $trimmedNote = trim((string) $note);
        $candidate->update([
            'status' => SpamRegistrationCandidate::STATUS_CANDIDATE,
            'manual_note' => $trimmedNote !== '' ? $trimmedNote : null,
            'noted_by_admin_id' => $trimmedNote !== '' ? $adminId : null,
            'noted_at' => $trimmedNote !== '' ? time() : null,
            'freeze_applied' => $candidate->freeze_applied || $freezeApplied,
            'is_login_frozen' => $isLoginFrozen,
        ]);

        return $candidate->refresh();
    }

    public function softDeleteCandidate(SpamRegistrationCandidate $candidate, ?int $adminId = null, ?string $note = null): SpamRegistrationCandidate
    {
        [$freezeApplied, $isLoginFrozen] = $this->freezeUserIfNeeded($candidate->user);
        $trimmedNote = trim((string) $note);
        $candidate->update([
            'status' => SpamRegistrationCandidate::STATUS_SOFT_DELETED,
            'manual_note' => $trimmedNote !== '' ? $trimmedNote : null,
            'noted_by_admin_id' => $trimmedNote !== '' ? $adminId : null,
            'noted_at' => $trimmedNote !== '' ? time() : null,
            'soft_deleted_by_admin_id' => $adminId,
            'soft_deleted_at' => time(),
            'freeze_applied' => $candidate->freeze_applied || $freezeApplied,
            'is_login_frozen' => $isLoginFrozen,
        ]);

        return $candidate->refresh();
    }

    public function saveCandidateNote(SpamRegistrationCandidate $candidate, ?string $note, ?int $adminId = null): SpamRegistrationCandidate
    {
        $trimmedNote = trim((string) $note);
        $candidate->update([
            'manual_note' => $trimmedNote !== '' ? $trimmedNote : null,
            'noted_by_admin_id' => $trimmedNote !== '' ? $adminId : null,
            'noted_at' => $trimmedNote !== '' ? time() : null,
        ]);

        return $candidate->refresh();
    }

    private function buildEvaluationSnapshot(User $user, string $healthStatus): array
    {
        $lookbackCutoff = CarbonImmutable::now()->subDays(self::FAILURE_LOOKBACK_DAYS)->timestamp;
        $lastFailure = MessageDispatchLog::query()
            ->where('channel', 'email')
            ->where('to_address', $user->email)
            ->where('status', MessageDispatchLog::STATUS_FAILED)
            ->where('failure_classification', MessageDispatchLog::FAILURE_PERMANENT)
            ->where('created_at', '>=', $lookbackCutoff)
            ->orderByDesc('id')
            ->first();

        $hasParentRelationColumn = Schema::hasColumn('v2_user', 'parent_id');

        $snapshot = [
            'registered_age_days' => (int) floor((time() - (int) $user->created_at) / 86400),
            'has_permanent_failure' => (bool) $lastFailure,
            'provider_health_status' => $healthStatus,
            'has_plan' => !empty($user->plan_id),
            'has_any_order_history' => Order::query()->where('user_id', $user->id)->exists(),
            'has_paid_orders' => Order::query()
                ->where('user_id', $user->id)
                ->where(function ($query): void {
                    $query->whereNotNull('paid_at')
                        ->orWhereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED]);
                })
                ->exists(),
            'has_balance' => (int) $user->balance > 0,
            'has_commission_balance' => (int) $user->commission_balance > 0,
            'has_tickets' => $user->tickets()->exists(),
            'has_invite_codes' => $user->codes()->exists(),
            'has_downline_users' => User::query()->where('invite_user_id', $user->id)->exists(),
            'has_commission_logs' => CommissionLog::query()
                ->where('user_id', $user->id)
                ->orWhere('invite_user_id', $user->id)
                ->exists(),
            'has_traffic_usage' => ((int) $user->u + (int) $user->d) > 0,
            'has_stat_history' => StatUser::query()->where('user_id', $user->id)->exists(),
            'has_traffic_reset_history' => TrafficResetLog::query()->where('user_id', $user->id)->exists(),
            'has_login_history' => (int) ($user->last_login_at ?? 0) > 0,
            'has_remarks' => trim((string) ($user->remarks ?? '')) !== '',
            'has_parent_relation' => $hasParentRelationColumn ? User::query()->where('parent_id', $user->id)->exists() : false,
            'last_failure_classification' => $lastFailure?->failure_classification,
            'last_email_log_id' => $lastFailure?->id,
            'last_email_failure_at' => $lastFailure?->created_at,
        ];

        $blockers = [];
        foreach ($snapshot as $key => $value) {
            if (str_starts_with($key, 'has_') && $key !== 'has_permanent_failure' && $value === true) {
                $blockers[] = $key;
            }
        }

        if ((int) $snapshot['registered_age_days'] < self::MIN_REGISTRATION_AGE_DAYS) {
            $blockers[] = 'registered_too_recent';
        }
        if ($healthStatus !== MessageDispatchLog::HEALTH_HEALTHY) {
            $blockers[] = 'provider_health_not_normal';
        }
        if (!$snapshot['has_permanent_failure']) {
            $blockers[] = 'no_permanent_email_failure';
        }

        $snapshot['blockers'] = $blockers;
        $snapshot['is_candidate'] = empty($blockers);
        $snapshot['reason_codes'] = $snapshot['is_candidate']
            ? [
                'registered_over_threshold',
                'email_permanent_failure',
                'provider_health_healthy',
                'no_plan_or_asset_relation',
            ]
            : $blockers;
        $snapshot['reason_summary'] = $snapshot['is_candidate']
            ? 'email_permanent_failure + provider_healthy + no_plan_balance_order_ticket_invite_relation_history'
            : implode(', ', $blockers);

        return $snapshot;
    }

    private function freezeUserIfNeeded(?User $user): array
    {
        if (!$user) {
            return [false, false];
        }

        if ($user->banned) {
            return [false, true];
        }

        $user->banned = true;
        $user->save();
        (new AuthService($user))->removeAllSessions();

        return [true, true];
    }

    private function restoreUserIfNeeded(SpamRegistrationCandidate $candidate): bool
    {
        $user = $candidate->user;
        if (!$user) {
            return false;
        }

        if ($candidate->freeze_applied && $user->banned) {
            $user->banned = false;
            $user->save();
        }

        return (bool) $user->banned;
    }
}

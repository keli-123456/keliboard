<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Models\MachineRiskEvent;
use App\Models\ServerMachine;
use App\Models\SubscriptionControlCaseReview;
use App\Models\User;
use App\Models\UserSyncEvent;
use App\Services\SubscriptionAiBehaviorService;
use App\Services\UserSyncService;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class MachineRiskCredentialActions
{
    private const TABLE = 'v2_machine_risk_credential_action';
    private const FRESH_SECONDS = 120;
    public const DAILY_LIMIT = 3;
    public const SYNC_GRACE = 300;

    public static function available(): bool
    {
        return Schema::hasColumns(self::TABLE, ['id', 'pre_epoch', 'user_sync_revision', 'leak_review_id', 'repeat_verdict']);
    }

    public static function ready(string $action): bool
    {
        return in_array($action, self::availableActions(), true);
    }

    public static function availableActions(): array
    {
        if (!self::available() || !Schema::hasTable('v2_subscription_control_case_review')) return [];
        return Schema::hasTable('user_sync_states') && Schema::hasTable('user_sync_events')
            ? ['reset_subscription', 'reset_uuid', 'reset_both'] : ['reset_subscription'];
    }

    private static function changesUuid(string $action): bool
    {
        return in_array($action, ['reset_uuid', 'reset_both'], true);
    }

    public static function verifiedUuidAction(object $policy): ?string
    {
        $settings = MachineRiskSettings::effective($policy->config);
        $action = $settings['overlap']['action'];
        // A link-only reset does not revoke the leaked node credential.
        return $settings['enabled'] === true && self::changesUuid($action) ? $action : null;
    }

    /** Called only after saving the verified UUID review, with the policy, event and user locked. */
    public function applyVerifiedUuidReview(object $policy, MachineRiskEvent $event, User $user,
        SubscriptionControlCaseReview $review, string $action): array
    {
        $now = time();
        $epoch = $review->evidence_snapshot['confirmed_credential_epoch'] ?? null;
        $proof = $review->evidence_snapshot['confirmed_node_uuid'] ?? [];
        $ref = is_string($proof['ref'] ?? null) ? $proof['ref'] : null;
        $receipt = ['action' => $action, 'at' => $now, 'ref' => $ref, 'sync_revision' => null, 'trigger' => 'verified_uuid_review'];
        if (self::verifiedUuidAction($policy) !== $action) return $receipt + ['state' => 'policy_changed'];
        if (!self::ready($action)) return $receipt + ['state' => 'storage_unavailable'];
        if ((int) $review->user_id !== (int) $user->id || (int) $review->admin_id <= 0
            || $review->status !== SubscriptionControlCaseReview::STATUS_CONFIRMED_LEAK || ($proof['event_id'] ?? null) !== (int) $event->id
            || $ref === null || !is_string($epoch) || !hash_equals($epoch, app(MachineRiskComparison::class)->identity($user))
            || !app(MachineRiskUuidReview::class)->stillValid($proof, $review->reviewed_at, $user, $epoch)) {
            return $receipt + ['state' => 'leak_review_required'];
        }
        if ($user->is_admin || $user->is_staff || !$user->isActive() || !app(UserSyncService::class)->computeSnapshot($user)['available']
            || trim((string) $user->token) === '' || trim((string) $user->uuid) === '') return $receipt + ['state' => 'account_changed'];

        $ledger = DB::table(self::TABLE);
        if ((clone $ledger)->where('event_id', $event->id)->exists()) return $receipt + ['state' => 'event_already_handled'];
        $account = (clone $ledger)->where('user_id', $user->id);
        if ((clone $account)->where('pre_epoch', $epoch)->exists()) return $receipt + ['state' => 'credential_epoch_handled'];
        $previousAt = $account->orderByDesc('id')->value('rotated_at');
        if ($previousAt !== null && $now <= (int) $previousAt + self::SYNC_GRACE) return $receipt + ['state' => 'reset_cooldown'];
        if ($ledger->where('rotated_at', '>=', $now - 86400)->count() >= self::DAILY_LIMIT) return $receipt + ['state' => 'daily_limit'];

        return $this->rotate($event, $user, $policy, $epoch, $action, (int) $review->id, $ref, 'verified_uuid_review');
    }

    public function receiptsFor(array $users): array
    {
        $ids = array_column($users, 'user_id');
        if ($ids === [] || !self::available()) return [];
        $latest = DB::table(self::TABLE)->whereIn('user_id', $ids)->selectRaw('MAX(id) AS receipt_id')->groupBy('user_id');
        return DB::table(self::TABLE . ' as receipt')->joinSub($latest, 'latest', 'receipt.id', '=', 'latest.receipt_id')
            ->get(['receipt.id', 'receipt.user_id', 'receipt.site_id', 'receipt.post_epoch', 'receipt.rotated_at'])->keyBy('user_id')->all();
    }

    public function repeatedAccounts(): array
    {
        if (!self::available()) return [];
        return DB::table(self::TABLE)->whereNotNull('repeat_at')->orderByDesc('repeat_at')->orderByDesc('id')->limit(50)
            ->get(['id', 'user_id', 'site_id', 'action', 'rotated_at', 'repeat_event_id', 'repeat_at', 'repeat_verdict'])
            ->map(fn ($row) => ['receipt_id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'site_id' => (int) $row->site_id, 'action' => $row->action,
                'rotated_at' => (int) $row->rotated_at, 'repeat_event_id' => (int) $row->repeat_event_id, 'repeat_at' => (int) $row->repeat_at,
                'repeat_verdict' => $row->repeat_verdict])->all();
    }

    public function committedUuidReview(int $eventId, int $reviewId, int $syncRevision): bool
    {
        return $syncRevision > 0 && DB::table(self::TABLE)->where('event_id', $eventId)->where('leak_review_id', $reviewId)
            ->where('user_sync_revision', $syncRevision)->exists();
    }

    public function reviewRepeat(int $event, string $verdict): void
    {
        if (self::available()) DB::table(self::TABLE)->where('repeat_event_id', $event)->update(['repeat_verdict' => $verdict]);
    }

    public function reconcilePending(): void
    {
        $events = MachineRiskEvent::query()->where('evidence->credential_action->state', 'pending')->orderBy('id')->limit(20)->get();
        foreach ($events as $event) $this->onIncident($event, (int) ($event->evidence['collection_context']['policy_revision'] ?? 0));
    }

    public function onIncident(MachineRiskEvent $event, int $revision): void
    {
        $priority = $event->evidence['priority_review'] ?? [];
        if (($priority['kind'] ?? null) !== 'cross_machine_presence' || ($priority['state'] ?? null) !== 'matched') return;
        try {
            if (($priority['repeated'] ?? null) === 'after_reset' && self::available()) {
                DB::transaction(function () use ($event, $priority): void {
                    $locked = MachineRiskEvent::query()->lockForUpdate()->find($event->id);
                    if ($locked) $this->rememberRepeats($locked, $priority['accounts']);
                }, 2);
            }
            if (($event->evidence['credential_action']['state'] ?? null) === 'pending') {
                DB::transaction(fn () => $this->apply((int) $event->id, $revision), 2);
            }
        } catch (\Throwable) {
            Log::warning('[MachineRisk] Credential handling requires review; check the action receipt');
            try {
                DB::transaction(function () use ($event): void {
                    $locked = MachineRiskEvent::query()->lockForUpdate()->find($event->id);
                    $intent = $locked?->evidence['credential_action'] ?? [];
                    // If the transaction committed and only its notification failed, retain the applied receipt.
                    if (($intent['state'] ?? null) === 'pending') $this->outcome($locked, $intent['action'], 'manual_review_required');
                });
            } catch (\Throwable) {
                Log::warning('[MachineRisk] Unconfirmed credential action could not be recorded');
            }
        }
    }

    private function rememberRepeats(MachineRiskEvent $event, array $accounts): void
    {
        // Keep the reappearance record even if the next credential update fails or is skipped.
        $users = array_column($event->evidence['users'] ?? [], null, 'ref');
        foreach ($accounts as $item) {
            if (($item['after_reset'] ?? false) !== true || !isset($item['reset_receipt_id'], $users[$item['ref']])) continue;
            $user = $users[$item['ref']];
            DB::table(self::TABLE)->where('id', $item['reset_receipt_id'])->where('user_id', $user['user_id'])->where('site_id', $user['site_id'])
                ->where('post_epoch', $user['identity_epoch'])->whereNull('repeat_at')
                ->update(['repeat_event_id' => $event->id, 'repeat_at' => $event->detected_at, 'repeat_verdict' => $event->review_verdict]);
        }
    }

    private function apply(int $id, int $revision): void
    {
        // Sharing this lock with policy saves serializes both consent revocation and the global rate cap.
        $policy = DB::table('v2_machine_risk_policy')->where('id', 1)->lockForUpdate()->first();
        $event = MachineRiskEvent::query()->lockForUpdate()->find($id);
        if (!$event) return;
        $evidence = $event->evidence;
        $intent = $evidence['credential_action'] ?? [];
        if (($intent['state'] ?? null) !== 'pending') return;
        $action = $intent['action'];
        $settings = MachineRiskSettings::effective($policy?->config);
        if (!$policy || (int) $policy->revision !== $revision || $settings['enabled'] !== true || $settings['overlap']['action'] !== $action
            || !in_array($action, ['reset_uuid', 'reset_subscription', 'reset_both'], true)) {
            $this->outcome($event, $action, 'policy_changed'); return;
        }
        if (($evidence['collection_context']['policy_revision'] ?? null) !== $revision
            || ($evidence['priority_review']['kind'] ?? null) !== 'cross_machine_presence'
            || $event->review_verdict === 'false_positive' || !$this->withinFreshWindow($event)) {
            $this->outcome($event, $action, 'evidence_not_current'); return;
        }
        if (!$this->covered($evidence) || ($evidence['comparison']['prior_events_capped'] ?? true)) {
            $this->outcome($event, $action, 'incomplete_coverage'); return;
        }
        $users = array_column($evidence['users'] ?? [], null, 'ref');
        $candidates = [];
        foreach ($evidence['priority_review']['accounts'] as $item) {
            if (isset($users[$item['ref']]) && (($item['matched'] ?? false) === true || ($item['after_reset'] ?? false) === true)) {
                $candidates[$item['ref']] = $item;
            }
        }

        if (count($candidates) !== 1) { $this->outcome($event, $action, 'multiple_accounts'); return; }
        $candidate = reset($candidates);
        $origin = $users[$candidate['ref']];
        $userId = (int) $origin['user_id'];
        if (!self::ready($action)) { $this->outcome($event, $action, 'storage_unavailable'); return; }
        $previous = DB::table(self::TABLE)->where('user_id', $userId)->orderByDesc('id')->first();
        $minimum = $settings['overlap']['minimum_machines'];
        if ($previous) {
            $minimum = $settings['overlap']['repeat_minimum'];
            if (!$this->repeatProofValid($candidate, $previous, $origin, $minimum)) {
                $this->outcome($event, $action, 'fresh_repeat_required', $candidate['ref']); return;
            }
        } elseif (($candidate['matched'] ?? false) !== true || ($candidate['after_reset'] ?? false) === true) {
            $this->outcome($event, $action, 'evidence_not_current'); return;
        }
        if (DB::table(self::TABLE)->where('user_id', $userId)->where('pre_epoch', $origin['identity_epoch'])->exists()) {
            $this->outcome($event, $action, 'credential_epoch_handled', $candidate['ref']); return;
        }
        if (DB::table(self::TABLE)->where('rotated_at', '>=', time() - 86400)->count() >= self::DAILY_LIMIT) {
            $this->outcome($event, $action, 'daily_limit'); return;
        }
        $user = User::query()->lockForUpdate()->find($userId);
        $sync = app(UserSyncService::class);
        $identity = app(MachineRiskComparison::class);
        if (!$user || $user->is_admin || $user->is_staff || !$user->isActive() || !$sync->computeSnapshot($user)['available']
            || trim((string) $user->token) === '' || trim((string) $user->uuid) === ''
            || !hash_equals($origin['identity_epoch'], $identity->identity($user))) {
            $this->outcome($event, $action, 'account_changed'); return;
        }
        if (($problem = $this->historyProblem($candidate, $minimum)) !== null) {
            $this->outcome($event, $action, $problem, $candidate['ref']); return;
        }
        if (($review = $this->confirmedLeak($user, $origin['identity_epoch'], $event, $previous, $settings['overlap']['history_days'])) === null) {
            $this->outcome($event, $action, 'leak_review_required', $candidate['ref']); return;
        }
        if ($previous && self::changesUuid($previous->action) && !$this->hasNodeAcknowledgements($candidate, $previous)) {
            $this->outcome($event, $action, 'node_sync_unconfirmed', $candidate['ref']); return;
        }
        if ($previous && $previous->action === 'reset_subscription' && !$this->newLinkUsed($user, $event, $previous)) {
            $this->outcome($event, $action, 'new_link_unconfirmed', $candidate['ref']); return;
        }
        if (!$this->stillFailing($event)) {
            $this->outcome($event, $action, 'evidence_not_current'); return;
        }

        $this->rotate($event, $user, $policy, $origin['identity_epoch'], $action, $review, $candidate['ref']);
    }

    private function rotate(MachineRiskEvent $event, User $user, object $policy, string $epoch, string $action, int $review,
        string $ref, ?string $trigger = null): array
    {
        $sync = app(UserSyncService::class);
        // UUID updates and the normal node-user delta must commit together.
        if (self::changesUuid($action)) {
            $sync->syncUser($user, 'node_risk_pre_reset');
            $user->uuid = Helper::guid(true);
        }
        if ($action === 'reset_subscription' || $action === 'reset_both') $user->token = Helper::guid();
        if (!$user->saveQuietly()) throw new RuntimeException('credential_write_failed');
        $syncRevision = null;
        if (self::changesUuid($action)) {
            $sync->syncUser($user, 'node_risk_reset');
            $syncRevision = UserSyncEvent::query()->where('user_id', $user->id)->where('uuid', (string) $user->uuid)
                ->where('available', true)->orderByDesc('id')->value('id');
            if (!$syncRevision) throw new RuntimeException('credential_sync_revision_missing');
        }
        DB::table(self::TABLE)->insert(['user_id' => $user->id, 'site_id' => (int) ($user->site_id ?? 0), 'event_id' => $event->id,
            'policy_revision' => (int) $policy->revision, 'authorized_by' => $policy->updated_by, 'action' => $action, 'leak_review_id' => $review,
            'user_sync_revision' => $syncRevision, 'pre_epoch' => $epoch, 'post_epoch' => app(MachineRiskComparison::class)->identity($user), 'rotated_at' => time()]);
        return $this->outcome($event, $action, 'applied', $ref, $syncRevision !== null ? (int) $syncRevision : null, $trigger);
    }

    private function repeatProofValid(array $candidate, object $previous, array $origin, int $minimum): bool
    {
        if (($candidate['after_reset'] ?? false) !== true || ($candidate['reset_receipt_id'] ?? null) !== (int) $previous->id
            || (int) $previous->site_id !== (int) $origin['site_id'] || !hash_equals($previous->post_epoch, $origin['identity_epoch'])
            || count($candidate['occurrences']) !== $minimum) return false;
        $boundary = (int) $previous->rotated_at + self::SYNC_GRACE;
        foreach ($candidate['occurrences'] as $hit) {
            if (!is_int($hit['active_at'] ?? null) || $hit['active_at'] <= $boundary) return false;
        }
        return true;
    }

    private function covered(array $evidence): bool
    {
        $coverage = $evidence['activity'] ?? [];
        return ($coverage['action_coverage'] ?? false) === true && ($coverage['capped'] ?? true) === false
            && ($coverage['users_capped'] ?? true) === false;
    }

    private function historyProblem(array $candidate, int $minimum): ?string
    {
        $ids = array_filter(array_column($candidate['occurrences'], 'event_id'), fn ($id) => is_int($id) && $id > 0);
        if (count($ids) !== $minimum - 1) return 'evidence_not_current';
        $events = MachineRiskEvent::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'review_verdict', 'evidence']);
        if ($events->count() !== $minimum - 1 || $events->contains('review_verdict', 'false_positive')) return 'evidence_not_current';
        return $events->every(fn ($event) => $this->covered($event->evidence)) ? null : 'incomplete_coverage';
    }

    private function confirmedLeak(User $user, string $epoch, MachineRiskEvent $event, ?object $previous, int $historyDays): ?int
    {
        $review = SubscriptionControlCaseReview::query()->where('user_id', $user->id)->orderByDesc('id')->lockForUpdate()->first();
        if (!$review || $review->status !== SubscriptionControlCaseReview::STATUS_CONFIRMED_LEAK || $review->admin_id < 1
            || $review->reviewed_at > $event->detected_at || $review->reviewed_at < $event->detected_at - $historyDays * 86400
            || ($previous && $review->reviewed_at <= (int) $previous->rotated_at + self::SYNC_GRACE)) return null;
        $verifiedEpoch = $review->evidence_snapshot['confirmed_credential_epoch'] ?? null;
        if (!is_string($verifiedEpoch) || !hash_equals($epoch, $verifiedEpoch)) return null;
        $nodeProof = $review->evidence_snapshot['confirmed_node_uuid'] ?? null;
        if ($nodeProof !== null) {
            return $review->reviewed_at < $event->detected_at
                && app(MachineRiskUuidReview::class)->stillValid($nodeProof, $review->reviewed_at, $user, $epoch) ? (int) $review->id : null;
        }
        $observed = $review->evidence_snapshot['confirmed_credential_event'] ?? [];
        $at = $observed['created_at'] ?? null;
        return is_int($at) && $at <= $review->reviewed_at && (!$previous || $at > (int) $previous->rotated_at)
            && (int) ($observed['id'] ?? 0) > 0 ? (int) $review->id : null;
    }

    private function hasNodeAcknowledgements(array $candidate, object $previous): bool
    {
        $revision = (int) $previous->user_sync_revision;
        if ($revision < 1) return false;
        foreach ($candidate['occurrences'] as $hit) {
            $ack = $hit['node_sync'] ?? [];
            if (!is_array($ack) || !MachineRiskNodeSync::validReceipt($ack, (int) ($hit['active_at'] ?? 0))
                || $ack['revision'] < $revision || $ack['sampled_at'] <= (int) $previous->rotated_at
                || $ack['sampled_at'] >= (int) ($hit['first_failure_at'] ?? 0)) return false;
        }
        return true;
    }

    private function newLinkUsed(User $user, MachineRiskEvent $event, object $previous): bool
    {
        return Schema::hasTable(SubscriptionRenderEvidence::TABLE) && DB::table(SubscriptionRenderEvidence::TABLE)
            ->where('user_id', $user->id)->where('site_id', (int) ($user->site_id ?? 0))
            ->where('credential_hash', app(SubscriptionAiBehaviorService::class)->credential($user))
            ->where('generated_at', '>', (int) $previous->rotated_at + self::SYNC_GRACE)
            ->where('generated_at', '<', $event->first_failure_at)->exists();
    }

    private function withinFreshWindow(MachineRiskEvent $event): bool
    {
        $now = time();
        return $event->status === 'open' && $event->detected_at <= $now && $event->detected_at >= $now - self::FRESH_SECONDS;
    }

    private function stillFailing(MachineRiskEvent $event): bool
    {
        $now = time();
        if (!$this->withinFreshWindow($event)) return false;
        $machine = ServerMachine::query()->lockForUpdate()->find($event->machine_id);
        if (!$machine || !$machine->is_active || !config('app.key') || app(MachineIdentityGuard::class)->conflicted((int) $machine->id)
            || !hash_equals($event->authority_hash, app(MachineRiskEventService::class)->authority($machine))
            || $event->probe_revision !== app(MachineEntryProbe::class)->configuration()['revision']) return false;
        $probe = app(MachineTelemetryService::class)->snapshot($machine)['entry_probe'];
        return $probe['status'] === 'suspected_blocked' && is_int($probe['checked_at']) && $probe['checked_at'] <= $now
            && $probe['checked_at'] >= $event->detected_at && ($probe['valid_until'] ?? 0) >= $now && $probe['unknown_targets'] === 0;
    }

    private function outcome(MachineRiskEvent $event, string $action, string $state, ?string $ref = null, ?int $syncRevision = null, ?string $trigger = null): array
    {
        $evidence = $event->evidence;
        $evidence['credential_action'] = ['action' => $action, 'state' => $state, 'at' => time(), 'ref' => $ref, 'sync_revision' => $syncRevision];
        if ($trigger !== null) $evidence['credential_action']['trigger'] = $trigger;
        if (!$event->forceFill(['evidence' => $evidence])->save()) throw new RuntimeException('credential_evidence_write_failed');
        return $evidence['credential_action'];
    }
}

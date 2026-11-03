<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Jobs\RecordMachineRiskEventJob;
use App\Models\MachineRiskEvent;
use App\Models\MachineRiskInvestigation;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\SubscriptionAiBehaviorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MachineRiskEventService
{
    public function authority(ServerMachine $machine): string
    {
        return hash_hmac('sha256', $machine->id . ':' . $machine->token, (string) config('app.key'));
    }

    /** Cache-only edge detection on the existing report path. No model/SQL writes. */
    public function observe(ServerMachine $machine): void
    {
        if (!app(MachineRiskSettings::class)->enabled() || !$machine->is_active || !config('app.key')) return;
        $snapshot = app(MachineTelemetryService::class)->snapshot($machine);
        $probe = $snapshot['entry_probe'];
        if (!is_int($probe['checked_at'] ?? null) || ($probe['valid_until'] ?? 0) < time()) return;
        $config = app(MachineEntryProbe::class)->configuration();
        $revision = $config['revision'];
        $policyRevision = app(MachineRiskSettings::class)->snapshot()['revision'];
        $authority = $this->authority($machine);
        $key = 'machine_risk:edge:' . $authority . ':' . $revision . ':' . $policyRevision;
        Cache::lock($key . ':lock', 3)->get(function () use ($key, $probe, $revision, $policyRevision, $authority, $machine, $config): void {
            $state = Cache::get($key, []); $at = $probe['checked_at'];
            if ($at <= ($state['last_checked_at'] ?? 0)) return;
            if ($at - ($state['last_checked_at'] ?? $at) > $config['interval_seconds'] * 2 + 30) {
                $state['failure_rounds'] = 0; unset($state['first_failure_at']);
            }
            $state['last_checked_at'] = $at;
            if ($probe['status'] === 'reachable') {
                if (isset($state['episode'])) RecordMachineRiskEventJob::dispatch($state['episode'], $at);
                $state = ['last_checked_at' => $at, 'last_reachable_at' => $at, 'failure_rounds' => 0,
                    'last_event_at' => $state['last_event_at'] ?? null];
            } elseif ($probe['configured_targets'] > 0 && $probe['unknown_targets'] === 0
                && $probe['unreachable_targets'] === $probe['configured_targets']) {
                $state['first_failure_at'] ??= $at;
                $state['failure_rounds'] = min(2, ($state['failure_rounds'] ?? 0) + 1);
                $confirmed = ($probe['consecutive_failures'] ?? $state['failure_rounds']) >= 2;
                if ($confirmed && !isset($state['episode']) && $at - (int) ($state['last_event_at'] ?? 0) >= 300) {
                    $episode = ['machine_id' => (int) $machine->id, 'authority_hash' => $authority, 'probe_revision' => $revision, 'policy_revision' => $policyRevision,
                        'detected_at' => $at, 'first_failure_at' => $state['first_failure_at'],
                        'last_reachable_at' => $state['last_reachable_at'] ?? null,
                        'probe' => array_intersect_key($probe, array_flip(['status', 'checked_at', 'configured_targets', 'reachable_targets', 'unreachable_targets', 'unknown_targets', 'consecutive_failures']))];
                    $episode['episode_key'] = hash('sha256', $authority . ':' . $revision . ':' . $at);
                    RecordMachineRiskEventJob::dispatch($episode);
                    $state['episode'] = $episode;
                    $state['last_event_at'] = $at;
                }
            } else {
                // A tool error or incomplete round breaks failure confirmation, not an open incident.
                $state['failure_rounds'] = 0; unset($state['first_failure_at']);
            }
            Cache::put($key, $state, 21600);
        });
    }

    public function persist(array $episode, ?int $recoveredAt = null): ?MachineRiskEvent
    {
        $policy = app(MachineRiskSettings::class);
        if (!$policy->enabled() || ($episode['policy_revision'] ?? null) !== $policy->snapshot()['revision']
            || $episode['detected_at'] < time() - 1800 || $episode['detected_at'] > time() + 30) return null;
        $machine = ServerMachine::query()->find($episode['machine_id']);
        if (!$machine || !$machine->is_active || !hash_equals($this->authority($machine), $episode['authority_hash'])) return null;
        if ($episode['probe_revision'] !== app(MachineEntryProbe::class)->configuration()['revision']) return null;
        $event = MachineRiskEvent::query()->where('episode_key', $episode['episode_key'])->first();
        if (!$event) {
            $evidence = $this->evidence($machine, $episode);
            $event = DB::transaction(function () use ($episode, $machine, $recoveredAt, $evidence): MachineRiskEvent {
                $event = MachineRiskEvent::query()->firstOrCreate(['episode_key' => $episode['episode_key']], [
                'machine_id' => $machine->id, 'authority_hash' => $episode['authority_hash'], 'probe_revision' => $episode['probe_revision'],
                'status' => $recoveredAt === null ? 'open' : 'recovered', 'detected_at' => $episode['detected_at'],
                'first_failure_at' => $episode['first_failure_at'], 'last_reachable_at' => $episode['last_reachable_at'],
                'recovered_at' => $recoveredAt, 'evidence' => $evidence,
                'notification_status' => app(MachineRiskSettings::class)->settings()['notify_admin'] ? 'pending' : 'disabled',
                ]);
                if ($event->wasRecentlyCreated) app(MachineRiskInvestigationService::class)->register($event, $episode['policy_revision']);
                return $event;
            });
            if ($event->wasRecentlyCreated) {
                app(MachineRiskInvestigationService::class)->notify($event, 'opened');
                if ($recoveredAt === null && app(MachineRiskSettings::class)->settings()['auto_ai']) {
                    app(MachineRiskAnalysis::class)->request($event, true);
                }
            }
        }
        if ($recoveredAt !== null && $recoveredAt >= $event->detected_at && $event->recovered_at === null) {
            $event->forceFill(['status' => 'recovered', 'recovered_at' => $recoveredAt])->save();
            app(MachineRiskInvestigationService::class)->recordRecovery($event, $recoveredAt);
        }
        return $event;
    }

    private function evidence(ServerMachine $machine, array $episode): array
    {
        $activity = app(MachineRiskActivity::class)->forMachine((int) $machine->id, $episode['detected_at']);
        $candidates = $activity['users']; unset($activity['users']);
        $nodes = $activity['nodes']; unset($activity['nodes']);
        uasort($candidates, fn ($a, $b) => $b['last_at'] <=> $a['last_at']);
        $users = [];
        $accounts = User::query()->whereIn('id', array_slice(array_keys($candidates), 0, 128))
            ->get(['id', 'site_id', 'group_id', 'is_admin', 'is_staff', 'token', 'uuid'])->keyBy('id');
        $eligible = 0;
        foreach ($candidates as $id => $row) {
            $user = $accounts->get($id);
            if (!$user) continue;
            if ($user->is_admin || $user->is_staff) continue;
            $authorized = array_filter($row['node_ids'], fn ($id) => in_array((string) $user->group_id, $nodes[$id] ?? [], true));
            if ($authorized === []) continue;
            $eligible++;
            if (count($users) >= 20) continue;
            $ref = substr(hash_hmac('sha256', $episode['episode_key'] . ':' . $user->id, (string) config('app.key')), 0, 16);
            $users[] = ['ref' => $ref, 'user_id' => (int) $user->id, 'site_id' => (int) ($user->site_id ?? 0),
                'activity' => array_diff_key($row, ['node_ids' => true]),
                'within_5_seconds' => $row['last_at'] >= $episode['detected_at'] - 5,
                'within_5_minutes' => $row['last_at'] >= $episode['detected_at'] - 300,
                'subscription' => $this->subscription($user, array_values($authorized), $episode['detected_at'])];
        }
        $activity['related_users_lower_bound'] = $eligible;
        $activity['users_included'] = count($users);
        $activity['users_capped'] = $eligible > count($users);
        return ['mode' => 'observe_only', 'kind' => 'outbound_probe_failure', 'probe' => $episode['probe'],
            'activity' => $activity, 'users' => $users,
            'subscription_generation' => app(SubscriptionRenderEvidence::class)->forMachine((int) $machine->id, $episode['detected_at'], $episode['episode_key']),
            'entry_security' => app(MachineEntryProtection::class)->snapshot((array) $machine->load_status, time()),
            'limits' => ['outbound_icmp_is_not_independent_censorship_proof', 'connection_start_times_not_collected',
                'online_samples_may_arrive_late_or_be_missing', 'no_account_causality_from_time_correlation',
                'unauthenticated_scans_have_no_account_identity', 'high_usage_and_network_changes_can_be_legitimate',
                'credential_rotation_and_historical_node_binding_are_not_fully_measured']];
    }

    private function subscription(User $user, array $nodeIds, int $at): array
    {
        $service = app(SubscriptionAiBehaviorService::class);
        if (!$service->available()) return ['state' => 'unavailable'];
        $rows = DB::table(SubscriptionAiBehaviorService::TABLE)->where('user_id', $user->id)
            ->where('site_id', (int) ($user->site_id ?? 0))->where('credential_hash', $service->credential($user))
            ->whereBetween('bucket_at', [$at - 1800, $at])->orderBy('bucket_at')->limit(7)->get();
        $pulls = 0; $capped = false; $candidate = false; $clients = [];
        foreach ($rows as $row) {
            $sample = json_decode($row->sample, true) ?: [];
            // Never include a bucket containing observations after the incident.
            if (($sample['last_at'] ?? PHP_INT_MAX) > $at) { $capped = true; continue; }
            $pulls += max(0, (int) ($sample['pulls'] ?? 0)); $capped = $capped || ($sample['capped'] ?? false);
            foreach ((array) ($sample['clients'] ?? []) as $name => $count) {
                if (preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', (string) $name)) $clients[$name] = min(1000000, ($clients[$name] ?? 0) + max(0, (int) $count));
            }
            if (array_intersect(array_map('intval', array_keys((array) ($sample['response_nodes'] ?? []))), $nodeIds) !== []) $candidate = true;
        }
        return ['state' => $rows->isEmpty() ? 'not_observed' : 'sampled', 'pulls_lower_bound' => min(1000000, $pulls),
            'client_categories' => array_slice($clients, 0, 16, true), 'render_candidate_observed' => $candidate,
            'capped' => $capped, 'bucket_seconds' => 300, 'reference' => $this->reference($user, $at, $service)];
    }

    private function reference(User $user, int $at, SubscriptionAiBehaviorService $service): array
    {
        $rows = DB::table(SubscriptionAiBehaviorService::TABLE)->where('user_id', $user->id)
            ->where('site_id', (int) ($user->site_id ?? 0))->where('credential_hash', $service->credential($user))
            ->whereBetween('bucket_at', [$at - 86400, $at - 3600])->orderByDesc('bucket_at')->limit(48)->get(['sample', 'bucket_at']);
        $counts = [];
        foreach ($rows as $row) {
            $sample = json_decode($row->sample, true) ?: [];
            if (($sample['last_at'] ?? PHP_INT_MAX) > $at - 3600) continue;
            $counts[] = min(1000000, max(0, (int) ($sample['pulls'] ?? 0)));
        }
        return ['bucket_count' => count($counts), 'pull_counts' => $counts, 'sampled_only' => true,
            'verified_legitimate' => false, 'excludes_latest_hour' => true];
    }

    public function advance(): array
    {
        if (!app(MachineRiskSettings::class)->available()) return ['recovered' => 0];
        app(SubscriptionRenderEvidence::class)->prune();
        $timedOut = MachineRiskEvent::query()->whereIn('ai_status', ['pending', 'analyzing'])->where('ai_requested_at', '<', time() - 300)->limit(100)->get();
        foreach ($timedOut as $event) {
            if (MachineRiskEvent::query()->whereKey($event->id)->whereIn('ai_status', ['pending', 'analyzing'])->where('ai_requested_at', '<', time() - 300)
                ->update(['ai_status' => 'failed', 'ai_error_code' => 'worker_timeout', 'ai_finished_at' => time(), 'updated_at' => time()])) {
                app(MachineRiskInvestigationService::class)->notify($event->fresh(), 'report');
            }
        }
        $ids = MachineRiskEvent::query()->where('created_at', '<', time() - 7 * 86400)->limit(200)->pluck('id');
        MachineRiskInvestigation::query()->whereIn('event_id', $ids)->delete();
        MachineRiskEvent::query()->whereIn('id', $ids)->delete();
        $orphans = MachineRiskInvestigation::query()->whereDoesntHave('event')->limit(200)->pluck('event_id');
        MachineRiskInvestigation::query()->whereIn('event_id', $orphans)->delete();
        $attempts = DB::table('v2_machine_risk_ai_attempt')->where('created_at', '<', time() - 7 * 86400)->limit(200)->pluck('attempt');
        DB::table('v2_machine_risk_ai_attempt')->whereIn('attempt', $attempts)->delete();
        $followup = app(MachineRiskInvestigationService::class);
        $followup->recoverNotices();
        $result = $followup->advance();
        $result['ai_scheduled'] = app(MachineRiskSettings::class)->enabled() ? app(MachineRiskAnalysis::class)->drain() : 0;
        return $result;
    }
}

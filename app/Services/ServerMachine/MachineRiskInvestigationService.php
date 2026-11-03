<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Models\MachineRiskEvent;
use App\Models\MachineRiskInvestigation;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MachineRiskInvestigationService
{
    public const ACTIVE = ['watching', 'awaiting_telemetry', 'verifying_recovery', 'recurring'];
    public const WINDOW = 7200;
    public const CHECK_INTERVAL = 60;

    public function register(MachineRiskEvent $event, int $revision, bool $manual = false): MachineRiskInvestigation
    {
        return MachineRiskInvestigation::query()->firstOrCreate(['event_id' => $event->id], [
            'policy_revision' => $revision, 'started_at' => time(),
            'deadline_at' => ($manual ? time() : $event->detected_at) + self::WINDOW,
            'state' => $event->recovered_at === null ? 'watching' : 'verifying_recovery',
            'last_probe_at' => $event->recovered_at, 'recovery_samples' => $event->recovered_at === null ? 0 : 1,
            'next_check_at' => time(), 'notices' => [],
        ]);
    }

    public function current(MachineRiskEvent $event, MachineRiskInvestigation $investigation, ?object $policy = null, ?ServerMachine $machine = null): bool
    {
        $policy ??= DB::table('v2_machine_risk_policy')->where('id', 1)->first();
        $machine ??= ServerMachine::query()->find($event->machine_id);
        $settings = $policy ? json_decode($policy->config, true) : [];
        return $policy && ($settings['enabled'] ?? false) === true
            && ($investigation->ai_origin !== 'automatic' || ($settings['auto_ai'] ?? false) === true)
            && (int) $policy->revision === $investigation->policy_revision && $machine && $machine->is_active
            && hash_equals($event->authority_hash, app(MachineRiskEventService::class)->authority($machine))
            && $event->probe_revision === app(MachineEntryProbe::class)->configuration()['revision'];
    }

    public function observation(ServerMachine $machine): array
    {
        $snapshot = app(MachineTelemetryService::class)->snapshot($machine);
        $probe = array_intersect_key($snapshot['entry_probe'], array_flip(['status', 'checked_at', 'valid_until', 'configured_targets',
            'reachable_targets', 'unreachable_targets', 'unknown_targets', 'consecutive_failures']));
        $number = static fn($value, $max) => is_numeric($value) && !is_bool($value) && is_finite((float) $value)
            && $value >= 0 && $value <= $max ? $value + 0 : null;
        $receivedAt = $snapshot['telemetry']['received_at'];
        $receivedAt = is_int($receivedAt) && $receivedAt > 0 && $receivedAt <= time() + 5 ? $receivedAt : null;
        return ['probe' => $probe,
            'fresh_probe' => is_int($probe['checked_at'] ?? null) && $probe['checked_at'] <= time() + 5 && ($probe['valid_until'] ?? 0) >= time()
                && in_array($probe['status'], ['reachable', 'suspected_blocked'], true),
            'resources' => ['state' => $receivedAt === null ? 'never' : $snapshot['telemetry']['state'], 'observed_at' => $receivedAt,
                'cpu_percent' => $number($snapshot['load_status']['cpu'] ?? null, 100),
                'memory_used_bytes' => $number($snapshot['load_status']['mem']['used'] ?? null, 9_000_000_000_000_000),
                'memory_total_bytes' => $number($snapshot['load_status']['mem']['total'] ?? null, 9_000_000_000_000_000),
                'uptime_seconds' => $number($snapshot['load_status']['uptime'] ?? null, 9_000_000_000_000_000)]];
    }

    public function failureConfirmed(array $observation, MachineRiskEvent $event): bool
    {
        $probe = $observation['probe'] ?? [];
        return ($observation['fresh_probe'] ?? false) && ($probe['checked_at'] ?? 0) >= $event->detected_at
            && ($probe['configured_targets'] ?? 0) > 0 && ($probe['unknown_targets'] ?? 1) === 0
            && ($probe['unreachable_targets'] ?? -1) === $probe['configured_targets'] && ($observation['failure_samples'] ?? 0) >= 2;
    }

    public function withFailureSamples(MachineRiskEvent $event, MachineRiskInvestigation $row, array $observation): array
    {
        $probe = $observation['probe']; $previous = $row->observation ?? [];
        $at = $probe['checked_at'] ?? 0; $previousAt = $previous['probe']['checked_at'] ?? 0;
        $count = 0;
        if ($observation['fresh_probe'] && $at >= $event->detected_at && $at >= ($row->last_probe_at ?? 0)
            && ($probe['configured_targets'] ?? 0) > 0 && ($probe['unknown_targets'] ?? 1) === 0
            && $probe['unreachable_targets'] === $probe['configured_targets']) {
            if (is_int($probe['consecutive_failures'] ?? null)) $count = $probe['consecutive_failures'];
            elseif ($at === $previousAt) $count = $previous['failure_samples'] ?? 0;
            elseif ($at > $previousAt) {
                // Legacy agents have no counter: only distinct observed complete rounds count.
                $count = min(2, ($previous['failure_samples'] ?? 0) + 1);
                if ($at === $event->detected_at && $event->first_failure_at !== null && $event->first_failure_at < $at) $count = 2;
            }
        }
        return array_replace($observation, ['failure_samples' => min(2, max(0, $count)),
            'failure_confirmation_source' => is_int($probe['consecutive_failures'] ?? null) ? 'agent_counter' : 'panel_observed_rounds']);
    }

    public function cancel(MachineRiskEvent $event, MachineRiskInvestigation $investigation, string $reason, bool $automaticOnly = false): void
    {
        if ($automaticOnly && $investigation->ai_origin !== 'automatic') return;
        MachineRiskEvent::query()->whereKey($event->id)->whereIn('ai_status', ['waiting', 'pending', 'analyzing'])
            ->update(['ai_status' => $reason === 'queue_expired' ? 'expired' : 'cancelled', 'ai_error_code' => $reason,
                'ai_finished_at' => time(), 'updated_at' => time()]);
        $investigation->ai_next_at = null;
    }

    public function recordRecovery(MachineRiskEvent $event, int $at): void
    {
        DB::transaction(function () use ($event, $at): void {
            $policy = DB::table('v2_machine_risk_policy')->where('id', 1)->lockForUpdate()->first();
            $event = MachineRiskEvent::query()->lockForUpdate()->findOrFail($event->id);
            $row = MachineRiskInvestigation::query()->lockForUpdate()->find($event->id);
            if (!$row || !$this->current($event, $row, $policy) || !in_array($row->state, self::ACTIVE, true)
                || $at < $event->detected_at || $at <= ($row->last_probe_at ?? 0) || $at > time() + 5) return;
            $row->forceFill(['state' => 'verifying_recovery', 'last_probe_at' => $at, 'recovery_samples' => 1, 'next_check_at' => time()]);
            $this->cancel($event, $row, 'probe_recovered', true);
            $row->save();
        });
    }

    public function advance(): array
    {
        $rows = MachineRiskInvestigation::query()->with('event')->whereIn('state', self::ACTIVE)
            ->where('next_check_at', '<=', time())->orderBy('next_check_at')->limit(100)->get();
        $machines = ServerMachine::query()->whereIn('id', $rows->pluck('event.machine_id')->filter()->unique())->get()->keyBy('id');
        $observations = []; $checked = 0; $recovered = 0;
        foreach ($rows as $candidate) {
            $machine = $candidate->event ? $machines->get($candidate->event->machine_id) : null;
            if ($machine && !isset($observations[$machine->id])) $observations[$machine->id] = $this->observation($machine);
            $result = DB::transaction(function () use ($candidate, $machine, &$observations): ?array {
                $policy = DB::table('v2_machine_risk_policy')->where('id', 1)->lockForUpdate()->first();
                $event = MachineRiskEvent::query()->lockForUpdate()->find($candidate->event_id);
                $currentMachine = $event ? ServerMachine::query()->lockForUpdate()->find($event->machine_id) : null;
                $row = MachineRiskInvestigation::query()->lockForUpdate()->find($candidate->event_id);
                if (!$event || !$row || !in_array($row->state, self::ACTIVE, true) || $row->next_check_at > time()) return null;
                $firstRecovery = false;
                if (!$machine || !$currentMachine || !hash_equals($machine->token, $currentMachine->token) || !$this->current($event, $row, $policy, $currentMachine)) {
                    $row->state = 'context_changed'; $this->cancel($event, $row, 'context_changed');
                } elseif ($row->deadline_at <= time()) {
                    $row->state = 'expired'; $this->cancel($event, $row, 'queue_expired');
                } else {
                    if ($event->ai_status === 'waiting' && ($row->ai_deadline_at ?? 0) <= time()) $this->cancel($event, $row, 'queue_expired');
                    $observation = $this->withFailureSamples($event, $row, $observations[$machine->id]); $probe = $observation['probe'];
                    if (($probe['checked_at'] ?? 0) < ($row->last_probe_at ?? 0)) $observation['fresh_probe'] = false;
                    $row->observation = $observation;
                    if (!$observation['fresh_probe'] || ($probe['checked_at'] ?? 0) < $event->detected_at) {
                        $row->state = 'awaiting_telemetry'; $row->recovery_samples = 0;
                    } elseif ($probe['status'] === 'reachable') {
                        if ($event->recovered_at === null) { $event->forceFill(['status' => 'recovered', 'recovered_at' => $probe['checked_at']])->save(); $firstRecovery = true; }
                        if ($probe['checked_at'] > ($row->last_probe_at ?? 0)) $row->recovery_samples = min(2, $row->recovery_samples + 1);
                        $row->state = $row->recovery_samples >= 2 ? 'recovered' : 'verifying_recovery';
                        $this->cancel($event, $row, 'probe_recovered', true);
                    } else {
                        $row->state = $event->recovered_at === null ? 'watching' : 'recurring'; $row->recovery_samples = 0;
                    }
                    if (is_int($probe['checked_at'] ?? null)) $row->last_probe_at = max($row->last_probe_at ?? 0, $probe['checked_at']);
                }
                $row->checked_at = time(); $row->next_check_at = time() + self::CHECK_INTERVAL;
                $row->save();
                return ['state' => $row->state, 'first_recovery' => $firstRecovery];
            });
            if ($result === null) continue;
            $checked++;
            $event = $candidate->event?->fresh();
            if (!$event) continue;
            if ($result['first_recovery']) $recovered++;
            if ($result['state'] === 'recovered') $this->notify($event, 'recovered');
            if ($result['state'] === 'expired') $this->notify($event, 'expired');
            if (in_array($event->ai_status, ['completed', 'failed'], true)) $this->notify($event, 'report');
        }
        return ['checked' => $checked, 'recovered' => $recovered];
    }

    public function notify(MachineRiskEvent $event, string $phase): void
    {
        try { $this->deliverNotice($event, $phase); }
        catch (\Throwable) { Log::warning('[MachineRisk] Notice state unavailable; transport delivery is not automatically retried'); }
    }

    private function deliverNotice(MachineRiskEvent $event, string $phase): void
    {
        if (!in_array($phase, ['opened', 'report', 'recovered', 'expired'], true)) return;
        $claimed = DB::transaction(function () use ($event, $phase): bool {
            $policy = DB::table('v2_machine_risk_policy')->where('id', 1)->lockForUpdate()->first();
            $event = MachineRiskEvent::query()->lockForUpdate()->findOrFail($event->id);
            $row = MachineRiskInvestigation::query()->lockForUpdate()->find($event->id);
            if (!$row || !$this->current($event, $row, $policy)) return false;
            $notices = $row->notices ?? [];
            if (isset($notices[$phase])) return false;
            $settings = json_decode($policy->config, true);
            $allowed = ($settings['notify_admin'] ?? false) && ($phase === 'opened' || ($settings['notify_followup'] ?? false));
            $status = !$allowed ? 'disabled' : (!admin_setting('telegram_bot_enable', false) ? 'panel_only' : 'sending');
            $notices[$phase] = ['status' => $status, 'at' => time()];
            $row->notices = $notices; $row->save();
            if ($phase === 'opened') $event->forceFill(['notification_status' => $status])->save();
            return $status === 'sending';
        });
        if (!$claimed) return;
        $texts = ['opened' => 'Probe failure recorded; investigate connectivity.', 'report' => 'Investigation result is available in the panel; it may contain limitations or errors.',
            'recovered' => 'Two distinct fresh probe reports are reachable; probe recovery recheck completed. This is not a client forwarding test.',
            'expired' => 'The two-hour recheck window ended without confirmed probe recovery. Review current telemetry.'];
        // TelegramService submits fan-out jobs; it does not acknowledge recipient delivery.
        $status = 'submitted';
        try {
            app(\App\Services\TelegramService::class)->sendMessageWithAdmin("Node risk observation #{$event->id}\nMachine #{$event->machine_id}\n{$texts[$phase]}\nObserve only. No account or node action was taken.");
        } catch (\Throwable) { $status = 'failed'; }
        DB::transaction(function () use ($event, $phase, $status): void {
            $currentEvent = MachineRiskEvent::query()->lockForUpdate()->find($event->id);
            $row = MachineRiskInvestigation::query()->lockForUpdate()->find($event->id);
            if (!$row || !$currentEvent) return;
            $notices = $row->notices ?? [];
            if (($notices[$phase]['status'] ?? null) !== 'sending') return;
            $notices[$phase] = ['status' => $status, 'at' => time()]; $row->notices = $notices; $row->save();
            if ($phase === 'opened') MachineRiskEvent::query()->whereKey($event->id)->update(['notification_status' => $status]);
        });
    }

    public function recoverNotices(): void
    {
        $query = MachineRiskInvestigation::query()->where(function ($query): void {
            foreach (['opened', 'report', 'recovered', 'expired'] as $phase) {
                $query->orWhere(fn($q) => $q->where("notices->{$phase}->status", 'sending')->where("notices->{$phase}->at", '<', time() - 300));
            }
        });
        foreach ($query->orderBy('event_id')->limit(100)->get() as $candidate) {
            DB::transaction(function () use ($candidate): void {
                MachineRiskEvent::query()->lockForUpdate()->find($candidate->event_id);
                $row = MachineRiskInvestigation::query()->lockForUpdate()->find($candidate->event_id);
                if (!$row) return;
                $notices = $row->notices ?? [];
                foreach ($notices as $phase => $notice) {
                    if (($notice['status'] ?? null) === 'sending' && ($notice['at'] ?? time()) < time() - 300) {
                        $notices[$phase]['status'] = 'unknown';
                        if ($phase === 'opened') MachineRiskEvent::query()->whereKey($row->event_id)->update(['notification_status' => 'unknown']);
                    }
                }
                if ($notices !== $row->notices) $row->forceFill(['notices' => $notices])->save();
            });
        }
    }
}

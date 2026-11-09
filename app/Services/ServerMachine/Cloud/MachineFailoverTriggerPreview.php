<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverExecution;
use App\Models\MachineFailoverExecutionMember;
use App\Models\MachineFailoverMember as Member;
use App\Models\MachineFailoverObservation;
use App\Models\MachineFailoverPlan;
use App\Models\MachineFailoverService as Service;
use App\Models\MachineFailoverTriggerPreview as Preview;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// This collector reads cached evidence and finance snapshots. Only its two audit tables may be written.
class MachineFailoverTriggerPreview
{
    private const EVENTS = 'v2_machine_failover_trigger_event';
    private const REASONS = ['permission_revoked', 'config_changed', 'member_policy_changed', 'source_changed', 'account_changed',
        'dns_credential_changed', 'credential_group_changed', 'credential_health_blocked', 'source_ownership_unverified', 'task_active',
        'replacement_cooldown', 'daily_budget', 'decision_snapshot_missing', 'replacement_episode_used', 'native_episode_used',
        'native_run_active', 'cost_baseline_required', 'shared_monthly_budget', 'service_attempt_limit', 'service_reservation_limit',
        'service_parallel_limit', 'shared_account_wait', 'observation_disabled', 'sample_not_collected', 'healthy_baseline_required',
        'source_unverified', 'agent_stale', 'probe_stale', 'probe_unknown', 'probe_disabled', 'ipv4_missing', 'source_recovered',
        'member_disabled', 'member_removed', 'context_changed', 'evidence_expired', 'preview_read_failed', 'busy', 'awaiting_samples',
        'initialization_required', 'ambiguous_instances', 'probe_clock_skew', 'machine_identity_changed', 'dns_provider_unsupported',
        'unsupported_provider', 'budget_required', 'dns_not_configured', 'dns_credential_missing', 'dns_record_conflict',
        'credential_group_empty', 'group_missing', 'isolation_confirmation_required', 'enabled_member_required',
        'machine_missing', 'machine_inactive', 'machine_in_use', 'node_group_empty', 'account_missing', 'provider_creation_unsupported',
        'panel_origin_invalid', 'template_unsupported', 'shared_node_group', 'ownership_mismatch', 'credentials_required',
        'groups_migration_required', 'budget_history_unavailable', 'credential_group_daily_budget', 'credential_group_exhausted',
        'catalog_expired', 'provider_template_mismatch', 'shadow_read_expired', 'shadow_context_changed'];
    public function __construct(private MachineFailoverServiceRegistry $registry, private MachineFailoverShadow $shadow,
        private MachineFailoverBudget $budget) {}

    public function available(): bool
    {
        return Schema::hasTable('v2_machine_failover_trigger_preview') && Schema::hasTable(self::EVENTS)
            && Schema::hasTable('v2_machine_failover_execution_member') && $this->shadow->available() && $this->budget->available();
    }
    public function collect(int $limit = 5): array
    {
        $result = ['enabled' => config('machine_failover.preview_enabled') === true, 'available' => false, 'services_checked' => 0,
            'records_changed' => 0, 'events_written' => 0, 'errors' => 0, 'busy' => false] + $this->scope();
        if (!$result['enabled'] || !($result['available'] = $this->available())) return $result;
        $lock = Cache::lock('machine-failover:trigger-preview-sweep', 60);
        if (!$lock->get()) { $result['busy'] = true; return $result; }
        try {
            $rows = Service::query()->orderBy('id')->limit(50)->get(); $cursor = (int) Cache::get('machine-failover:trigger-preview-cursor', 0);
            $rows = $rows->filter(fn ($row) => $row->id > $cursor)->concat($rows->filter(fn ($row) => $row->id <= $cursor))->take(max(1, min(10, $limit)));
            $until = microtime(true) + 5;
            foreach ($rows as $service) {
                if (microtime(true) > $until) break;
                try { $this->check($service, $result); }
                catch (\Throwable $error) {
                    $result['errors']++;
                    try { $this->failed($service, $this->reason($error), $result); } catch (\Throwable) {}
                }
                $result['services_checked']++; Cache::put('machine-failover:trigger-preview-cursor', $service->id, 3600);
            }
            return $result;
        } finally { $lock->release(); }
    }
    private function check(Service $service, array &$result): void
    {
        $admin = (int) $service->getRawOriginal('updated_by'); $this->registry->actor($admin);
        $context = $this->registry->fingerprint($this->registry->snapshot($service->id, $service->revision));
        $report = $this->shadow->report($service->id, $service->revision, $admin); $now = now()->getTimestamp();
        if ($report['valid_until'] <= $now) throw new CloudException('evidence_expired');
        $cost = in_array('would_replace', array_column($report['members'], 'decision'), true)
            ? $this->budget->forecast($service->id, $service->revision, $admin) : null;
        $planned = 0; $entries = []; $active = MachineFailoverExecution::query()->where('service_id', $service->id)->where('status', 'running')->exists();
        foreach ($report['members'] as $member) {
            $reasons = $this->reasons($member['blockers']); $decision = $member['decision'] === 'would_replace' ? 'would_start' : $member['decision'];
            if ($decision === 'insufficient_evidence') $reasons[] = $this->safe($member['source']['evidence_reason']);
            if ($decision === 'awaiting_samples') $reasons[] = 'awaiting_samples';
            $episode = $this->episode($member); $price = (int) round($member['budget']['monthly_reservation_usd'] * 100); $valid = $report['valid_until'];
            if ($decision === 'would_start') {
                if (!$episode) $reasons[] = 'decision_snapshot_missing';
                if ($episode && MachineFailoverExecutionMember::query()->where('episode_key', $episode)->exists()) $reasons[] = 'native_episode_used';
                if ($active) $reasons[] = 'native_run_active';
                if (!in_array($member['candidate']['provider'] ?? null, MachineProvisionProviders::SUPPORTED, true)) $reasons[] = 'unsupported_provider';
                if (($cost['state'] ?? '') !== 'verified' || !is_int($cost['available_cents'] ?? null) || ($cost['valid_until'] ?? 0) <= $now)
                    $reasons[] = 'cost_baseline_required';
                else {
                    $valid = min($valid, $cost['valid_until']);
                    if ($price < 1) $reasons[] = 'budget_required';
                    elseif ($planned + $price > $cost['available_cents']) $reasons[] = 'shared_monthly_budget';
                }
                if ($reasons) $decision = 'blocked'; else $planned += $price;
            }
            $entries[] = ['member' => $member, 'episode' => $episode, 'decision' => $decision, 'reasons' => $this->reasons($reasons),
                'planned_cents' => $decision === 'would_start' ? $price : 0, 'checked_at' => $report['checked_at'], 'valid_until' => $valid];
        }
        $this->registry->locked(function () use ($service, $admin, $context, $entries, &$result) {
            $this->registry->actor($admin);
            if ($context !== $this->registry->fingerprint($this->registry->snapshot($service->id, $service->revision))) throw new CloudException('context_changed');
            $changes = ['records_changed' => 0, 'events_written' => 0];
            DB::transaction(function () use ($service, $context, $entries, &$changes) {
                $seen = [];
                foreach ($entries as $entry) { $seen[] = $entry['member']['member_id']; $this->record($service, $context, $entry, $changes); }
                foreach (Preview::query()->where('service_id', $service->id)->whereNull('closed_at')->whereNotIn('member_id', $seen)->lockForUpdate()->get() as $old)
                    $this->close($old, 'cancelled', Member::query()->whereKey($old->member_id)->exists() ? 'member_disabled' : 'member_removed', $changes);
            });
            foreach ($changes as $key => $count) $result[$key] += $count;
        });
    }
    private function episode(array $member): ?string
    {
        return $this->qualifiedPlan($member)?->evidence['episode_key'] ?? null;
    }
    public function qualifiedPlan(array $member): ?MachineFailoverPlan
    {
        $source = $member['source'];
        if ($source['state'] !== 'failed' || !$source['evidence_current'] || !$source['threshold_reached']) return null;
        $plan = MachineFailoverPlan::query()->where('policy_id', $member['policy_id'])->where('policy_revision', $member['policy_revision'])
            ->where('status', 'blocked')->latest('id')->first();
        $row = MachineFailoverObservation::query()->where('policy_id', $member['policy_id'])->where('policy_revision', $member['policy_revision'])->latest('id')->first();
        if (!$row || !$plan || $row->installation_id !== $source['installation_id'] || $row->checked_at !== $source['checked_at']
            || !$row->threshold_reached || !$row->first_failure_at || $row->baseline_at === null) return null;
        $expected = hash('sha256', json_encode([$member['policy_id'], $member['policy_revision'], $row->context_key, $row->baseline_at, $row->first_failure_at], JSON_THROW_ON_ERROR));
        return is_string($plan->evidence['episode_key'] ?? null) && hash_equals($expected, $plan->evidence['episode_key']) ? $plan : null;
    }
    private function record(Service $service, string $context, array $entry, array &$result): void
    {
        $member = $entry['member']; $source = $member['source']; $now = now()->getTimestamp();
        $sourceHash = hash('sha256', json_encode([$source['installation_id'], $source['ipv4']], JSON_THROW_ON_ERROR));
        $key = hash('sha256', json_encode([$service->id, $member['member_id'], $member['policy_id'], $entry['episode'] ?? 'unqualified'], JSON_THROW_ON_ERROR));
        foreach (Preview::query()->where('service_id', $service->id)->where('member_id', $member['member_id'])->whereNull('closed_at')->where('dedup_key', '!=', $key)->lockForUpdate()->get() as $old) {
            $recovered = $source['state'] === 'healthy' && $old->source_hash === $sourceHash;
            $this->close($old, isset($entry['closure_reason']) || $recovered ? 'cancelled' : ($entry['episode'] ? 'superseded' : 'expired'),
                $entry['closure_reason'] ?? ($recovered ? 'source_recovered' : ($old->source_hash !== $sourceHash ? 'source_changed' : ($entry['episode'] ? 'context_changed' : 'evidence_expired'))), $result);
        }
        $row = Preview::query()->where('dedup_key', $key)->lockForUpdate()->first(); $new = !$row;
        $row ??= new Preview(['dedup_key' => $key, 'created_at' => $now, 'revision' => 0]);
        if ($row->closed_at !== null && $row->decision === 'cancelled' && in_array('source_recovered', $row->reasons, true)) return;
        $values = ['service_id' => $service->id, 'service_revision' => $service->revision, 'member_id' => $member['member_id'],
            'policy_id' => $member['policy_id'], 'policy_revision' => $member['policy_revision'], 'machine_id' => $member['machine_id'],
            'episode_key' => $entry['episode'], 'source_hash' => $sourceHash, 'context_hash' => $context, 'decision' => $entry['decision'],
            'reasons' => $entry['reasons'], 'planned_cents' => $entry['planned_cents'], 'source_state' => $source['state']];
        $changed = $new || $row->closed_at !== null || $row->only(array_keys($values)) !== $values;
        $row->fill($values + ['source_checked_at' => $source['checked_at'], 'checked_at' => $entry['checked_at'],
            'valid_until' => $entry['valid_until'], 'closed_at' => null, 'updated_at' => $now]);
        if ($changed) $row->revision++;
        $row->save(); if ($changed) { $result['records_changed']++; $this->event($row, $result); }
    }
    private function close(Preview $row, string $decision, string $reason, array &$result): void
    {
        $now = now()->getTimestamp(); $row->fill(['decision' => $decision, 'reasons' => [$reason], 'planned_cents' => 0,
            'checked_at' => $now, 'valid_until' => $now, 'closed_at' => $now, 'updated_at' => $now, 'revision' => $row->revision + 1])->save();
        $result['records_changed']++; $this->event($row, $result);
    }
    private function event(Preview $row, array &$result): void
    {
        $fields = ['service_id', 'member_id', 'policy_id', 'machine_id', 'service_revision', 'policy_revision', 'revision',
            'decision', 'reasons', 'planned_cents', 'source_state', 'source_checked_at', 'checked_at', 'valid_until'];
        $values = $row->only($fields); $values['reasons'] = json_encode($values['reasons'], JSON_THROW_ON_ERROR);
        DB::table(self::EVENTS)->insert($values + ['preview_id' => $row->id, 'created_at' => now()->getTimestamp()]); $result['events_written']++;
    }
    private function failed(Service $original, string $reason, array &$result): void
    {
        $changes = ['records_changed' => 0, 'events_written' => 0];
        $this->registry->locked(function () use ($original, $reason, &$changes) {
          DB::transaction(function () use ($original, $reason, &$changes) {
            $service = Service::query()->find($original->id); if (!$service) return;
            $now = now()->getTimestamp(); $members = Member::query()->where('service_id', $service->id)->get();
            foreach ($members as $member) $this->record($service, hash('sha256', json_encode([$service->id, $service->revision, $reason])), [
                'member' => ['member_id' => $member->id, 'policy_id' => $member->policy_id, 'policy_revision' => $member->policy_revision,
                    'machine_id' => $member->machine_id, 'source' => ['state' => 'unknown', 'installation_id' => null, 'ipv4' => [], 'checked_at' => null]],
                'episode' => null, 'decision' => 'blocked', 'reasons' => [$member->enabled ? $reason : 'member_disabled'],
                'closure_reason' => $reason, 'planned_cents' => 0, 'checked_at' => $now, 'valid_until' => $now], $changes);
            foreach (Preview::query()->where('service_id', $service->id)->whereNull('closed_at')->whereNotIn('member_id', $members->pluck('id'))->lockForUpdate()->get() as $old)
                $this->close($old, 'cancelled', 'member_removed', $changes);
          });
        });
        foreach ($changes as $key => $count) $result[$key] += $count;
    }
    public function history(int $id, int $revision, int $admin, ?int $before = null): array
    {
        $this->registry->actor($admin); $service = Service::query()->findOrFail($id);
        if ($service->revision !== $revision) throw new CloudException('config_changed');
        $result = ['service_id' => $id, 'service_revision' => $revision, 'available' => $this->available(),
            'collector_enabled' => config('machine_failover.preview_enabled') === true, 'checked_at' => now()->getTimestamp(),
            'records' => [], 'events' => [], 'next_before_id' => null] + $this->scope();
        if (!$result['available']) return $result;
        $context = null;
        try {
            $this->registry->actor((int) $service->getRawOriginal('updated_by'));
            $context = $this->registry->fingerprint($this->registry->snapshot($id, $revision));
        } catch (CloudException) {}
        $fields = ['id', 'member_id', 'policy_id', 'machine_id', 'service_revision', 'policy_revision', 'decision', 'reasons',
            'planned_cents', 'source_state', 'source_checked_at', 'checked_at', 'valid_until', 'created_at'];
        $result['records'] = Preview::query()->where('service_id', $id)->orderByDesc('updated_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn ($row) => $row->only($fields) + ['current' => $result['collector_enabled'] && config('machine_failover.observe_enabled') === true
                && $context !== null && hash_equals($row->context_hash, $context) && $row->closed_at === null
                && $row->service_revision === $revision && $row->valid_until > now()->getTimestamp()])->all();
        $events = DB::table(self::EVENTS)->where('service_id', $id)->when($before, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(21)->get();
        $result['events'] = $events->take(20)->map(function ($row) use ($fields) {
            $data = array_intersect_key((array) $row, array_flip([...$fields, 'preview_id']));
            $data['reasons'] = json_decode($data['reasons'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($data as $key => $value) if ($value !== null && !in_array($key, ['decision', 'reasons', 'source_state'], true)) $data[$key] = (int) $value;
            return $data;
        })->all();
        if ($events->count() > 20) $result['next_before_id'] = $events[19]->id;
        return $result;
    }
    private function scope(): array
    {
        return ['mode' => 'trigger_preview', 'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false,
            'dispatch_allowed' => false, 'cloud_writes' => 0, 'writes_reserved' => false];
    }
    private function safe(string $reason): string { return in_array($reason, self::REASONS, true) ? $reason : 'preview_read_failed'; }
    private function reasons(array $reasons): array { $values = array_values(array_unique(array_map($this->safe(...), $reasons))); sort($values); return $values; }
    private function reason(\Throwable $error): string { return $error instanceof CloudException ? $this->safe($error->getMessage()) : 'preview_read_failed'; }
}

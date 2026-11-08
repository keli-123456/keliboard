<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Models\MachineRiskEvent;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\SubscriptionAiBehaviorService;
use Illuminate\Support\Facades\Cache;

/** Sampled counterexamples, never a clean population or account-causality score. */
class MachineRiskComparison
{
    public const MACHINES_PER_TICK = 8;
    public const CONTROL_USERS = 64;
    public const CONTROL_SAMPLES = 12;
    public const CONTROL_SECONDS = 3600;
    public const CONTROL_INTERVAL = 300;
    public const PRIOR_EVENTS = 100;

    public function identity(User $user): string
    {
        return hash_hmac('sha256', json_encode([(int) $user->id, (int) ($user->site_id ?? 0), (string) $user->group_id,
            app(SubscriptionAiBehaviorService::class)->credential($user)], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function context(ServerMachine $machine, int $revision, ?string $probeRevision = null): string
    {
        return hash_hmac('sha256', json_encode([app(MachineRiskEventService::class)->authority($machine), $revision,
            $probeRevision ?? app(MachineEntryProbe::class)->configuration()['revision']], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function key(ServerMachine $machine, int $revision, ?string $probeRevision = null): string
    {
        return 'machine_risk:controls:v1:' . $this->context($machine, $revision, $probeRevision);
    }

    private function indexKey(int $revision, ?string $probeRevision = null): string
    {
        return 'machine_risk:control_index:v1:' . hash_hmac('sha256', $revision . ':'
            . ($probeRevision ?? app(MachineEntryProbe::class)->configuration()['revision']), (string) config('app.key'));
    }

    /** Called only by the existing background risk advance command. */
    public function collect(): array
    {
        $result = ['checked' => 0, 'collected' => 0, 'errors' => 0];
        $policy = app(MachineRiskSettings::class)->snapshot();
        if (!$policy['available'] || !$policy['settings']['enabled'] || !config('app.key')) return $result;
        $collected = Cache::store('redis')->lock('machine_risk:control_collector:v1', 55)->get(function () use ($policy, $result): array {
            if (!Cache::store('redis')->add('machine_risk:control_tick:v1', true, 60)) return $result;
            $cursorKey = 'machine_risk:control_cursor:v1';
            $cursor = (int) Cache::store('redis')->get($cursorKey, 0);
            $query = fn() => ServerMachine::query()->where('is_active', true)->orderBy('id');
            $machines = $query()->where('id', '>', $cursor)->limit(self::MACHINES_PER_TICK)->get(['id', 'token', 'is_active', 'load_status']);
            if ($machines->isEmpty()) $machines = $query()->limit(self::MACHINES_PER_TICK)->get(['id', 'token', 'is_active', 'load_status']);
            foreach ($machines as $machine) {
                $result['checked']++;
                try { $result['collected'] += (int) $this->collectHealthy($machine, $policy['revision']); }
                catch (\Throwable) { $result['errors']++; }
            }
            Cache::store('redis')->put($cursorKey, (int) ($machines->last()?->id ?? 0), 600);
            return $result;
        });
        return is_array($collected) ? $collected : $result;
    }

    public function collectHealthy(ServerMachine $machine, int $revision): bool
    {
        $policy = app(MachineRiskSettings::class)->snapshot();
        if (!$policy['settings']['enabled'] || $policy['revision'] !== $revision || !$machine->is_active || !config('app.key')) return false;
        $now = time();
        $probeRevision = app(MachineEntryProbe::class)->configuration()['revision'];
        $probe = app(MachineTelemetryService::class)->snapshot($machine)['entry_probe'];
        $at = $probe['checked_at'] ?? null;
        if (($probe['status'] ?? null) !== 'reachable' || !is_int($at) || $at > $now || $at < $now - 120
            || ($probe['valid_until'] ?? 0) < $now || ($probe['reachable_targets'] ?? 0) < 1
            || $probeRevision !== app(MachineEntryProbe::class)->configuration()['revision']) return false;
        $key = $this->key($machine, $revision, $probeRevision);
        $state = (array) Cache::store('redis')->get($key, []);
        if ($at < (int) ($state['last_probe_at'] ?? 0) + self::CONTROL_INTERVAL) return false;
        $activity = app(MachineRiskActivity::class)->forMachine((int) $machine->id, $at, 120);
        if ($activity['sample_count'] < 1) return false;
        $epochs = []; $eligible = 0;
        $accounts = User::query()->whereIn('id', array_keys($activity['users']))
            ->get(['id', 'site_id', 'group_id', 'is_admin', 'is_staff', 'banned', 'token', 'uuid'])->keyBy('id');
        foreach (array_keys($activity['users']) as $id) {
            $user = $accounts->get($id);
            if (!$user) continue;
            if ($user->is_admin || $user->is_staff || $user->banned) continue;
            $online = $activity['users'][$user->id];
            $authorized = array_filter($online['node_ids'], fn($id) => in_array((string) $user->group_id, $activity['nodes'][$id] ?? [], true));
            if ($authorized === []) continue;
            $eligible++;
            if (count($epochs) < self::CONTROL_USERS) $epochs[$this->identity($user)] = true;
        }
        $rows = array_values(array_filter((array) ($state['samples'] ?? []), fn($r) => ($r['collected_at'] ?? 0) >= $now - self::CONTROL_SECONDS));
        $rows[] = ['probe_checked_at' => $at, 'collected_at' => $now, 'first_sample_at' => $activity['first_sample_at'],
            'last_sample_at' => $activity['last_sample_at'], 'sample_count' => $activity['sample_count'], 'users' => $epochs,
            'capped' => $activity['capped'] || $eligible > count($epochs)];
        $rows = array_slice($rows, -self::CONTROL_SAMPLES);
        while (strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > 65536 && count($rows) > 1) array_shift($rows);
        Cache::store('redis')->put($key, ['last_probe_at' => $at, 'samples' => $rows], self::CONTROL_SECONDS + 60);
        $indexKey = $this->indexKey($revision, $probeRevision);
        $index = array_filter((array) Cache::store('redis')->get($indexKey, []), fn($t) => $t >= $now - self::CONTROL_SECONDS);
        $index[(int) $machine->id] = $now;
        arsort($index);
        Cache::store('redis')->put($indexKey, array_slice($index, 0, 128, true), self::CONTROL_SECONDS + 60);
        return true;
    }

    public function capture(ServerMachine $machine, array $episode, array $users): array
    {
        $at = (int) $episode['detected_at']; $revision = (int) $episode['policy_revision'];
        $probeRevision = (string) $episode['probe_revision'];
        $policy = app(MachineRiskSettings::class)->snapshot();
        if (!$policy['settings']['enabled'] || $policy['revision'] !== $revision || !$machine->is_active
            || !hash_equals((string) ($episode['authority_hash'] ?? ''), app(MachineRiskEventService::class)->authority($machine))
            || $probeRevision !== app(MachineEntryProbe::class)->configuration()['revision']) {
            return ['schema' => 1, 'state' => 'unavailable', 'users' => [], 'absence_is_evidence' => false];
        }
        $index = (array) Cache::store('redis')->get($this->indexKey($revision, $probeRevision), []);
        $ids = array_slice(array_keys($index), 0, 32);
        if (!in_array((int) $machine->id, $ids, true)) $ids[] = (int) $machine->id;
        $controls = ServerMachine::query()->where('is_active', true)->whereIn('id', $ids)->get(['id', 'token', 'is_active']);
        $same = []; $others = []; $capped = count($index) > 32;
        foreach ($controls as $control) {
            $state = (array) Cache::store('redis')->get($this->key($control, $revision, $probeRevision), []);
            foreach ((array) ($state['samples'] ?? []) as $row) {
                if (($row['collected_at'] ?? PHP_INT_MAX) > $at || ($row['probe_checked_at'] ?? 0) > $at
                    || ($row['probe_checked_at'] ?? 0) < $at - self::CONTROL_SECONDS) continue;
                if ((int) $control->id === (int) $machine->id) {
                    if ($row['probe_checked_at'] > $at - 300) continue;
                    $same[] = $row;
                } elseif ($row['probe_checked_at'] >= $at - 300) {
                    $others[(int) $control->id] = $row;
                }
                $capped = $capped || (bool) ($row['capped'] ?? false);
            }
        }
        $prior = MachineRiskEvent::query()->where('probe_revision', $probeRevision)
            ->where('detected_at', '>=', $at - 7 * 86400)->where('detected_at', '<', $at)
            ->where('created_at', '<=', $at)
            ->orderByDesc('detected_at')->orderByDesc('id')->limit(self::PRIOR_EVENTS + 1)->get(['machine_id', 'detected_at', 'recovered_at', 'evidence']);
        $history = []; $scoped = 0; $unscoped = 0;
        foreach ($prior->take(self::PRIOR_EVENTS) as $event) {
            $evidence = $event->evidence;
            if (($evidence['collection_context']['policy_revision'] ?? null) !== $revision) { $unscoped++; continue; }
            $scoped++;
            foreach ((array) ($evidence['users'] ?? []) as $row) {
                $epoch = $row['identity_epoch'] ?? null;
                if (!is_string($epoch) || !preg_match('/^[a-f0-9]{64}$/D', $epoch)) continue;
                $h = $history[$epoch] ?? ['events' => 0, 'recovered_events' => 0, 'machines' => []];
                $h['events']++; $h['recovered_events'] += (int) ($event->recovered_at !== null && $event->recovered_at <= $at);
                $h['machines'][(int) $event->machine_id] = true; $history[$epoch] = $h;
            }
        }
        $items = [];
        foreach (array_slice($users, 0, 20) as $user) {
            $epoch = $user['identity_epoch'] ?? null;
            if (!is_string($epoch) || !preg_match('/^[a-f0-9]{64}$/D', $epoch)) continue;
            $h = $history[$epoch] ?? ['events' => 0, 'recovered_events' => 0, 'machines' => []];
            $presentSame = count(array_filter($same, fn($r) => ($r['users'][$epoch] ?? false) === true));
            $presentOther = count(array_filter($others, fn($r) => ($r['users'][$epoch] ?? false) === true));
            $items[] = ['ref' => $user['ref'], 'healthy_same_machine' => ['observed_windows' => count($same), 'present_windows' => $presentSame],
                'healthy_other_machines' => ['observed_machines' => count($others), 'present_machines' => $presentOther],
                'prior_incidents' => ['events' => $h['events'], 'machines' => count($h['machines']), 'recovered_events' => $h['recovered_events']],
                'account_misuse_proven' => false];
        }
        return ['schema' => 1, 'state' => 'sampled', 'collected_at' => time(), 'event_cutoff' => $at,
            'healthy_controls_max_age_seconds' => self::CONTROL_SECONDS, 'control_activity_window_seconds' => 120,
            'prior_events_inspected' => min($prior->count(), self::PRIOR_EVENTS), 'prior_events_scoped' => $scoped,
            'prior_events_unscoped' => $unscoped, 'prior_events_capped' => $prior->count() > self::PRIOR_EVENTS,
            'coverage_capped' => $capped, 'individual_ip_attribution' => false, 'credential_epoch_is_connection_auth_proof' => false, 'absence_is_evidence' => false,
            'representative_sample' => false, 'users' => $items];
    }
}

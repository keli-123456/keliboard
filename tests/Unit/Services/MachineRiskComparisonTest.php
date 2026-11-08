<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MachineRiskEvent;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\MachineEntryProbe;
use App\Services\ServerMachine\MachineRiskActivity;
use App\Services\ServerMachine\MachineRiskAnalysis;
use App\Services\ServerMachine\MachineRiskComparison;
use App\Services\ServerMachine\MachineRiskEventService;
use App\Services\ServerMachine\MachineRiskSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineRiskComparisonTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private ServerMachine $machine;
    private User $user;
    private MachineRiskComparison $comparison;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->createUserTable();
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        config(['app.key' => 'synthetic-comparison-key']);
        $this->bindTestSettings(['server_entry_probe_enable' => true, 'server_entry_probe_interval' => 5,
            'server_entry_probe_targets' => ['223.5.5.5', '119.29.29.29']]);
        (require base_path('database/migrations/2026_11_03_000001_create_machine_risk_events.php'))->up();
        (require base_path('database/migrations/2026_11_03_000003_create_machine_risk_investigations.php'))->up();
        Schema::create('v2_server_machine', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->text('load_status');
            $t->timestamp('created_at')->nullable(); $t->timestamp('updated_at')->nullable();
        });
        Schema::create('v2_server', function (Blueprint $t): void {
            $t->id(); $t->string('type'); $t->string('runtime')->default('generic'); $t->integer('machine_id'); $t->text('group_ids'); $t->boolean('enabled');
            $t->timestamp('created_at')->nullable(); $t->timestamp('updated_at')->nullable();
        });
        $this->machine = $this->machine();
        $this->user = User::query()->create(['site_id' => 2, 'group_id' => 1, 'token' => 'private-subscription-token',
            'uuid' => 'private-node-identity', 'email' => 'private@example.test']);
        $this->comparison = app(MachineRiskComparison::class);
        $p = app(MachineRiskSettings::class);
        $p->save(array_replace(MachineRiskSettings::DEFAULTS, ['enabled' => true]), 1, 1, true);
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::query()->create(['name' => 'Synthetic machine', 'token' => bin2hex(random_bytes(16)),
            'is_active' => true, 'load_status' => []]);
    }

    private function online(ServerMachine $machine, array $users, ?int $at = null): void
    {
        $node = Server::query()->firstOrCreate(['machine_id' => $machine->id], ['type' => 'hysteria', 'enabled' => true, 'group_ids' => ['1']]);
        app(MachineRiskActivity::class)->record('hysteria', $node->id,
            ['users' => $users, 'total_users' => count($users)], $at ?? time() - 20);
    }

    private function probe(ServerMachine $machine, string $state = 'reachable', ?int $at = null): void
    {
        $c = app(MachineEntryProbe::class)->configuration();
        $machine->forceFill(['load_status' => ['updated_at' => time(), 'entry_probe' => [
            'schema' => 1, 'revision' => $c['revision'], 'checked_at' => $at ?? time() - 5, 'state' => 'ready',
            'consecutive_failures' => $state === 'reachable' ? 0 : 2,
            'targets' => array_map(fn($t) => ['id' => $t['id'], 'status' => $state === 'reachable' ? 'reachable' : 'unreachable'], $c['targets']),
        ]]])->save();
    }

    private function control(ServerMachine $machine, int $age = 600, ?User $user = null): void
    {
        $user ??= $this->user;
        $this->online($machine, [$user->id => 1]); $this->probe($machine);
        $this->assertTrue($this->comparison->collectHealthy($machine, 2));
        // Simulate elapsed time without waiting or introducing a production clock override.
        $key = $this->comparison->key($machine, 2); $state = Cache::store('redis')->get($key);
        foreach (['probe_checked_at', 'collected_at', 'first_sample_at', 'last_sample_at'] as $field) $state['samples'][0][$field] -= $age;
        $state['last_probe_at'] -= $age;
        Cache::store('redis')->put($key, $state, 3660);
    }

    private function episode(): array
    {
        return ['machine_id' => $this->machine->id, 'detected_at' => time(), 'policy_revision' => 2,
            'authority_hash' => app(MachineRiskEventService::class)->authority($this->machine),
            'probe_revision' => app(MachineEntryProbe::class)->configuration()['revision']];
    }

    private function candidate(): array
    {
        return ['ref' => 'opaque-current-account', 'identity_epoch' => $this->comparison->identity($this->user)];
    }

    private function prior(array $extra = []): MachineRiskEvent
    {
        return MachineRiskEvent::query()->create(array_replace([
            'machine_id' => $this->machine->id, 'episode_key' => hash('sha256', random_bytes(16)),
            'authority_hash' => app(MachineRiskEventService::class)->authority($this->machine),
            'probe_revision' => app(MachineEntryProbe::class)->configuration()['revision'], 'status' => 'recovered',
            'detected_at' => time() - 1200, 'recovered_at' => time() - 900, 'created_at' => time() - 1200,
            'evidence' => ['collection_context' => ['schema' => 1, 'policy_revision' => 2], 'users' => [$this->candidate()]],
        ], $extra));
    }

    public function test_controls_require_fresh_reachable_probe_and_nearby_online_samples(): void
    {
        $this->probe($this->machine); $this->assertFalse($this->comparison->collectHealthy($this->machine, 2));
        $this->online($this->machine, [$this->user->id => 1], time() - 250);
        $this->assertFalse($this->comparison->collectHealthy($this->machine, 2));
        $this->online($this->machine, [$this->user->id => 1]);
        $this->probe($this->machine, 'unreachable'); $this->assertFalse($this->comparison->collectHealthy($this->machine, 2));
        $this->probe($this->machine, 'reachable', time() - 200); $this->assertFalse($this->comparison->collectHealthy($this->machine, 2));
        $this->probe($this->machine, 'reachable', time() + 1); $this->assertFalse($this->comparison->collectHealthy($this->machine, 2));
        $this->probe($this->machine); $this->assertTrue($this->comparison->collectHealthy($this->machine, 2));
        $this->assertFalse($this->comparison->collectHealthy($this->machine, 2));
        Bus::assertNothingDispatched();
    }

    public function test_collector_is_round_robin_bounded_and_at_most_once_per_shared_minute(): void
    {
        for ($i = 0; $i < 9; $i++) $this->machine();
        $this->assertSame(8, $this->comparison->collect()['checked']);
        $this->assertSame(0, $this->comparison->collect()['checked']);
        Cache::store('redis')->forget('machine_risk:control_tick:v1');
        $this->assertSame(2, $this->comparison->collect()['checked']);
        $p = app(MachineRiskSettings::class); $p->save(MachineRiskSettings::DEFAULTS, 2, 1, true);
        Cache::store('redis')->forget('machine_risk:control_tick:v1');
        $this->assertSame(['checked' => 0, 'collected' => 0, 'errors' => 0], $this->comparison->collect());
    }

    public function test_collector_lock_contention_is_a_noop_not_a_failed_or_duplicate_collection(): void
    {
        $lock = Cache::store('redis')->lock('machine_risk:control_collector:v1', 55);
        $this->assertTrue($lock->get());
        try { $this->assertSame(['checked' => 0, 'collected' => 0, 'errors' => 0], $this->comparison->collect()); }
        finally { $lock->release(); }
        $this->assertSame(1, $this->comparison->collect()['checked']);
    }

    public function test_probe_change_during_collection_does_not_relabel_old_samples_with_the_new_configuration(): void
    {
        $this->online($this->machine, [$this->user->id => 1]); $this->probe($this->machine);
        $oldKey = $this->comparison->key($this->machine, 2);
        app()->instance(\App\Services\ServerMachine\MachineTelemetryService::class,
            new class(function (): void {
                $this->bindTestSettings(['server_entry_probe_enable' => true, 'server_entry_probe_interval' => 10,
                    'server_entry_probe_targets' => ['223.5.5.5']]);
            }) extends \App\Services\ServerMachine\MachineTelemetryService {
                public function __construct(private \Closure $change) {}
                public function snapshot(ServerMachine $machine): array
                {
                    $snapshot = parent::snapshot($machine); ($this->change)(); return $snapshot;
                }
            });
        $this->assertFalse($this->comparison->collectHealthy($this->machine, 2));
        $this->assertNull(Cache::store('redis')->get($oldKey));
        $this->assertNull(Cache::store('redis')->get($this->comparison->key($this->machine, 2)));
    }

    public function test_control_storage_has_only_bounded_credential_scoped_presence_not_private_account_fields(): void
    {
        $admin = User::query()->create(['group_id' => 1, 'is_admin' => true]);
        $banned = User::query()->create(['group_id' => 1, 'banned' => true]);
        $foreign = User::query()->create(['group_id' => 2]);
        $this->online($this->machine, [$this->user->id => 1, $admin->id => 1, $banned->id => 1, $foreign->id => 1]);
        $this->probe($this->machine); $this->assertTrue($this->comparison->collectHealthy($this->machine, 2));
        $state = Cache::store('redis')->get($this->comparison->key($this->machine, 2));
        $this->assertSame([$this->comparison->identity($this->user) => true], $state['samples'][0]['users']);
        foreach (['private-subscription-token', 'private-node-identity', 'private@example.test', 'user_id', 'site_id', '223.5.5.5'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($state));
        }
        $this->assertLessThanOrEqual(65536, strlen(json_encode($state['samples'])));
    }

    public function test_healthy_same_machine_other_machine_and_prior_event_counts_reach_only_current_opaque_refs(): void
    {
        $this->control($this->machine); $other = $this->machine(); $this->control($other, 0); $this->prior();
        $result = $this->comparison->capture($this->machine, $this->episode(), [$this->candidate()]);
        $row = $result['users'][0];
        $this->assertSame(['observed_windows' => 1, 'present_windows' => 1], $row['healthy_same_machine']);
        $this->assertSame(['observed_machines' => 1, 'present_machines' => 1], $row['healthy_other_machines']);
        $this->assertSame(['events' => 1, 'machines' => 1, 'recovered_events' => 1], $row['prior_incidents']);
        $this->assertFalse($row['account_misuse_proven']); $this->assertFalse($result['individual_ip_attribution']);
        $this->assertFalse($result['credential_epoch_is_connection_auth_proof']);
        $this->assertFalse($result['absence_is_evidence']); $this->assertFalse($result['representative_sample']);
        foreach (['user_id', 'site_id', 'identity_epoch', $this->comparison->identity($this->user), 'private-subscription-token', 'private@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($result));
        }
    }

    public function test_account_credential_site_or_group_change_cannot_reuse_old_controls_or_incident_presence(): void
    {
        $this->control($this->machine); $this->prior();
        foreach ([['token' => 'rotated-token'], ['site_id' => 3], ['group_id' => 2]] as $change) {
            $this->user->forceFill($change)->save();
            $row = $this->comparison->capture($this->machine, $this->episode(), [$this->candidate()])['users'][0];
            $this->assertSame(0, $row['healthy_same_machine']['present_windows']); $this->assertSame(0, $row['prior_incidents']['events']);
            $this->assertFalse($row['account_misuse_proven']);
            $this->user->forceFill(['token' => 'private-subscription-token', 'site_id' => 2, 'group_id' => 1])->save();
        }
    }

    public function test_reporter_policy_probe_or_inactive_machine_cannot_supply_old_healthy_controls(): void
    {
        $this->control($this->machine); $oldEpisode = $this->episode();
        $this->machine->forceFill(['token' => 'rotated-machine-token'])->save();
        $this->assertSame('unavailable', $this->comparison->capture($this->machine, $oldEpisode, [$this->candidate()])['state']);
        $result = $this->comparison->capture($this->machine, $this->episode(), [$this->candidate()]);
        $this->assertSame(0, $result['users'][0]['healthy_same_machine']['observed_windows']);
        $this->assertFalse($this->comparison->collectHealthy($this->machine, 1));
        $oldEpisode = $this->episode();
        $this->bindTestSettings(['server_entry_probe_enable' => true, 'server_entry_probe_interval' => 10,
            'server_entry_probe_targets' => ['223.5.5.5']]);
        $this->assertSame('unavailable', $this->comparison->capture($this->machine, $oldEpisode, [$this->candidate()])['state']);
        $this->machine->forceFill(['is_active' => false])->save();
        $this->assertFalse($this->comparison->collectHealthy($this->machine, 2));
        $this->assertSame('unavailable', $this->comparison->capture($this->machine, $this->episode(), [$this->candidate()])['state']);
    }

    public function test_control_ring_keeps_twelve_samples_and_its_byte_budget(): void
    {
        $this->control($this->machine);
        $checkedAt = $this->machine->load_status['entry_probe']['checked_at'];
        $key = $this->comparison->key($this->machine, 2); $state = Cache::store('redis')->get($key);
        $row = $state['samples'][0]; $state['samples'] = [];
        for ($i = 12; $i >= 1; $i--) {
            $state['samples'][] = array_replace($row, ['probe_checked_at' => time() - 5 - $i * 290, 'collected_at' => time() - $i * 290]);
        }
        $state['last_probe_at'] = $checkedAt - 300;
        Cache::store('redis')->put($key, $state, 3660);
        $this->assertTrue($this->comparison->collectHealthy($this->machine, 2));
        $state = Cache::store('redis')->get($key);
        $this->assertCount(12, $state['samples']);
        $this->assertLessThanOrEqual(65536, strlen(json_encode($state['samples'])));
        $this->assertSame($checkedAt, $state['last_probe_at']);
    }

    public function test_future_late_or_old_controls_and_post_cutoff_incident_feedback_are_not_used(): void
    {
        $this->control($this->machine);
        $key = $this->comparison->key($this->machine, 2); $state = Cache::store('redis')->get($key); $good = $state['samples'][0];
        $state['samples'][] = array_replace($good, ['collected_at' => time() + 5]);
        $state['samples'][] = array_replace($good, ['probe_checked_at' => time() + 5]);
        $state['samples'][] = array_replace($good, ['probe_checked_at' => time() - 3601]);
        Cache::store('redis')->put($key, $state, 3660);
        $this->prior(['recovered_at' => time() + 600]);
        $this->prior(['created_at' => time() + 600]);
        $this->prior(['detected_at' => time() - 8 * 86400]);
        $row = $this->comparison->capture($this->machine, $this->episode(), [$this->candidate()])['users'][0];
        $this->assertSame(1, $row['healthy_same_machine']['observed_windows']);
        $this->assertSame(1, $row['prior_incidents']['events']); $this->assertSame(0, $row['prior_incidents']['recovered_events']);
    }

    public function test_legacy_and_different_policy_or_probe_history_cannot_be_backfilled_as_current_evidence(): void
    {
        $this->prior(['evidence' => ['users' => [$this->candidate()]]]);
        $this->prior(['evidence' => ['collection_context' => ['policy_revision' => 1], 'users' => [$this->candidate()]]]);
        $this->prior(['probe_revision' => str_repeat('a', 64)]);
        $r = $this->comparison->capture($this->machine, $this->episode(), [$this->candidate()]);
        $this->assertSame(2, $r['prior_events_inspected']); $this->assertSame(2, $r['prior_events_unscoped']);
        $this->assertSame(0, $r['users'][0]['prior_incidents']['events']);
    }

    public function test_history_reads_are_bounded_and_truncation_never_establishes_absence(): void
    {
        for ($i = 0; $i < 102; $i++) $this->prior();
        $r = $this->comparison->capture($this->machine, $this->episode(), [$this->candidate()]);
        $this->assertSame(100, $r['prior_events_inspected']); $this->assertTrue($r['prior_events_capped']);
        $this->assertSame(100, $r['users'][0]['prior_incidents']['events']);
        $this->assertFalse($r['absence_is_evidence']);
    }

    private function modelEvidence(): array
    {
        return ['evidence' => [['id' => 'probe', 'value' => []], ['id' => 'coverage', 'value' => []],
            ['id' => 'users', 'value' => [['ref' => 'opaque']]],
            ['id' => 'account_activity', 'value' => [['ref' => 'opaque', 'state' => 'sampled', 'observed_buckets' => 1, 'pulls_lower_bound' => 1]]],
            ['id' => 'comparison', 'value' => ['state' => 'sampled', 'users' => [['ref' => 'opaque',
                'healthy_same_machine' => ['present_windows' => 1], 'healthy_other_machines' => ['present_machines' => 0],
                'prior_incidents' => ['events' => 20]]]]]]];
    }

    private function modelReview(): array
    {
        return ['classification' => 'possible_entry_exposure', 'summary' => 'A model-only claim.', 'evidence_ids' => ['users'],
            'limitations' => array_fill(0, 8, 'Sampled only.'), 'related_users' => [['ref' => 'opaque', 'assessment' => 'review',
                'reason' => 'Investigate a measured behavior.', 'evidence_ids' => ['account_activity', 'comparison']]]];
    }

    public function test_time_only_global_or_generation_only_reviews_are_downgraded_without_provider_retries(): void
    {
        $analysis = new MachineRiskAnalysis(); $review = $this->modelReview();
        foreach ([['users'], ['probe', 'shared_security'], ['subscription_generation'], ['comparison']] as $citations) {
            $review['related_users'][0]['evidence_ids'] = $citations;
            $r = $analysis->validate($review, $this->modelEvidence());
            $this->assertSame('insufficient', $r['related_users'][0]['assessment']);
            $this->assertSame('insufficient_evidence', $r['classification']); $this->assertCount(8, $r['limitations']);
            $this->assertNotSame('A model-only claim.', $r['summary']);
        }
        Bus::assertNothingDispatched();
    }

    public function test_citations_cannot_replace_missing_measurements_or_healthy_counterexamples(): void
    {
        $analysis = new MachineRiskAnalysis();
        $evidence = $this->modelEvidence(); $evidence['evidence'][3]['value'][0]['observed_buckets'] = 0;
        $this->assertSame('insufficient', $analysis->validate($this->modelReview(), $evidence)['related_users'][0]['assessment']);
        $evidence = $this->modelEvidence(); $evidence['evidence'][4]['value']['users'][0]['healthy_same_machine']['present_windows'] = 0;
        $this->assertSame('insufficient', $analysis->validate($this->modelReview(), $evidence)['related_users'][0]['assessment']);
        $this->assertSame('review', $analysis->validate($this->modelReview(), $this->modelEvidence())['related_users'][0]['assessment']);
    }
}

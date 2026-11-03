<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\MachineRiskController;
use App\Jobs\AnalyzeMachineRiskEventJob;
use App\Jobs\RecordMachineRiskEventJob;
use App\Models\MachineRiskEvent;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\MachineEntryProbe;
use App\Services\ServerMachine\MachineRiskActivity;
use App\Services\ServerMachine\MachineRiskAnalysis;
use App\Services\ServerMachine\MachineRiskEventService;
use App\Services\ServerMachine\MachineRiskSettings;
use App\Services\SubscriptionAiBehaviorService;
use App\Services\TicketAiProviderClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineRiskTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private ServerMachine $machine;
    private Server $node;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->createUserTable(); $this->bindJsonResponseFactory();
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        config(['app.key' => 'synthetic-risk-key']);
        $this->bindTestSettings(['server_entry_probe_enable' => true, 'server_entry_probe_interval' => 5,
            'server_entry_probe_targets' => ['223.5.5.5', '119.29.29.29']]);
        (require base_path('database/migrations/2026_11_03_000001_create_machine_risk_events.php'))->up();
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active');
            $table->text('load_status'); $table->timestamp('created_at')->nullable(); $table->timestamp('updated_at')->nullable();
        });
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id(); $table->string('type'); $table->string('runtime')->default('generic'); $table->integer('machine_id'); $table->text('group_ids');
            $table->boolean('enabled'); $table->timestamp('created_at')->nullable(); $table->timestamp('updated_at')->nullable();
        });
        $this->machine = ServerMachine::query()->create(['name' => 'Synthetic server', 'token' => 'private-machine-token', 'is_active' => true, 'load_status' => []]);
        $this->node = Server::query()->create(['machine_id' => $this->machine->id, 'type' => 'hysteria', 'enabled' => true, 'group_ids' => ['1']]);
    }

    private function enable(array $overrides = []): void
    {
        $policy = app(MachineRiskSettings::class);
        $policy->save(array_replace(MachineRiskSettings::DEFAULTS, ['enabled' => true], $overrides), $policy->snapshot()['revision'], 1, true);
    }

    private function report(int $at, array $states, ?int $failures = 2, ?string $revision = null): void
    {
        $config = app(MachineEntryProbe::class)->configuration();
        $probe = ['schema' => 1, 'revision' => $revision ?? $config['revision'], 'checked_at' => $at, 'state' => 'ready',
            'targets' => array_map(fn ($target, $state) => ['id' => $target['id'], 'status' => $state], $config['targets'], $states)];
        if ($failures !== null) $probe['consecutive_failures'] = $failures;
        $this->machine->forceFill(['load_status' => ['updated_at' => time(), 'entry_probe' => $probe]])->save();
        app(MachineRiskEventService::class)->observe($this->machine);
    }

    private function episode(): array
    {
        $this->report(time() - 10, ['reachable', 'unreachable'], 0);
        $this->report(time() - 5, ['unreachable', 'unreachable'], 1);
        $this->report(time(), ['unreachable', 'unreachable']);
        return Bus::dispatched(RecordMachineRiskEventJob::class)->last()->episode;
    }

    private function event(): MachineRiskEvent
    {
        return app(MachineRiskEventService::class)->persist($this->episode());
    }

    private function user(array $extra = []): User
    {
        return User::query()->create(array_replace(['site_id' => 2, 'group_id' => 1, 'token' => 'private-sub-token',
            'uuid' => 'private-node-uuid', 'email' => 'private@example.test'], $extra));
    }

    private function sample(array $users, ?int $at = null): void
    {
        app(MachineRiskActivity::class)->record('hysteria', $this->node->id, ['users' => $users, 'total_users' => count($users)], $at ?? time() - 10);
    }

    private function analysis(?\Closure $reply = null): MachineRiskAnalysis
    {
        $provider = new class($reply) extends TicketAiProviderClient {
            public int $calls = 0;
            public array $messages = [];
            public function __construct(private ?\Closure $reply) {}
            public function complete(array $settings, array $messages, bool $validateStructuredContent = true): array
            {
                $this->calls++; $this->messages = $messages;
                return ['content' => json_encode($this->reply ? ($this->reply)() : [
                    'classification' => 'insufficient_evidence', 'summary' => 'Evidence is insufficient.',
                    'evidence_ids' => ['probe', 'coverage'], 'limitations' => ['Sampling is incomplete.'], 'related_users' => [],
                ]), 'input_tokens' => 100, 'output_tokens' => 20];
            }
        };
        app()->instance(TicketAiProviderClient::class, $provider);
        $analysis = new class($provider) extends MachineRiskAnalysis {
            public function providerSettings(): array { return ['enabled' => true, 'api_key' => 'synthetic-key', 'base_url' => 'https://synthetic-provider.example.test/v1', 'model' => 'synthetic-risk', 'timeout' => 30]; }
        };
        app()->instance(MachineRiskAnalysis::class, $analysis);
        return $analysis;
    }

    public function test_defaults_are_off_and_activation_is_revisioned_and_explicit(): void
    {
        $policy = app(MachineRiskSettings::class);
        $this->assertFalse($policy->enabled()); $this->sample([1 => 2]); $this->report(time(), ['unreachable', 'unreachable']);
        Bus::assertNothingDispatched(); $this->assertNull(Cache::get(app(MachineRiskActivity::class)->key($this->machine->id, $this->node->id)));
        try { $policy->save(array_replace(MachineRiskSettings::DEFAULTS, ['enabled' => true]), 1, 1, false); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('confirmation_required', $error->getMessage()); }
        $this->enable(); $this->assertSame(2, $policy->snapshot()['revision']);
        try { $policy->save(MachineRiskSettings::DEFAULTS, 1, 1, true); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('policy_changed', $error->getMessage()); }
        $policy->save(array_replace(MachineRiskSettings::DEFAULTS, ['auto_ai' => true]), 2, 1, true);
        $this->assertFalse($policy->settings()['auto_ai']);
    }

    public function test_activity_is_bounded_ordered_and_does_not_store_addresses(): void
    {
        $this->enable(); $activity = app(MachineRiskActivity::class); $at = time();
        $this->sample(array_fill_keys(range(1, 70), 2), $at - 20); $this->sample([100 => 9], $at - 21);
        $key = $activity->key($this->machine->id, $this->node->id); $value = json_decode(Cache::get($key), true);
        $this->assertCount(64, $value['samples'][0]['users']); $this->assertTrue($value['samples'][0]['capped']);
        $this->sample([], $at - 10); $result = $activity->forMachine($this->machine->id, $at);
        $this->assertSame(2, $result['sample_count']); $this->assertFalse($result['exact_connection_times']);
        $this->assertSame($at - 20, $result['users'][1]['last_at']);
        $this->sample([101 => 1], $at - 301); $this->sample([102 => 1], $at + 60);
        $this->assertSame(2, $activity->forMachine($this->machine->id, $at)['sample_count']);
        Cache::forget($key);
        for ($i = 100; $i >= 30; $i--) $this->sample([1 => 1], $at - $i);
        $this->assertCount(60, json_decode(Cache::get($key), true)['samples']);
        $this->assertLessThanOrEqual(131072, strlen(Cache::get($key)));
        $this->node->forceFill(['machine_id' => 99])->save();
        $this->assertSame(0, $activity->forMachine($this->machine->id, $at)['sample_count']);
    }

    public function test_confirmed_failure_is_deduplicated_and_recovery_cannot_be_reopened_by_late_jobs(): void
    {
        $this->enable(); $episode = $this->episode(); $this->report(time(), ['unreachable', 'unreachable']);
        Bus::assertDispatchedTimes(RecordMachineRiskEventJob::class, 1);
        $service = app(MachineRiskEventService::class); $event = $service->persist($episode, time() + 1);
        $this->assertSame('recovered', $event->status); $this->assertSame($event->id, $service->persist($episode)->id);
        $this->assertSame('recovered', $event->fresh()->status); $this->assertSame(1, MachineRiskEvent::query()->count());
        $this->report(time() + 1, ['reachable', 'unreachable'], 0);
        $this->report(time() + 2, ['unreachable', 'unreachable']);
        Bus::assertDispatchedTimes(RecordMachineRiskEventJob::class, 2);
        Bus::assertDispatched(RecordMachineRiskEventJob::class, fn ($job) => $job->recoveredAt !== null);
    }

    public function test_tool_errors_partial_stale_or_wrong_revision_reports_do_not_create_incidents(): void
    {
        $this->enable();
        $this->report(time() - 1000, ['unreachable', 'unreachable']);
        $this->report(time() - 6, ['unreachable', 'error']);
        $this->report(time() - 5, ['unreachable', 'unreachable'], 1);
        $this->report(time() - 4, ['unreachable', 'unreachable'], 2, str_repeat('a', 64));
        $this->report(time() - 3, ['reachable', 'unreachable']);
        Bus::assertNothingDispatched();
    }

    public function test_legacy_reports_need_two_distinct_complete_rounds_and_incomplete_rounds_reset_confirmation(): void
    {
        $this->enable(); $this->report(time() - 4, ['unreachable', 'unreachable'], null);
        $this->report(time() - 3, ['unreachable', 'error'], null);
        $this->report(time() - 2, ['unreachable', 'unreachable'], null); Bus::assertNothingDispatched();
        $this->report(time() - 1, ['unreachable', 'unreachable'], null); Bus::assertDispatchedTimes(RecordMachineRiskEventJob::class, 1);
    }

    public function test_policy_and_reporter_revocation_discard_queued_evidence(): void
    {
        $this->enable(); $episode = $this->episode(); $this->enable();
        $this->assertNull(app(MachineRiskEventService::class)->persist($episode));
        $episode['policy_revision'] = app(MachineRiskSettings::class)->snapshot()['revision'];
        $this->machine->forceFill(['token' => 'rotated'])->save();
        $this->assertNull(app(MachineRiskEventService::class)->persist($episode));
    }

    public function test_candidates_are_currently_authorized_ordinary_accounts_and_not_exact_connection_events(): void
    {
        $this->enable(); $own = $this->user(); $other = $this->user(['group_id' => 2]); $admin = $this->user(['is_admin' => true]);
        $this->sample([$own->id => 2, $other->id => 10, $admin->id => 10]);
        $event = $this->event(); $rows = $event->evidence['users'];
        $this->assertCount(1, $rows); $this->assertSame($own->id, $rows[0]['user_id']);
        $this->assertFalse($rows[0]['within_5_seconds']); $this->assertTrue($rows[0]['within_5_minutes']);
        $this->assertSame(1, $event->evidence['activity']['related_users_lower_bound']);
        $prompt = json_encode($this->analysis()->promptEvidence($event));
        foreach (['private@example.test', 'private-sub-token', 'private-node-uuid', 'private-machine-token', '223.5.5.5', 'user_id', 'site_id'] as $private) $this->assertStringNotContainsString($private, $prompt);
        $this->assertStringContainsString('connection_start_times_not_collected', $prompt);
        $this->assertFalse($own->fresh()->banned);
    }

    public function test_existing_online_job_records_report_time_without_retaining_raw_addresses_and_is_failure_isolated(): void
    {
        $this->enable(); $user = $this->user();
        Schema::table('v2_user', function (Blueprint $table): void { $table->integer('online_count')->default(0); $table->timestamp('last_online_at')->nullable(); });
        $at = microtime(true) - 20;
        (new \App\Jobs\UpdateAliveDataJob([$user->id => ['192.0.2.101', '192.0.2.102']], 'hysteria', $this->node->id, $at))->handle();
        $key = app(MachineRiskActivity::class)->key($this->machine->id, $this->node->id); $value = Cache::get($key);
        $this->assertIsString($value); $this->assertStringNotContainsString('192.0.2.', $value);
        $this->assertSame((int) $at, json_decode($value, true)['latest_at']);
        (new \App\Jobs\UpdateAliveDataJob([$user->id => ['192.0.2.103']], 'hysteria', $this->node->id, $at - 1))->handle();
        $this->assertSame($value, Cache::get($key));
        app()->instance(MachineRiskActivity::class, new class extends MachineRiskActivity {
            public function record(string $nodeType, int $nodeId, array $sample, float $reportedAt): void { throw new \RuntimeException('Synthetic cache outage'); }
        });
        (new \App\Jobs\UpdateAliveDataJob([], 'hysteria', $this->node->id, $at + 1))->handle();
        $this->assertSame(0, $user->fresh()->online_count);
    }

    public function test_subscription_evidence_is_credential_site_and_time_scoped(): void
    {
        $this->enable(); $user = $this->user(); $this->sample([$user->id => 1]); $now = time();
        Schema::create(SubscriptionAiBehaviorService::TABLE, function (Blueprint $table): void {
            $table->id(); $table->integer('user_id'); $table->integer('site_id'); $table->string('credential_hash');
            $table->integer('bucket_at'); $table->text('sample');
        });
        Schema::create('v2_subscription_ai_policy_version', fn (Blueprint $table) => $table->id());
        $credential = app(SubscriptionAiBehaviorService::class)->credential($user);
        $base = ['user_id' => $user->id, 'site_id' => 2, 'credential_hash' => $credential];
        foreach ([[-600, 3, 2, $credential, -300], [-300, 900, 2, $credential, 10], [-300, 900, 3, $credential, -10], [-300, 900, 2, 'old', -10], [-4000, 4, 2, $credential, -3700]] as [$offset, $pulls, $site, $key, $last]) {
            DB::table(SubscriptionAiBehaviorService::TABLE)->insert(array_replace($base, ['site_id' => $site, 'credential_hash' => $key, 'bucket_at' => $now + $offset,
                'sample' => json_encode(['last_at' => $now + $last, 'pulls' => $pulls, 'response_nodes' => [$this->node->id => 1], 'clients' => ['mihomo' => $pulls]])]));
        }
        $result = $this->event()->evidence['users'][0]['subscription'];
        $this->assertSame(3, $result['pulls_lower_bound']); $this->assertTrue($result['capped']);
        $this->assertTrue($result['render_candidate_observed']); $this->assertSame([4], $result['reference']['pull_counts']);
        $this->assertFalse($result['reference']['verified_legitimate']);
    }

    public function test_ai_calls_are_budgeted_once_and_duplicate_jobs_do_not_call_again(): void
    {
        $this->enable(['hourly_budget' => 1]); $event = $this->event(); $analysis = $this->analysis();
        $this->assertTrue($analysis->request($event)); $event->refresh(); $attempt = $event->ai_attempt;
        $this->assertFalse($analysis->request($event));
        $analysis->process($event->id, $attempt); $analysis->process($event->id, $attempt);
        $this->assertSame('completed', $event->fresh()->ai_status); $this->assertSame(1, app(TicketAiProviderClient::class)->calls);
        $event->forceFill(['ai_status' => 'failed'])->save();
        $this->assertFalse($analysis->request($event)); $this->assertSame('budget_exhausted', $event->fresh()->ai_status);
        $this->assertSame(1, DB::table('v2_machine_risk_ai_attempt')->count());
        Bus::assertDispatchedTimes(AnalyzeMachineRiskEventJob::class, 1);
    }

    public function test_in_flight_revocation_discards_the_model_response(): void
    {
        $this->enable(); $event = $this->event();
        $analysis = $this->analysis(function (): array {
            $policy = app(MachineRiskSettings::class); $policy->save(MachineRiskSettings::DEFAULTS, $policy->snapshot()['revision'], 1, true);
            return ['classification' => 'insufficient_evidence', 'summary' => 'Old result', 'evidence_ids' => [], 'limitations' => [], 'related_users' => []];
        });
        $analysis->request($event); $event->refresh(); $analysis->process($event->id, $event->ai_attempt);
        $this->assertSame('failed', $event->fresh()->ai_status); $this->assertSame('context_changed', $event->fresh()->ai_error_code);
        $this->assertNull($event->fresh()->analysis);
    }

    public function test_unrecognized_model_actions_and_account_references_are_rejected(): void
    {
        $analysis = $this->analysis(); $evidence = ['evidence' => [[], [], ['value' => [['ref' => 'opaque']]]]];
        $valid = ['classification' => 'insufficient_evidence', 'summary' => 'Insufficient', 'evidence_ids' => ['probe'], 'limitations' => [], 'related_users' => []];
        $this->assertSame($valid, $analysis->validate($valid, $evidence));
        foreach ([array_replace($valid, ['action' => 'ban']), array_replace($valid, ['summary' => 'https://untrusted.test']),
            array_replace($valid, ['evidence_ids' => [['bad']]]), array_replace($valid, ['related_users' => [['ref' => 'outsider', 'assessment' => 'review', 'reason' => 'Check', 'evidence_ids' => ['users']]]])] as $bad) {
            try { $analysis->validate($bad, $evidence); $this->fail('Unsafe output accepted'); }
            catch (\RuntimeException $error) { $this->assertSame('unsafe_analysis', $error->getMessage()); }
        }
    }

    public function test_reporter_rotation_during_inference_discards_the_result_and_failed_calls_do_not_refund_budget(): void
    {
        $this->enable(['hourly_budget' => 1]); $event = $this->event();
        $analysis = $this->analysis(function (): array {
            $this->machine->forceFill(['token' => 'rotated-reporter'])->save();
            return ['classification' => 'insufficient_evidence', 'summary' => 'Old result', 'evidence_ids' => [], 'limitations' => [], 'related_users' => []];
        });
        $analysis->request($event); $event->refresh(); $analysis->process($event->id, $event->ai_attempt);
        $this->assertSame('context_changed', $event->fresh()->ai_error_code); $this->assertNull($event->fresh()->analysis);
        $this->assertSame(1, DB::table('v2_machine_risk_ai_attempt')->count());
        $this->assertFalse($analysis->request($event->fresh()));
    }

    public function test_provider_failure_has_a_fixed_safe_error_and_no_report_or_account_action(): void
    {
        $this->enable(); $user = $this->user(); $this->sample([$user->id => 1]); $event = $this->event();
        $analysis = $this->analysis(fn () => throw new \RuntimeException('Private provider body with credentials'));
        $analysis->request($event); $event->refresh(); $analysis->process($event->id, $event->ai_attempt);
        $this->assertSame('analysis_failed', $event->fresh()->ai_error_code); $this->assertNull($event->fresh()->analysis);
        $this->assertStringNotContainsString('Private provider', json_encode($event->fresh()->toArray()));
        $this->assertFalse($user->fresh()->banned); $this->assertSame('private-sub-token', $user->fresh()->token);
    }

    public function test_missing_storage_and_inactive_reporters_are_fail_closed(): void
    {
        $this->enable(); $this->machine->forceFill(['is_active' => false])->save();
        $this->report(time(), ['unreachable', 'unreachable']); Bus::assertNothingDispatched();
        (require base_path('database/migrations/2026_11_03_000001_create_machine_risk_events.php'))->down();
        Cache::forget('machine_risk:policy'); $this->assertFalse(app(MachineRiskSettings::class)->enabled());
        $result = (new MachineRiskController())->overview(Request::create('/risk/node-events'), app(MachineRiskSettings::class))->getData(true)['data'];
        $this->assertFalse($result['available']); $this->assertSame([], $result['events']);
    }

    public function test_recovery_requires_fresh_current_authority_and_retention_runs_even_when_disabled(): void
    {
        $this->enable(); $event = $this->event(); $this->report(time() + 1, ['reachable', 'unreachable'], 0);
        $this->assertSame(1, app(MachineRiskEventService::class)->advance()['recovered']);
        $policy = app(MachineRiskSettings::class); $policy->save(MachineRiskSettings::DEFAULTS, $policy->snapshot()['revision'], 1, true);
        DB::table('v2_machine_risk_event')->where('id', $event->id)->update(['created_at' => time() - 8 * 86400]);
        app(MachineRiskEventService::class)->advance(); $this->assertSame(0, MachineRiskEvent::query()->count());
    }

    public function test_admin_list_is_bounded_and_does_not_expose_authority_or_report_bodies(): void
    {
        $this->enable(); $event = $this->event(); $this->analysis();
        $controller = new MachineRiskController(); $request = Request::create('/risk/node-events');
        $result = $controller->overview($request, app(MachineRiskSettings::class))->getData(true)['data'];
        $this->assertSame(1, $result['summary']['open']); $this->assertCount(1, $result['events']);
        foreach (['authority_hash', 'episode_key', 'ai_attempt', 'evidence', 'analysis'] as $field) $this->assertArrayNotHasKey($field, $result['events'][0]);
        $detail = $controller->detail($event->id)->getData(true)['data'];
        $this->assertTrue($detail['context_current']); $this->assertArrayHasKey('users', $detail['evidence']);
    }

    public function test_routes_remain_admin_only(): void
    {
        $this->bindTestRouter(); (new \App\Http\Routes\V2\AdminRoute())->map(app('router'));
        $routes = array_filter(iterator_to_array(app('router')->getRoutes()), fn ($route) => str_contains($route->uri(), '/risk/node-events'));
        $this->assertCount(5, $routes);
        foreach ($routes as $route) $this->assertContains('admin', $route->gatherMiddleware());
    }
}

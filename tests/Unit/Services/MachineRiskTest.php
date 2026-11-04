<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\MachineRiskController;
use App\Jobs\AnalyzeMachineRiskEventJob;
use App\Jobs\RecordMachineRiskEventJob;
use App\Models\MachineRiskEvent;
use App\Models\MachineRiskInvestigation;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\MachineEntryProbe;
use App\Services\ServerMachine\MachineRiskActivity;
use App\Services\ServerMachine\MachineRiskAnalysis;
use App\Services\ServerMachine\MachineRiskEventService;
use App\Services\ServerMachine\MachineRiskSettings;
use App\Services\ServerMachine\MachineRiskInvestigationService;
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
        (require base_path('database/migrations/2026_11_03_000003_create_machine_risk_investigations.php'))->up();
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
        return app(MachineRiskEventService::class)->persist($this->episode())->fresh();
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

    private function copyEvent(MachineRiskEvent $event): MachineRiskEvent
    {
        $copy = $event->replicate();
        $copy->forceFill(['episode_key' => hash('sha256', random_bytes(16)), 'ai_status' => 'not_requested', 'ai_attempt' => null])->save();
        app(MachineRiskInvestigationService::class)->register($copy, app(MachineRiskSettings::class)->snapshot()['revision']);
        return $copy->fresh();
    }

    private function fullBudget(): void
    {
        DB::table('v2_machine_risk_ai_attempt')->insert(['attempt' => bin2hex(random_bytes(16)), 'event_id' => 0,
            'policy_revision' => app(MachineRiskSettings::class)->snapshot()['revision'], 'created_at' => time()]);
    }

    private function due(MachineRiskEvent $event): void
    {
        MachineRiskInvestigation::query()->whereKey($event->id)->update(['next_check_at' => time() - 1]);
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
            public function record(string $nodeType, int $nodeId, array $sample, float $reportedAt, ?int $sourceMachineId = null, bool $identified = false): void { throw new \RuntimeException('Synthetic cache outage'); }
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
        $this->assertTrue($analysis->request($event)); $this->assertSame('waiting', $event->fresh()->ai_status);
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
        Cache::forget(MachineRiskSettings::CACHE_KEY); $this->assertFalse(app(MachineRiskSettings::class)->enabled());
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

    public function test_waiting_intents_are_deduplicated_fifo_and_only_actual_reservations_consume_budget(): void
    {
        $this->enable(['hourly_budget' => 1]); $event = $this->event(); $next = $this->copyEvent($event);
        $analysis = $this->analysis(); $this->fullBudget();
        $this->assertTrue($analysis->request($event)); $deadline = $event->investigation->fresh()->ai_deadline_at;
        $this->assertTrue($analysis->request($next)); $this->assertTrue($analysis->request($event->fresh()));
        $this->assertSame($deadline, $event->investigation->fresh()->ai_deadline_at);
        $this->assertSame('waiting', $event->fresh()->ai_status); Bus::assertNotDispatched(AnalyzeMachineRiskEventJob::class);
        $this->assertSame(1, DB::table('v2_machine_risk_ai_attempt')->count());
        DB::table('v2_machine_risk_ai_attempt')->update(['created_at' => time() - 3601]);
        MachineRiskInvestigation::query()->update(['ai_next_at' => time() - 1]);
        $this->assertSame(1, $analysis->drain()); $this->assertSame(0, $analysis->drain());
        $this->assertSame('pending', $event->fresh()->ai_status); $this->assertSame('waiting', $next->fresh()->ai_status);
        Bus::assertDispatchedTimes(AnalyzeMachineRiskEventJob::class, 1);
        $this->assertSame(2, DB::table('v2_machine_risk_ai_attempt')->count());
    }

    public function test_auto_queue_waits_for_fresh_complete_probe_and_provider_without_spending_an_attempt(): void
    {
        $this->enable(['auto_ai' => true]); $analysis = new class extends MachineRiskAnalysis {
            public function providerSettings(): array { return []; }
        };
        app()->instance(MachineRiskAnalysis::class, $analysis); $event = $this->event();
        $this->assertSame('waiting', $event->fresh()->ai_status); $this->assertSame('ai_disabled', $event->fresh()->ai_error_code);
        $analysis = $this->analysis(); $this->report(time(), ['unreachable', 'error']);
        $this->assertTrue($analysis->request($event, true)); $this->assertSame('probe_not_fresh', $event->fresh()->ai_error_code);
        $this->assertSame(0, DB::table('v2_machine_risk_ai_attempt')->count());
        $this->report(time(), ['unreachable', 'unreachable']);
        $this->assertTrue($analysis->request($event, true)); $this->assertSame('pending', $event->fresh()->ai_status);
        Bus::assertDispatchedTimes(AnalyzeMachineRiskEventJob::class, 1);
    }

    public function test_auto_recovery_cancels_reserved_job_before_any_provider_call(): void
    {
        $this->enable(['auto_ai' => true]); $analysis = $this->analysis(); $event = $this->event();
        $this->report(time() + 1, ['reachable', 'unreachable'], 0); $analysis->process($event->id, $event->ai_attempt);
        $this->assertSame('cancelled', $event->fresh()->ai_status); $this->assertSame('probe_recovered', $event->fresh()->ai_error_code);
        $this->assertSame(0, app(TicketAiProviderClient::class)->calls); $this->assertSame(1, DB::table('v2_machine_risk_ai_attempt')->count());
        $this->assertFalse($analysis->request($event->fresh(), true));
    }

    public function test_recovery_during_inference_discards_result_without_refunding_or_retrying(): void
    {
        $this->enable(['auto_ai' => true]); $analysis = $this->analysis(function (): array {
            $this->report(time() + 1, ['reachable', 'unreachable'], 0);
            return ['classification' => 'insufficient_evidence', 'summary' => 'Late result', 'evidence_ids' => [], 'limitations' => [], 'related_users' => []];
        });
        $event = $this->event(); $analysis->process($event->id, $event->ai_attempt);
        $this->assertSame('cancelled', $event->fresh()->ai_status); $this->assertNull($event->fresh()->analysis);
        $this->assertSame(1, app(TicketAiProviderClient::class)->calls); $this->assertFalse($analysis->request($event->fresh(), true));
        $this->assertSame(1, DB::table('v2_machine_risk_ai_attempt')->count());
    }

    public function test_manual_queue_expires_at_its_consent_deadline_even_if_budget_is_not_due_yet(): void
    {
        $this->enable(['hourly_budget' => 1]); $event = $this->event(); $analysis = $this->analysis(); $this->fullBudget();
        $analysis->request($event); $row = $event->investigation->fresh();
        $this->assertLessThanOrEqual(time() + 1800, $row->ai_deadline_at);
        $row->forceFill(['ai_deadline_at' => time() - 1, 'ai_next_at' => time() + 1200])->save();
        app(MachineRiskEventService::class)->advance();
        $this->assertSame('expired', $event->fresh()->ai_status); $this->assertNull($row->fresh()->ai_next_at);
        $this->assertSame('watching', $row->fresh()->state); Bus::assertNotDispatched(AnalyzeMachineRiskEventJob::class);
    }

    public function test_policy_reporter_and_probe_changes_cancel_waiting_intents_instead_of_reauthorizing(): void
    {
        foreach (['policy', 'reporter', 'probe'] as $change) {
            $this->enable(['hourly_budget' => 1]); $event = $this->event(); $analysis = $this->analysis(); $this->fullBudget(); $analysis->request($event);
            if ($change === 'policy') $this->enable(['hourly_budget' => 1]);
            elseif ($change === 'reporter') $this->machine->forceFill(['token' => 'new-token'])->save();
            else $this->bindTestSettings(['server_entry_probe_enable' => true, 'server_entry_probe_interval' => 6, 'server_entry_probe_targets' => ['223.5.5.5']]);
            $this->due($event); app(MachineRiskEventService::class)->advance();
            $this->assertSame('context_changed', $event->investigation->fresh()->state);
            $this->assertSame('cancelled', $event->fresh()->ai_status); $this->assertNull($event->investigation->fresh()->ai_next_at);
            $this->assertFalse($analysis->request($event->fresh()));
        }
        Bus::assertNotDispatched(AnalyzeMachineRiskEventJob::class);
    }

    public function test_recovery_requires_two_distinct_successes_and_partial_or_out_of_order_samples_do_not_confirm_it(): void
    {
        $this->enable(); $event = $this->event(); $loop = app(MachineRiskEventService::class); $at = time();
        $this->report($at + 1, ['reachable', 'unreachable'], 0); $loop->advance();
        $this->assertSame('verifying_recovery', $event->investigation->fresh()->state);
        $this->due($event); $loop->advance(); $this->assertSame(1, $event->investigation->fresh()->recovery_samples);
        $this->report($at + 2, ['unreachable', 'error']); $this->due($event); $loop->advance();
        $this->assertSame('awaiting_telemetry', $event->investigation->fresh()->state); $this->assertSame(0, $event->investigation->fresh()->recovery_samples);
        $this->report($at + 3, ['unreachable', 'unreachable']); $this->due($event); $loop->advance();
        $this->assertSame('recurring', $event->investigation->fresh()->state);
        $this->report($at + 2, ['reachable', 'unreachable'], 0); $this->due($event); $loop->advance();
        $this->assertSame('awaiting_telemetry', $event->investigation->fresh()->state);
        $this->report($at + 4, ['reachable', 'unreachable'], 0); $this->due($event); $loop->advance();
        $this->assertSame(1, $event->investigation->fresh()->recovery_samples);
        $this->report($at + 5, ['reachable', 'unreachable'], 0); $this->due($event); $loop->advance();
        $this->assertSame('recovered', $event->investigation->fresh()->state); $this->assertSame($at + 1, $event->fresh()->recovered_at);
    }

    public function test_followup_window_is_bounded_and_legacy_events_are_not_automatically_enrolled(): void
    {
        $this->enable(['hourly_budget' => 1]); $event = $this->event(); $analysis = $this->analysis(); $this->fullBudget(); $analysis->request($event);
        $event->investigation->forceFill(['deadline_at' => time() - 1])->save();
        app(MachineRiskEventService::class)->advance(); $this->assertSame('expired', $event->investigation->fresh()->state);
        $this->assertSame('expired', $event->fresh()->ai_status);
        $legacy = $this->copyEvent($event); $legacy->investigation()->delete();
        $this->assertFalse($analysis->request($legacy, true)); $this->assertNull($legacy->fresh()->investigation);
        $this->assertTrue($analysis->request($legacy)); $this->assertSame('manual', $legacy->fresh()->investigation->ai_origin);
    }

    public function test_provider_and_worker_failures_do_not_automatically_retry_or_refund(): void
    {
        $this->enable(['auto_ai' => true]); $analysis = $this->analysis(fn() => throw new \RuntimeException('secret'));
        $event = $this->event(); $analysis->process($event->id, $event->ai_attempt);
        $this->assertSame('failed', $event->fresh()->ai_status); $this->assertFalse($analysis->request($event->fresh(), true));
        $timeout = $this->copyEvent($event); $analysis->request($timeout, true);
        $timeout->forceFill(['ai_requested_at' => time() - 301])->save(); app(MachineRiskEventService::class)->advance();
        $this->assertSame('worker_timeout', $timeout->fresh()->ai_error_code);
        $this->assertFalse($analysis->request($timeout->fresh(), true)); $this->assertSame(2, DB::table('v2_machine_risk_ai_attempt')->count());
    }

    public function test_followup_notifications_require_new_explicit_opt_in_and_are_attempted_at_most_once(): void
    {
        $this->enable(['notify_admin' => true]); $this->bindTestSettings(['telegram_bot_enable' => true,
            'server_entry_probe_enable' => true, 'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['223.5.5.5', '119.29.29.29']]);
        $telegram = \Mockery::mock(\App\Services\TelegramService::class); $messages = [];
        $telegram->shouldReceive('sendMessageWithAdmin')->times(3)->andReturnUsing(function ($text) use (&$messages): void { $messages[] = $text; });
        app()->instance(\App\Services\TelegramService::class, $telegram);
        $event = $this->event(); $loop = app(MachineRiskInvestigationService::class);
        $loop->notify($event, 'opened'); $loop->notify($event, 'report');
        $this->assertSame('disabled', $event->investigation->fresh()->notices['report']['status']);
        $this->enable(['notify_admin' => true, 'notify_followup' => true]);
        $next = $this->copyEvent($event); $loop->notify($next, 'opened'); $loop->notify($next, 'report'); $loop->notify($next, 'report');
        $this->assertSame('submitted', $next->investigation->fresh()->notices['report']['status']);
        foreach ($messages as $text) foreach (['private-machine-token', '223.5.5.5', 'Synthetic server', 'private@example.test'] as $private) $this->assertStringNotContainsString($private, $text);
    }

    public function test_notice_failures_and_interrupted_delivery_are_not_resent(): void
    {
        $this->enable(['notify_admin' => true, 'notify_followup' => true]); $this->bindTestSettings(['telegram_bot_enable' => true,
            'server_entry_probe_enable' => true, 'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['223.5.5.5', '119.29.29.29']]);
        $telegram = \Mockery::mock(\App\Services\TelegramService::class);
        $telegram->shouldReceive('sendMessageWithAdmin')->once()->andThrow(new \RuntimeException('private response'));
        app()->instance(\App\Services\TelegramService::class, $telegram); $event = $this->event(); $loop = app(MachineRiskInvestigationService::class);
        $loop->notify($event, 'opened'); $this->assertSame('failed', $event->fresh()->notification_status);
        $event->investigation->forceFill(['notices' => ['opened' => ['status' => 'sending', 'at' => time() - 301]]])->save();
        $loop->recoverNotices(); $loop->notify($event, 'opened');
        $this->assertSame('unknown', $event->fresh()->notification_status);
        $this->assertSame('unknown', $event->investigation->fresh()->notices['opened']['status']);
    }

    public function test_legacy_settings_cannot_implicitly_enable_followup_notices_and_missing_migration_ignores_old_cache(): void
    {
        $this->enable(['notify_admin' => true]); $this->assertFalse(app(MachineRiskSettings::class)->settings()['notify_followup']);
        $policy = app(MachineRiskSettings::class); $old = array_diff_key($policy->settings(), ['notify_followup' => true]);
        $policy->save($old, $policy->snapshot()['revision'], 1, true); $this->assertFalse($policy->settings()['notify_followup']);
        $this->enable(['notify_admin' => false, 'notify_followup' => true]); $this->assertFalse($policy->settings()['notify_followup']);
        Cache::put('machine_risk:policy', ['available' => true, 'settings' => ['enabled' => true]], 30);
        Cache::forget(MachineRiskSettings::CACHE_KEY); Schema::drop('v2_machine_risk_investigation');
        $this->assertFalse($policy->enabled()); $this->assertFalse($policy->available());
    }

    public function test_current_resources_are_numeric_only_and_admin_reads_cannot_advance_or_spend(): void
    {
        $this->enable(); $event = $this->event(); $analysis = $this->analysis();
        $status = $this->machine->load_status; $status['cpu'] = 'private body'; $status['mem'] = ['used' => 123, 'total' => 456, 'secret' => 'private'];
        $status['uptime'] = -1; $this->machine->forceFill(['load_status' => $status])->save();
        $analysis->request($event); $analysis->process($event->id, $event->fresh()->ai_attempt);
        $prompt = json_decode(app(TicketAiProviderClient::class)->messages[1]['content'], true);
        $snapshot = $prompt['evidence'][5]['value']['observation'];
        $this->assertNull($snapshot['resources']['cpu_percent']); $this->assertSame(123, $snapshot['resources']['memory_used_bytes']);
        $this->assertNull($snapshot['resources']['uptime_seconds']); $this->assertStringNotContainsString('private', json_encode($snapshot));
        $before = $event->investigation->fresh()->toArray(); $calls = app(TicketAiProviderClient::class)->calls;
        $controller = new MachineRiskController(); $controller->overview(Request::create('/risk/node-events'), app(MachineRiskSettings::class)); $controller->detail($event->id);
        $this->assertSame($before, $event->investigation->fresh()->toArray()); $this->assertSame($calls, app(TicketAiProviderClient::class)->calls);
    }

    public function test_failed_dispatch_is_counted_once_and_not_automatically_retried(): void
    {
        $this->enable(); $event = $this->event(); $analysis = $this->analysis();
        Bus::swap(new class {
            public function dispatch($job): void { throw new \RuntimeException('private queue failure'); }
        });
        $this->assertTrue($analysis->request($event)); $this->assertSame('queue_unavailable', $event->fresh()->ai_error_code);
        $this->assertSame('failed', $event->fresh()->ai_status); $this->assertSame(0, app(TicketAiProviderClient::class)->calls);
        $this->assertSame(0, $analysis->drain()); $this->assertSame(1, DB::table('v2_machine_risk_ai_attempt')->count());
    }

    public function test_disabling_policy_closes_existing_intents_without_touching_accounts_or_node_configuration(): void
    {
        $this->enable(['auto_ai' => true, 'hourly_budget' => 1]); $analysis = $this->analysis(); $this->fullBudget();
        $user = $this->user(); $event = $this->event(); $beforeMachine = $this->machine->fresh()->toArray(); $beforeNode = $this->node->fresh()->toArray();
        $settings = app(MachineRiskSettings::class); $settings->save(MachineRiskSettings::DEFAULTS, $settings->snapshot()['revision'], 1, true);
        $this->due($event); app(MachineRiskEventService::class)->advance();
        $this->assertSame('cancelled', $event->fresh()->ai_status); $this->assertSame('context_changed', $event->investigation->fresh()->state);
        $this->assertSame($beforeMachine, $this->machine->fresh()->toArray()); $this->assertSame($beforeNode, $this->node->fresh()->toArray());
        $this->assertSame('private-sub-token', $user->fresh()->token); $this->assertFalse($user->fresh()->banned);
        $this->assertSame(0, $analysis->drain()); Bus::assertNotDispatched(AnalyzeMachineRiskEventJob::class);
    }

    public function test_stale_probe_never_proves_recovery_or_authorizes_automatic_inference(): void
    {
        $this->enable(['auto_ai' => true, 'hourly_budget' => 1]); $analysis = $this->analysis(); $this->fullBudget(); $event = $this->event();
        $this->report(time() - 1000, ['reachable', 'unreachable'], 0);
        app(MachineRiskEventService::class)->advance();
        $this->assertSame('awaiting_telemetry', $event->investigation->fresh()->state); $this->assertSame('open', $event->fresh()->status);
        $this->assertSame(0, $event->investigation->fresh()->recovery_samples);
        $this->assertTrue($analysis->request($event->fresh(), true)); $this->assertSame('probe_not_fresh', $event->fresh()->ai_error_code);
        Bus::assertNotDispatched(AnalyzeMachineRiskEventJob::class);
    }

    public function test_notice_reconciliation_finds_stale_claim_even_after_recent_followup_checks(): void
    {
        $this->enable(); $event = $this->event();
        $event->investigation->forceFill(['checked_at' => time(), 'notices' => ['report' => ['status' => 'sending', 'at' => time() - 301],
            'recovered' => ['status' => 'sending', 'at' => time()]]])->save();
        app(MachineRiskInvestigationService::class)->recoverNotices();
        $this->assertSame('unknown', $event->investigation->fresh()->notices['report']['status']);
        $this->assertSame('sending', $event->investigation->fresh()->notices['recovered']['status']);
    }

    public function test_failed_followup_creation_rolls_back_incident_so_the_worker_can_retry_safely(): void
    {
        $this->enable(); $episode = $this->episode(); $service = app(MachineRiskInvestigationService::class);
        app()->instance(MachineRiskInvestigationService::class, new class extends MachineRiskInvestigationService {
            public function register(MachineRiskEvent $event, int $revision, bool $manual = false): MachineRiskInvestigation { throw new \RuntimeException('Synthetic write failure'); }
        });
        try { app(MachineRiskEventService::class)->persist($episode); $this->fail('Write failure expected'); }
        catch (\RuntimeException $error) { $this->assertSame('Synthetic write failure', $error->getMessage()); }
        $this->assertSame(0, MachineRiskEvent::query()->count());
        app()->instance(MachineRiskInvestigationService::class, $service); $event = app(MachineRiskEventService::class)->persist($episode);
        $this->assertNotNull($event->investigation); $this->assertSame(1, MachineRiskEvent::query()->count());
    }

    public function test_worker_failure_hook_records_notice_once_and_does_not_overwrite_completed_reports(): void
    {
        $this->enable(); $event = $this->event(); $analysis = $this->analysis(); $analysis->request($event);
        $event->refresh(); $job = new AnalyzeMachineRiskEventJob($event->id, $event->ai_attempt);
        $job->failed(new \RuntimeException('Private worker error')); $job->failed(null);
        $this->assertSame('worker_failed', $event->fresh()->ai_error_code); $this->assertNotNull($event->fresh()->ai_finished_at);
        $this->assertSame('disabled', $event->investigation->fresh()->notices['report']['status']);
        $event->forceFill(['ai_status' => 'completed'])->save(); $job->failed(null);
        $this->assertSame('completed', $event->fresh()->ai_status); $this->assertSame(1, DB::table('v2_machine_risk_ai_attempt')->count());
    }

    public function test_legacy_auto_investigation_uses_distinct_complete_observations_without_inventing_an_agent_counter(): void
    {
        $this->enable(['auto_ai' => true]); $analysis = $this->analysis();
        $at = time(); $this->report($at - 1, ['unreachable', 'unreachable'], null); $this->report($at, ['unreachable', 'unreachable'], null);
        $event = app(MachineRiskEventService::class)->persist(Bus::dispatched(RecordMachineRiskEventJob::class)->last()->episode)->fresh();
        $this->assertSame('pending', $event->ai_status);
        $this->assertSame('panel_observed_rounds', $event->investigation->observation['failure_confirmation_source']);
        $this->assertNull($event->investigation->observation['probe']['consecutive_failures']);
        $loop = app(MachineRiskInvestigationService::class); $row = $event->investigation;
        $this->report($at + 1, ['unreachable', 'error'], null);
        $row->observation = $loop->withFailureSamples($event, $row, $loop->observation($this->machine));
        $this->report($at + 2, ['unreachable', 'unreachable'], null);
        $row->observation = $loop->withFailureSamples($event, $row, $loop->observation($this->machine));
        $this->assertFalse($loop->failureConfirmed($row->observation, $event));
        $duplicate = $loop->withFailureSamples($event, $row, $loop->observation($this->machine));
        $this->assertSame(1, $duplicate['failure_samples']);
        $this->report($at + 3, ['unreachable', 'unreachable'], null);
        $this->assertTrue($loop->failureConfirmed($loop->withFailureSamples($event, $row, $loop->observation($this->machine)), $event));
    }
}

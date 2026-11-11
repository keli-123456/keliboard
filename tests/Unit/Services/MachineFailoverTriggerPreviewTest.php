<?php
namespace Tests\Unit\Services;

use App\Models\MachineFailoverService;
use App\Models\MachineFailoverTriggerPreview as Preview;
use App\Services\ServerMachine\Cloud\MachineFailoverBudget;
use App\Services\ServerMachine\Cloud\MachineFailoverTriggerPreview as Collector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MachineFailoverTriggerPreviewTest extends MachineFailoverShadowTest
{
    private MachineFailoverBudget $finance;
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_11_07_180000_create_machine_failover_native_journal.php', '2026_11_07_190000_create_machine_failover_finance.php',
            '2026_11_08_000000_create_machine_failover_trigger_previews.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        $this->finance = new class(app(\App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry::class), app(\App\Services\ServerMachine\Cloud\MachineProvisionProviders::class)) extends MachineFailoverBudget {
            public array $value = [];
            public function forecast(int $id, int $revision, int $admin): array { return $this->value; }
        };
        $this->finance->value = ['state' => 'verified', 'available_cents' => 10000, 'valid_until' => $this->clock + 300];
        app()->instance(MachineFailoverBudget::class, $this->finance);
    }
    private function collectPreview(int $limit = 5): array { return app(Collector::class)->collect($limit); }
    private function enablePreview(): void { config(['machine_failover.preview_enabled' => true]); }
    private function historyPreview(?int $before = null): array { return app(Collector::class)->history($this->service['id'], 1, $this->admin->id, $before); }
    private function businessState(): array
    {
        $result = [];
        foreach (['v2_machine_failover_execution', 'v2_machine_failover_execution_member', 'v2_machine_failover_attempt',
            'v2_machine_failover_budget_release', 'v2_machine_provision_task', 'v2_machine_failover_plan', 'v2_machine_failover_observation'] as $table)
            $result[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        return $result;
    }
    public function test_collector_defaults_off_even_if_execution_flags_are_set(): void
    {
        config(['machine_failover.runner_enabled' => true, 'machine_failover.allow_replacement' => true, 'machine_provision.allow_live' => true, 'machine_acceptance.allow_dns' => true]);
        $this->assertFalse($this->collectPreview()['enabled']); $this->assertSame(0, Preview::query()->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_same_episode_is_rechecked_without_duplicate_rows_or_events_and_without_real_intents(): void
    {
        $this->trigger(); $this->enablePreview(); $before = $this->businessState();
        $first = $this->collectPreview(); $this->assertSame(1, $first['services_checked']);
        $this->assertSame($before, $this->businessState());
        $this->assertSame(['would_start', 'waiting'], Preview::query()->orderBy('member_id')->pluck('decision')->all());
        $events = DB::table('v2_machine_failover_trigger_event')->count(); $again = $this->collectPreview();
        $this->assertSame(0, $again['events_written']); $this->assertSame(2, Preview::query()->count());
        $this->assertSame($events, DB::table('v2_machine_failover_trigger_event')->count());
        $this->tick(); foreach ([0, 1] as $i) $this->sample($i, false);
        $this->assertSame(0, $this->collectPreview()['events_written']); $this->assertSame(2, Preview::query()->count());
        $this->assertSame($before['v2_machine_failover_execution'], $this->businessState()['v2_machine_failover_execution']);
        foreach (['v2_machine_failover_attempt', 'v2_machine_provision_task', 'v2_machine_failover_budget_release'] as $table)
            $this->assertSame($before[$table], $this->businessState()[$table]);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_source_recovery_cancels_the_old_forecast_and_a_new_failure_gets_a_new_episode(): void
    {
        $this->trigger(); $this->enablePreview(); $this->collectPreview(); $old = Preview::query()->where('decision', 'would_start')->firstOrFail();
        $this->tick(); $this->sample(0, true); $this->collectPreview();
        $this->assertSame('cancelled', $old->fresh()->decision); $this->assertSame(['source_recovered'], $old->fresh()->reasons);
        $this->assertNotNull($old->fresh()->closed_at);
        foreach ([false, false] as $healthy) { $this->tick(); $this->sample(0, $healthy); }
        $this->collectPreview();
        $this->assertSame(2, Preview::query()->where('member_id', $old->member_id)->whereNotNull('episode_key')->count());
        $this->assertSame('cancelled', $old->fresh()->decision); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_missing_or_insufficient_cost_evidence_does_not_block_or_reserve_money(): void
    {
        $this->trigger(); $this->enablePreview(); $this->finance->value['available_cents'] = 3999;
        $this->collectPreview(); $row = Preview::query()->orderBy('member_id')->first();
        $this->assertSame('would_start', $row->decision); $this->assertSame([], $row->reasons); $this->assertSame(0, $row->planned_cents);
        $this->finance->value['state'] = 'expired'; $this->collectPreview();
        $this->assertSame('would_start', $row->fresh()->decision);
        $this->assertSame(0, DB::table('v2_machine_failover_attempt')->count());
        $this->assertSame(0, DB::table('v2_machine_failover_budget_release')->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_real_finance_projection_is_cache_only_and_unknown_is_not_zero_cost(): void
    {
        $this->trigger(); $this->enablePreview(); app()->forgetInstance(MachineFailoverBudget::class);
        $before = $this->businessState(); $this->collectPreview();
        $this->assertSame('would_start', Preview::query()->orderBy('member_id')->first()->decision);
        $this->assertSame($before, $this->businessState()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_stale_reports_expire_old_plans_and_history_is_read_only_redacted_and_stale(): void
    {
        $this->trigger(); $this->enablePreview(); $this->collectPreview(); $old = Preview::query()->where('decision', 'would_start')->firstOrFail();
        $this->tick(50); $this->collectPreview(); $this->assertSame('expired', $old->fresh()->decision);
        $count = DB::table('v2_machine_failover_trigger_event')->count(); $history = $this->historyPreview();
        $this->assertFalse($history['dispatch_allowed']); $this->assertSame(0, $history['cloud_writes']);
        $json = json_encode($history); foreach (['dedup_key', 'episode_key', 'context_hash', 'source_hash', 'synthetic-secret', 'synthetic-machine-'] as $private)
            $this->assertStringNotContainsString($private, $json);
        $this->assertSame($count, DB::table('v2_machine_failover_trigger_event')->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_permission_loss_is_audited_and_cancels_prior_candidates_without_event_flooding(): void
    {
        $this->trigger(); $this->enablePreview(); $this->collectPreview(); $old = Preview::query()->where('decision', 'would_start')->firstOrFail();
        $this->admin->fill(['banned' => true])->save(); $failed = $this->collectPreview(); $this->assertSame(1, $failed['errors']);
        $this->assertSame(4, $failed['records_changed']); $this->assertSame(4, $failed['events_written']);
        $this->assertSame(['permission_revoked'], $old->fresh()->reasons); $this->assertSame('cancelled', $old->fresh()->decision);
        $this->assertSame(0, $this->collectPreview()['events_written']); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_collector_is_bounded_and_cursor_rotates_services(): void
    {
        $this->enablePreview();
        $second = MachineFailoverService::query()->create(['name' => 'Empty synthetic service', 'revision' => 1, 'max_parallel' => 1,
            'attempt_limit' => 1, 'monthly_cap_usd' => 10, 'updated_by' => $this->admin->id]);
        $this->assertSame(1, $this->collectPreview(1)['services_checked']);
        $this->assertSame(1, $this->collectPreview(1)['services_checked']);
        $this->assertSame($second->id, \Illuminate\Support\Facades\Cache::get('machine-failover:trigger-preview-cursor'));
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_history_endpoint_rejects_forged_instructions_and_non_admin_access(): void
    {
        $controller = new \App\Http\Controllers\V2\Admin\Server\MachineFailoverServiceController();
        $request = Request::create('/synthetic', 'GET', ['service_id' => $this->service['id'], 'service_revision' => 1, 'execute' => true]);
        $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(422, $controller->triggerHistory($request, app(Collector::class))->getStatusCode());
        $this->admin->fill(['is_admin' => false])->save();
        try { $controller->triggerHistory($request, app(Collector::class)); $this->fail('Expected forbidden'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
    }
    public function test_real_flags_cannot_turn_the_preview_into_a_dispatcher(): void
    {
        $this->trigger(); $this->enablePreview();
        config(['machine_failover.runner_enabled' => true, 'machine_failover.allow_replacement' => true,
            'machine_provision.allow_live' => true, 'machine_acceptance.allow_dns' => true]);
        $before = $this->businessState(); $this->collectPreview(); $this->assertSame($before, $this->businessState());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_used_native_episode_is_not_proposed_again(): void
    {
        $this->trigger(); $this->enablePreview(); $this->collectPreview(); $row = Preview::query()->where('decision', 'would_start')->firstOrFail();
        DB::table('v2_machine_failover_execution_member')->insert(['execution_id' => (string) \Illuminate\Support\Str::uuid(),
            'member_id' => $row->member_id, 'policy_id' => $row->policy_id, 'policy_revision' => 1,
            'plan_id' => \App\Models\MachineFailoverPlan::query()->where('policy_id', $row->policy_id)->latest('id')->value('id'),
            'episode_key' => $row->episode_key, 'payload' => '{}', 'created_at' => $this->clock, 'updated_at' => $this->clock]);
        $this->collectPreview(); $this->assertSame('blocked', $row->fresh()->decision);
        $this->assertContains('native_episode_used', $row->fresh()->reasons); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_disabled_member_is_cancelled_and_disabled_collector_history_is_not_current(): void
    {
        $this->trigger(); $this->enablePreview(); $this->collectPreview(); $row = Preview::query()->where('decision', 'would_start')->firstOrFail();
        \App\Models\MachineFailoverMember::query()->whereKey($row->member_id)->update(['enabled' => false]); $this->collectPreview();
        $this->assertSame('cancelled', $row->fresh()->decision); $this->assertSame(['member_disabled'], $row->fresh()->reasons);
        config(['machine_failover.preview_enabled' => false]);
        $this->assertFalse($this->historyPreview()['collector_enabled']);
        $this->assertNotContains(true, array_column($this->historyPreview()['records'], 'current'));
    }
    public function test_service_revision_changes_coalesce_the_episode_but_keep_transition_history(): void
    {
        $this->trigger(); $this->enablePreview(); $this->collectPreview();
        MachineFailoverService::query()->whereKey($this->service['id'])->update(['revision' => 2]); $this->collectPreview();
        $this->assertSame(2, Preview::query()->count()); $this->assertSame([2], Preview::query()->distinct()->pluck('service_revision')->all());
        $this->assertSame([1, 2], DB::table('v2_machine_failover_trigger_event')->distinct()->orderBy('service_revision')->pluck('service_revision')->all());
        $this->expectException(\App\Services\ServerMachine\Cloud\CloudException::class); $this->historyPreview();
    }
    public function test_schema_gap_and_sweep_lock_fail_closed_without_partial_business_writes(): void
    {
        $this->enablePreview(); $lock = \Illuminate\Support\Facades\Cache::lock('machine-failover:trigger-preview-sweep', 60); $lock->get();
        $this->assertTrue($this->collectPreview()['busy']); $lock->release();
        \Illuminate\Support\Facades\Schema::drop('v2_machine_failover_trigger_event');
        $this->assertFalse($this->collectPreview()['available']); $this->assertSame(0, Preview::query()->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_transition_cursor_is_stable_and_history_performs_no_writes(): void
    {
        $this->trigger(); $this->enablePreview();
        $run = \App\Models\MachineFailoverExecution::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(),
            'service_id' => $this->service['id'], 'service_revision' => 1, 'admin_id' => $this->admin->id,
            'request_key' => str_repeat('f', 64), 'context_hash' => str_repeat('a', 64), 'intent_hash' => str_repeat('b', 64),
            'payload' => [], 'deadline_at' => $this->clock + 600]);
        for ($i = 0; $i < 25; $i++) {
            $run->fill(['status' => $i % 2 ? 'running' : 'stopped'])->save(); $this->collectPreview();
        }
        DB::enableQueryLog(); $first = $this->historyPreview(); $second = $this->historyPreview($first['next_before_id']);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(20, $first['events']); $this->assertSame(end($first['events'])['id'], $first['next_before_id']);
        $this->assertNotEmpty($second['events']); $this->assertNull($second['next_before_id']);
        $this->assertEmpty(array_intersect(array_column($first['events'], 'id'), array_column($second['events'], 'id')));
        foreach ($second['events'] as $event) $this->assertLessThan($first['next_before_id'], $event['id']);
        foreach ($queries as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_preview_command_is_default_off_and_rejects_unbounded_limits(): void
    {
        $command = new \App\Console\Commands\PreviewMachineFailover(); $command->setLaravel(app());
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $this->assertSame(0, $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output));
        $this->assertFalse(json_decode($output->fetch(), true)['enabled']);
        $this->assertSame(0, Preview::query()->count());
        $this->assertSame(2, $command->run(new \Symfony\Component\Console\Input\ArrayInput(['--limit' => 11]), $output));
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_an_audit_transaction_failure_cannot_report_rolled_back_records_as_written(): void
    {
        $this->trigger(); $this->enablePreview(); $before = $this->businessState();
        $member = \App\Models\MachineFailoverMember::query()->orderByDesc('id')->value('id');
        DB::unprepared("CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON v2_machine_failover_trigger_event WHEN NEW.member_id = " . (int) $member
            . " BEGIN SELECT RAISE(FAIL, 'synthetic audit failure'); END");
        $result = $this->collectPreview();
        $this->assertSame(1, $result['errors']); $this->assertSame(0, $result['records_changed']); $this->assertSame(0, $result['events_written']);
        $this->assertSame(0, Preview::query()->count()); $this->assertSame(0, DB::table('v2_machine_failover_trigger_event')->count());
        $this->assertSame($before, $this->businessState()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_populated_audit_cannot_be_discarded_by_migration_rollback(): void
    {
        $this->trigger(); $this->enablePreview(); $this->collectPreview();
        $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_11_08_000000_create_machine_failover_trigger_previews.php'))->down();
    }
}

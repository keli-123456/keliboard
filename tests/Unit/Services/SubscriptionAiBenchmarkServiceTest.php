<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SubscriptionAiAction;
use App\Services\SubscriptionAiAutomationService;
use App\Services\SubscriptionAiBenchmarkService as Benchmark;
use App\Services\TicketAiAssistantService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionAiBenchmarkServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private Benchmark $bench;
    private object $automation;
    private object $ai;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); config(['app.key' => 'synthetic-benchmark-key']);
        Schema::create('v2_plugins', function (Blueprint $table): void { $table->increments('id'); $table->string('code'); $table->text('config'); });
        DB::table('v2_plugins')->insert(['code' => 'subscription_control', 'config' => '{}']);
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->increments('id'); $table->integer('site_id')->nullable(); $table->boolean('is_admin')->default(false); $table->boolean('is_staff')->default(false); $table->boolean('banned')->default(false);
        });
        foreach (['000001_create_subscription_ai_actions', '000002_create_subscription_ai_behavior', '000003_create_subscription_ai_benchmarks'] as $migration) (require base_path('database/migrations/2026_11_02_' . $migration . '.php'))->up();
        $this->automation = new class extends SubscriptionAiAutomationService {
            public array $current;
            public function __construct() { $this->current = array_merge(self::DEFAULTS, ['ai_auto_enabled' => true]); }
            public function settings(): array { return $this->current; }
        };
        $this->ai = new class extends TicketAiAssistantService {
            public array $provider = ['enabled' => true, 'base_url' => 'https://model.example.test/v1', 'model' => 'synthetic-model', 'api_key' => 'never-display-provider-key'];
            public function __construct() {}
            public function providerSettingsForInternalUse(): array { return $this->provider; }
        };
        app()->instance(SubscriptionAiAutomationService::class, $this->automation);
        app()->instance(TicketAiAssistantService::class, $this->ai);
        $this->bench = new Benchmark();
        DB::table('v2_user')->insert([['id' => 10, 'site_id' => 2, 'is_admin' => false], ['id' => 11, 'site_id' => 3, 'is_admin' => false], ['id' => 12, 'site_id' => 2, 'is_admin' => true]]);
    }

    private function start(): object
    {
        $this->bench->start(2, 99, true);
        return DB::table(Benchmark::RUNS)->latest('id')->first();
    }

    public function test_a_comparison_cannot_start_for_a_site_outside_the_observation_authorization(): void
    {
        $this->automation->current['ai_auto_site_ids'] = [0];
        $this->expectExceptionMessage('site_not_authorized');
        $this->bench->start(2, 99, true);
    }

    public function test_admission_context_tracks_effective_risk_model_budget_and_effort_not_an_unrelated_ticket_model(): void
    {
        Schema::create('v2_settings', function (Blueprint $table): void {
            $table->id(); $table->string('name')->unique(); $table->text('value')->nullable(); $table->timestamps();
        });
        \App\Models\Setting::createOrUpdate('risk_ai_model', 'gpt-6-luna');
        $first = $this->bench->contextKey($this->automation->current);
        $this->ai->provider['model'] = 'gpt-6.1-sol';
        $this->assertSame($first, $this->bench->contextKey($this->automation->current));
        \App\Models\Setting::createOrUpdate('risk_ai_model', 'gpt-6.1-sol');
        app()->forgetInstance(\App\Support\Setting::class);
        \Illuminate\Support\Facades\Cache::flush();
        $second = $this->bench->contextKey($this->automation->current);
        $this->assertNotSame($first, $second);
        \App\Models\Setting::createOrUpdate('risk_ai_max_tokens', 2048);
        app()->forgetInstance(\App\Support\Setting::class);
        \Illuminate\Support\Facades\Cache::flush();
        $third = $this->bench->contextKey($this->automation->current);
        $this->assertNotSame($second, $third);
        \App\Models\Setting::createOrUpdate('risk_ai_reasoning_effort', 'high');
        app()->forgetInstance(\App\Support\Setting::class);
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertNotSame($third, $this->bench->contextKey($this->automation->current));
    }

    private function evidence(array $outcomes = ['allowed' => 1]): array
    {
        return ['source' => 'independent_behavior_v1', 'features' => [['id' => 1, 'current_bucket_pull_counts' => [1]]],
            'event_ids' => [1, 2, 3, 4], 'baseline_mature' => true, 'last_event_at' => time(), 'window_seconds' => 900,
            'observed_outcomes' => $outcomes, 'behavior_bucket_ids' => [77], 'sampling' => ['lower_bounds_only' => true]];
    }

    private function action(int $user = 10, array $outcomes = ['allowed' => 1]): SubscriptionAiAction
    {
        return SubscriptionAiAction::query()->create(['user_id' => $user, 'site_id' => 2, 'credential_hash' => str_repeat('a', 64), 'policy_hash' => str_repeat('b', 64),
            'intent_key' => hash('sha256', uniqid('synthetic', true)), 'status' => 'pending', 'evidence' => $this->evidence($outcomes), 'authorization' => []]);
    }

    private function decision(): array { return ['action' => 'temporary_block', 'duration_seconds' => 60]; }

    private function seedPassing(object $run): void
    {
        $rows = []; $candidate = 1;
        for ($i = 0; $i < Benchmark::HOLDOUT_SIZE; $i++) {
            do { $hash = hash_hmac('sha256', '2:' . $candidate++, 'synthetic-benchmark-key'); } while (hexdec(substr($hash, 0, 6)) % 5 === 0);
            $abuse = $i >= 400;
            $rows[] = ['run_id' => $run->id, 'action_id' => 10000 + $i, 'subject_hash' => $hash, 'cohort' => 'holdout', 'evidence' => json_encode($this->evidence()),
                'legacy_prediction' => $abuse || $i < 60, 'prediction' => $abuse, 'prediction_ok' => true, 'latency_ms' => 1000, 'total_tokens' => 100,
                'evidence_age_seconds' => 1, 'created_at' => time() - 10, 'completed_at' => time() - 9, 'truth' => $abuse ? 'abuse' : 'benign', 'truth_by' => 99, 'truth_at' => time()];
        }
        foreach (array_chunk($rows, 50) as $chunk) DB::table(Benchmark::CASES)->insert($chunk);
        DB::table(Benchmark::RUNS)->where('id', $run->id)->update(['status' => 'sealed']);
    }

    private function pilotSettings(): array
    {
        return array_merge($this->automation->current, ['ai_auto_mode' => 'independent', 'ai_auto_max_seconds' => 120]);
    }

    public function test_no_grant_and_old_event_mode_cannot_enable_ai_execution(): void
    {
        $this->assertFalse($this->bench->permitsMode($this->pilotSettings()));
        $this->assertFalse($this->bench->permitsMode(array_merge($this->pilotSettings(), ['ai_auto_mode' => 'legacy'])));
        $this->assertTrue($this->bench->permitsMode($this->automation->current));
    }

    public function test_enrollment_is_before_inference_and_repeated_accounts_cannot_pad_the_sample(): void
    {
        $run = $this->start(); $action = $this->action();
        $this->bench->enroll($action, $this->automation->current);
        $this->bench->enroll($action, $this->automation->current);
        $this->bench->enroll($this->action(), $this->automation->current);
        $this->assertSame(1, DB::table(Benchmark::CASES)->count());
        $this->assertNull(DB::table(Benchmark::CASES)->value('prediction'));
        $this->assertFalse($this->bench->overview(2)['report']['eligible']);
        $this->assertSame('collecting', $run->status);
    }

    public function test_independent_fact_queue_exposes_neither_ai_nor_rule_predictions(): void
    {
        $this->start(); $action = $this->action(10, ['legacy_blocked' => 3]);
        $this->bench->enroll($action, $this->automation->current); $this->bench->finish($action->id, $this->decision(), ['total_tokens' => 100]);
        $case = $this->bench->overview(2)['cases'][0];
        foreach (['prediction', 'legacy_prediction', 'action_id', 'subject_hash', 'truth'] as $hidden) $this->assertArrayNotHasKey($hidden, $case);
        $this->assertSame(10, $case['user_id']); $this->assertArrayNotHasKey('observed_outcomes', $case['evidence']);
        $this->assertArrayNotHasKey('behavior_bucket_ids', $case['evidence']);
    }

    public function test_labels_require_independent_attestation_and_a_frozen_prediction(): void
    {
        $this->start(); $action = $this->action(); $this->bench->enroll($action, $this->automation->current);
        $id = DB::table(Benchmark::CASES)->value('id');
        foreach ([false, true] as $attested) {
            try { $this->bench->label($id, 'benign', $attested, 99); $this->fail('Premature label accepted'); }
            catch (\RuntimeException $error) { $this->assertSame($attested ? 'prediction_not_frozen' : 'independent_review_required', $error->getMessage()); }
        }
        $this->bench->finish($action->id, null); $this->bench->label($id, 'uncertain', true, 99);
        $this->assertSame('uncertain', DB::table(Benchmark::CASES)->value('truth'));
    }

    public function test_failures_and_missing_usage_are_not_quietly_removed_from_the_exam(): void
    {
        $run = $this->start(); $this->seedPassing($run);
        DB::table(Benchmark::CASES)->where('run_id', $run->id)->orderBy('id')->limit(1)->update(['prediction_ok' => false, 'total_tokens' => null]);
        $report = $this->bench->overview(2)['report'];
        $this->assertSame(600, $report['sample_count']); $this->assertFalse($report['eligible']);
        $this->assertContains('prediction_incomplete', $report['reasons']); $this->assertContains('operational_evidence_incomplete', $report['reasons']);
    }

    public function test_exact_paired_math_and_miss_bound_have_known_values(): void
    {
        $this->assertEqualsWithDelta(pow(0.5, 10), $this->bench->pairedP(10, 0), 1e-12);
        $this->assertSame(1.0, $this->bench->pairedP(0, 0));
        $this->assertEqualsWithDelta(1 - pow(0.025, 1 / 200), $this->bench->binomialUpper(0, 200), 1e-10);
        $this->assertSame(1.0, $this->bench->binomialUpper(0, 0));
    }

    public function test_matching_old_rules_or_never_intervening_does_not_prove_superiority(): void
    {
        $run = $this->start(); $this->seedPassing($run);
        DB::table(Benchmark::CASES)->where('run_id', $run->id)->update(['prediction' => DB::raw('legacy_prediction')]);
        $this->assertContains('false_positive_advantage_unproven', $this->bench->overview(2)['report']['reasons']);
        DB::table(Benchmark::CASES)->where('run_id', $run->id)->update(['prediction' => false]);
        $this->assertContains('miss_safety_unproven', $this->bench->overview(2)['report']['reasons']);
    }

    public function test_synthetic_pass_is_only_pilot_eligibility_not_automatic_activation(): void
    {
        $run = $this->start(); $this->seedPassing($run); $report = $this->bench->overview(2)['report'];
        $this->assertTrue($report['eligible']); $this->assertSame(0, DB::table(Benchmark::GRANTS)->count());
        $this->assertTrue($report['selected_sample_only']); $this->assertTrue($report['cpu_memory_and_money_require_manual_signoff']);
        $this->assertSame('shadow', $this->automation->current['ai_auto_mode']);
    }

    public function test_approval_requires_human_operational_signoff_and_an_exact_ten_account_site_scope(): void
    {
        $run = $this->start(); $this->seedPassing($run);
        foreach ([[[10], false], [[11], true], [[12], true], [range(1, 11), true]] as [$ids, $ops]) {
            try { $this->bench->approve($run->id, $ids, true, $ops, 99); $this->fail('Invalid approval accepted'); }
            catch (\RuntimeException) { $this->assertSame(0, DB::table(Benchmark::GRANTS)->count()); }
        }
        $this->bench->approve($run->id, [10], true, true, 99);
        $user = \App\Models\User::query()->findOrFail(10); $settings = $this->pilotSettings();
        $this->assertTrue($this->bench->permits($user, $settings, 'throttle'));
        $this->assertFalse($this->bench->permits(\App\Models\User::query()->findOrFail(11), $settings));
        $this->assertFalse($this->bench->permits($user, $settings, 'reset_token'));
        $this->assertFalse($this->bench->permitsMode(array_merge($settings, ['ai_auto_max_seconds' => 121])));
        $this->assertFalse($this->bench->permitsMode(array_merge($settings, ['ai_auto_allow_suspend' => true])));
        $this->assertSame('shadow', $this->automation->current['ai_auto_mode']);
    }

    public function test_expiry_rule_or_model_changes_revoke_runtime_permission(): void
    {
        $run = $this->start(); $this->seedPassing($run); $this->bench->approve($run->id, [10], true, true, 99); $settings = $this->pilotSettings();
        $this->ai->provider['model'] = 'changed-model'; $this->assertFalse($this->bench->permitsMode($settings));
        $this->ai->provider['model'] = 'synthetic-model';
        DB::table('v2_plugins')->update(['config' => '{"new_rule":true}']); $this->assertFalse($this->bench->permitsMode($settings));
        DB::table('v2_plugins')->update(['config' => '{}']);
        DB::table(Benchmark::GRANTS)->update(['expires_at' => time() - 1]); $this->assertFalse($this->bench->permitsMode($settings));
    }

    public function test_relabeling_immediately_revokes_an_existing_pilot_and_uncertainty_blocks_readiness(): void
    {
        $run = $this->start(); $this->seedPassing($run); $this->bench->approve($run->id, [10], true, true, 99);
        $case = DB::table(Benchmark::CASES)->where('run_id', $run->id)->first();
        $this->bench->label($case->id, 'uncertain', true, 99);
        $this->assertFalse($this->bench->permitsMode($this->pilotSettings()));
        $this->assertContains('independent_labels_incomplete', $this->bench->overview(2)['report']['reasons']);
    }

    public function test_additional_cases_cannot_replace_failures_in_the_fixed_first_cohort(): void
    {
        $run = $this->start(); $this->seedPassing($run);
        $first = DB::table(Benchmark::CASES)->where('run_id', $run->id)->first();
        DB::table(Benchmark::CASES)->where('id', $first->id)->update(['truth' => 'uncertain']);
        $extra = (array) $first; unset($extra['id']); $extra['subject_hash'] = str_repeat('f', 64); $extra['action_id'] = 99999; DB::table(Benchmark::CASES)->insert($extra);
        $this->assertFalse($this->bench->overview(2)['report']['eligible']); $this->assertSame(600, $this->bench->overview(2)['report']['sample_count']);
    }

    public function test_learning_examples_never_use_holdout_truth_and_are_frozen_for_the_next_run(): void
    {
        $run = $this->start(); $this->seedPassing($run);
        $sample = (array) DB::table(Benchmark::CASES)->first(); unset($sample['id']);
        $sample['cohort'] = 'training'; $sample['subject_hash'] = str_repeat('0', 64); $sample['action_id'] = 99998; $sample['evidence'] = json_encode(['source' => 'training-only-marker']);
        DB::table(Benchmark::CASES)->insert($sample);
        $next = $this->bench->start(2, 99, true); $new = DB::table(Benchmark::RUNS)->where('id', $next['run']['id'])->first();
        $teaching = json_decode($new->teaching, true); $this->assertCount(1, $teaching['reviewed_training_examples']);
        $this->assertSame('training-only-marker', $teaching['reviewed_training_examples'][0]['evidence']['source']);
        DB::table(Benchmark::CASES)->where('action_id', 99998)->update(['truth' => 'abuse']);
        $this->assertSame($new->teaching, DB::table(Benchmark::RUNS)->where('id', $new->id)->value('teaching'));
    }

    public function test_cancel_does_not_delete_cases_or_accounts_and_cannot_restore_an_older_grant(): void
    {
        $run = $this->start(); $this->seedPassing($run); $this->bench->approve($run->id, [10], true, true, 99);
        $this->bench->cancel($run->id, 99);
        $this->assertFalse($this->bench->permitsMode($this->pilotSettings()));
        $this->assertSame(600, DB::table(Benchmark::CASES)->count()); $this->assertSame(3, DB::table('v2_user')->count());
        $this->assertSame(99, DB::table(Benchmark::RUNS)->where('id', $run->id)->value('cancelled_by'));
    }

    public function test_failed_prediction_is_frozen_and_cannot_be_overwritten_by_a_late_success(): void
    {
        $this->start(); $action = $this->action(); $this->bench->enroll($action, $this->automation->current);
        $this->bench->finish($action->id, null); $this->bench->finish($action->id, $this->decision(), ['total_tokens' => 100]);
        $this->assertSame(0, DB::table(Benchmark::CASES)->value('prediction_ok'));
    }

    public function test_same_exam_cannot_be_cleared_and_restarted_until_it_passes(): void
    {
        $this->start(); $action = $this->action(); $this->bench->enroll($action, $this->automation->current);
        $run = DB::table(Benchmark::RUNS)->first(); $this->bench->cancel($run->id, 99);
        $this->expectExceptionMessage('benchmark_duplicate_experiment'); $this->bench->start(2, 99, true);
    }

    public function test_previously_examined_holdout_accounts_cannot_reenter_a_new_exam(): void
    {
        $this->start(); $user = 1;
        while (hexdec(substr(hash_hmac('sha256', '2:' . $user, 'synthetic-benchmark-key'), 0, 6)) % 5 === 0) $user++;
        $this->bench->enroll($this->action($user), $this->automation->current); $run = DB::table(Benchmark::RUNS)->first();
        $this->bench->cancel($run->id, 99); $this->ai->provider['model'] = 'next-model'; $this->bench->start(2, 99, true);
        $this->bench->enroll($this->action($user), $this->automation->current);
        $this->assertSame(1, DB::table(Benchmark::CASES)->count());
    }

    public function test_fact_corrections_append_a_label_history_and_emergency_stop_revokes_pilots(): void
    {
        $run = $this->start(); $this->seedPassing($run); $this->bench->approve($run->id, [10], true, true, 99);
        $id = DB::table(Benchmark::CASES)->where('run_id', $run->id)->value('id');
        $this->bench->label($id, 'uncertain', true, 99); $this->bench->label($id, 'benign', true, 100);
        $this->assertSame(2, DB::table('v2_subscription_ai_benchmark_label')->where('case_id', $id)->count());
        $this->bench->approve($run->id, [10], true, true, 99); $this->bench->revokeAllPilots();
        $this->assertFalse($this->bench->permitsMode($this->pilotSettings()));
    }

    public function test_key_rotation_cannot_turn_a_previous_holdout_account_into_training_data(): void
    {
        $this->start(); $user = 1;
        while (hexdec(substr(hash_hmac('sha256', '2:' . $user, 'synthetic-benchmark-key'), 0, 6)) % 5 === 0) $user++;
        $this->bench->enroll($this->action($user), $this->automation->current); $run = DB::table(Benchmark::RUNS)->first();
        $this->bench->cancel($run->id, 99); config(['app.key' => 'rotated-synthetic-key']);
        $this->bench->start(2, 99, true); $this->bench->enroll($this->action($user), $this->automation->current);
        $this->assertSame(1, DB::table(Benchmark::CASES)->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SubscriptionControlCaseReview;
use App\Models\User;
use App\Services\ServerMachine\MachineRiskComparison;
use App\Services\SubscriptionControlCaseReviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionControlCaseReviewServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();

        Schema::create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->string('token')->nullable();
            $table->string('uuid')->nullable();
            $table->unsignedInteger('group_id')->nullable();
            $table->unsignedInteger('site_id')->nullable();
        });
        Schema::create('v2_subscription_control_event', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('code', 64);
            $table->string('action', 32);
            $table->string('client_ip', 64)->nullable();
            $table->string('ua_category', 64)->nullable();
            $table->string('region', 64)->nullable();
            $table->integer('risk_score')->nullable();
            $table->integer('created_at');
        });
        Schema::create('v2_subscription_control_case_review', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 32);
            $table->text('note')->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->unsignedTinyInteger('suspicion_score')->nullable();
            $table->char('evidence_fingerprint', 64)->nullable();
            $table->unsignedInteger('baseline_last_trigger_at')->nullable();
            $table->unsignedInteger('reviewed_at');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    public function test_credential_proof_is_bound_to_the_event_viewed_and_the_current_credential_epoch(): void
    {
        config(['app.key' => 'synthetic-review-test-key']);
        $migration = require base_path('database/migrations/2026_11_20_000002_add_subscription_control_event_credential_epoch.php');
        $migration->up();
        DB::table('v2_user')->insert(['id' => 10, 'token' => 'old-test-token', 'uuid' => 'old-test-uuid', 'site_id' => 1]);
        $user = User::query()->findOrFail(10);
        $service = new SubscriptionControlCaseReviewService();
        $item = fn (): array => $service->attachOverview(['items' => [['user_id' => 10]]])['items'][0];
        $epoch = app(MachineRiskComparison::class)->identity($user);
        $oldEvent = $this->event(10, time() - 60);
        DB::table('v2_subscription_control_event')->insert($oldEvent);
        $this->assertArrayNotHasKey('credential_review_evidence', $item());
        $this->assertNull($service::observedCredentialEpoch($user, 'subscription_leak_guard', ['signal_families' => ['client']]));
        $this->assertSame($epoch, $service::observedCredentialEpoch($user, 'subscription_leak_guard', ['signal_families' => ['client', 'network']]));
        $eventId = DB::table('v2_subscription_control_event')->insertGetId($oldEvent + ['credential_epoch' => $epoch]);
        $seen = $item()['credential_review_evidence'];
        $this->assertSame($eventId, $seen['id']);
        $this->assertStringNotContainsString($epoch, json_encode($seen, JSON_THROW_ON_ERROR));

        DB::table('v2_user')->where('id', 10)->update(['token' => 'new-test-token', 'uuid' => 'new-test-uuid']);
        $this->assertArrayNotHasKey('credential_review_evidence', $item());
        $this->assertRejectedProof($service, $seen['proof']);
        $this->assertSame(0, SubscriptionControlCaseReview::query()->count());

        $historical = $service->review(10, 'confirmed_leak', 'Confirm old incident only', 1, ['last_trigger_at' => time()]);
        $this->assertArrayNotHasKey('confirmed_credential_epoch', SubscriptionControlCaseReview::query()->findOrFail($historical['id'])->evidence_snapshot);
        $newEpoch = app(MachineRiskComparison::class)->identity($user->fresh());
        $newId = DB::table('v2_subscription_control_event')->insertGetId($this->event(10, time()) + ['credential_epoch' => $newEpoch]);
        $newProof = $item()['credential_review_evidence']['proof'];
        $saved = $service->review(10, 'confirmed_leak', 'Reviewed new evidence', 1, ['credential_review_proof' => $newProof]);
        $snapshot = SubscriptionControlCaseReview::query()->findOrFail($saved['id'])->evidence_snapshot;
        $this->assertSame($newEpoch, $snapshot['confirmed_credential_epoch']);
        $this->assertSame($newId, $snapshot['confirmed_credential_event']['id']);
        $this->assertRejectedProof($service, $newProof);
        $modified = $item()['credential_review_evidence']['proof'];
        DB::table('v2_subscription_control_event')->where('id', $newId)->update(['action' => 'observe']);
        $this->assertRejectedProof($service, $modified);
        $migration->down();
        $this->assertArrayNotHasKey('credential_review_evidence', $item());
        $this->assertSame(2, SubscriptionControlCaseReview::query()->count());
    }

    private function assertRejectedProof(SubscriptionControlCaseReviewService $service, string $proof): void
    {
        try {
            $service->review(10, 'confirmed_leak', 'Verify a stale view', 1, ['credential_review_proof' => $proof]);
            $this->fail('A stale credential proof was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('case_evidence_changed', $e->getMessage());
        }
    }

    public function test_manual_review_is_saved_and_new_events_reopen_the_case(): void
    {
        $now = time();
        DB::table('v2_user')->insert(['id' => 10, 'email' => 'risk@example.test']);
        DB::table('v2_subscription_control_event')->insert($this->event(10, $now - 120));
        $service = new SubscriptionControlCaseReviewService();

        $review = $service->review(10, 'false_positive', 'Shared office network', 5, [
            'suspicion_score' => 82,
            'verdict' => 'probable_subscription_leak',
            'confidence' => 'high',
            'case_evidence' => ['source_sharing', 'infrastructure_source'],
            'last_trigger_at' => $now - 120,
            'model_version' => '1.1.0',
        ]);

        $this->assertSame('false_positive', $review['status']);
        $this->assertFalse($review['needs_re_review']);
        $this->assertSame(82, $review['suspicion_score']);
        $this->assertNotEmpty($review['evidence_fingerprint']);

        DB::table('v2_subscription_control_event')->insert($this->event(10, $now + 5));
        $overview = $service->attachOverview([
            'items' => [['user_id' => 10]],
        ]);
        $attached = $overview['items'][0]['case_review'];

        $this->assertTrue($attached['needs_re_review']);
        $this->assertSame(1, $attached['new_event_count']);
        $this->assertSame($now + 5, $attached['last_new_event_at']);
        $this->assertSame(1, $overview['case_review_summary']['needs_re_review']);
        $this->assertSame(1.0, $overview['case_review_summary']['false_positive_rate']);
    }

    public function test_latest_decision_drives_calibration_while_history_is_preserved(): void
    {
        $now = time();
        DB::table('v2_user')->insert(['id' => 20, 'email' => 'review@example.test']);
        DB::table('v2_subscription_control_event')->insert($this->event(20, $now - 60));
        $service = new SubscriptionControlCaseReviewService();

        $service->review(20, 'false_positive', null, 1, ['suspicion_score' => 60]);
        $service->review(20, 'watching', 'Observe after reset', 2, ['suspicion_score' => 75]);
        $metrics = $service->calibrationMetrics();

        $this->assertSame(1, $metrics['reviewed_users']);
        $this->assertSame(1, $metrics['status_counts']['watching']);
        $this->assertSame(0, $metrics['status_counts']['false_positive']);
        $this->assertSame(2, $metrics['decision_history_count']);
        $this->assertSame(0.0, $metrics['false_positive_rate']);
    }

    public function test_evidence_outcomes_conservatively_calibrate_candidate_ranking(): void
    {
        $service = new SubscriptionControlCaseReviewService();
        $now = time();
        for ($offset = 0; $offset < 10; $offset++) {
            $userId = 100 + $offset;
            DB::table('v2_user')->insert(['id' => $userId, 'email' => "review{$userId}@example.test"]);
            DB::table('v2_subscription_control_event')->insert($this->event($userId, $now - 60));
            $evidence = $offset < 5 ? 'source_sharing' : 'infrastructure_source';
            $confirmed = $offset < 4 || $offset === 5;
            $service->review($userId, $confirmed ? 'confirmed_leak' : 'false_positive', null, 1, [
                'suspicion_score' => 70,
                'case_evidence' => [$evidence],
                'model_version' => '1.2.0',
            ]);
        }

        $overview = $service->calibrateOverview([
            'items' => [
                ['user_id' => 105, 'suspicion_score' => 70, 'case_evidence' => ['infrastructure_source'], 'last_trigger_at' => $now],
                ['user_id' => 100, 'suspicion_score' => 70, 'case_evidence' => ['source_sharing'], 'last_trigger_at' => $now],
            ],
        ], 20);

        $this->assertSame(100, $overview['items'][0]['user_id']);
        $this->assertSame(1, $overview['items'][0]['calibration_adjustment']);
        $this->assertSame(71, $overview['items'][0]['calibrated_ranking_score']);
        $this->assertSame(-1, $overview['items'][1]['calibration_adjustment']);
        $this->assertSame(69, $overview['items'][1]['calibrated_ranking_score']);
        $this->assertSame(10, $overview['case_review_calibration']['labeled_users']);
        $this->assertSame(2, $overview['case_review_calibration']['eligible_rule_count']);
        $this->assertTrue($overview['case_review_calibration']['affects_ranking_only']);
        $this->assertFalse($overview['case_review_calibration']['automatic_enforcement']);
    }

    public function test_evidence_outcomes_below_minimum_sample_do_not_change_ranking(): void
    {
        $service = new SubscriptionControlCaseReviewService();
        $now = time();
        for ($offset = 0; $offset < 4; $offset++) {
            $userId = 200 + $offset;
            DB::table('v2_user')->insert(['id' => $userId, 'email' => "limited{$userId}@example.test"]);
            DB::table('v2_subscription_control_event')->insert($this->event($userId, $now - 60));
            $service->review($userId, 'confirmed_leak', null, 1, [
                'suspicion_score' => 70,
                'case_evidence' => ['limited_evidence'],
                'model_version' => '1.2.0',
            ]);
        }

        $overview = $service->calibrateOverview([
            'items' => [[
                'user_id' => 200,
                'suspicion_score' => 70,
                'case_evidence' => ['limited_evidence'],
                'last_trigger_at' => $now,
            ]],
        ], 20);

        $this->assertSame(0, $overview['items'][0]['calibration_adjustment']);
        $this->assertSame(70, $overview['items'][0]['calibrated_ranking_score']);
        $this->assertFalse($overview['items'][0]['calibration_applied']);
        $this->assertSame(0, $overview['case_review_calibration']['eligible_rule_count']);
    }

    /** @return array<string, mixed> */
    private function event(int $userId, int $createdAt): array
    {
        return [
            'user_id' => $userId,
            'code' => 'subscription_leak_guard',
            'action' => 'block',
            'client_ip' => '203.0.113.10',
            'ua_category' => 'script',
            'region' => 'US',
            'risk_score' => 90,
            'created_at' => $createdAt,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

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
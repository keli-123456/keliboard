<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\GenerateSubscriptionControlAiReviewJob;
use App\Services\SubscriptionControlAiAdvisorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SubscriptionControlAiReviewLifecycleTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();

        Schema::create('v2_subscription_control_ai_review', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32)->default('pending');
            $table->unsignedTinyInteger('window_days')->default(7);
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->text('summary')->nullable();
            $table->json('current_config')->nullable();
            $table->json('metrics')->nullable();
            $table->json('findings')->nullable();
            $table->json('suggestions')->nullable();
            $table->json('replay')->nullable();
            $table->json('applied_changes')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedInteger('generated_at')->nullable();
            $table->unsignedInteger('applied_at')->nullable();
            $table->unsignedInteger('rolled_back_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    public function test_stale_pending_reviews_are_failed_without_touching_active_reviews(): void
    {
        $now = 2_000_000_000;
        DB::table('v2_subscription_control_ai_review')->insert([
            ['id' => 1, 'status' => 'pending', 'created_at' => $now - 500, 'updated_at' => $now - 300],
            ['id' => 2, 'status' => 'pending', 'created_at' => $now - 30, 'updated_at' => $now - 30],
        ]);

        $recovered = (new SubscriptionControlAiAdvisorService())->recoverStalePendingReviews($now);

        $this->assertSame(1, $recovered);
        $stale = DB::table('v2_subscription_control_ai_review')->find(1);
        $active = DB::table('v2_subscription_control_ai_review')->find(2);
        $this->assertSame('failed', $stale->status);
        $this->assertSame('review_stalled', $stale->error_code);
        $this->assertSame('pending', $active->status);
        $this->assertNull($active->error_code);
    }

    public function test_failure_callback_only_changes_pending_review(): void
    {
        $now = time();
        DB::table('v2_subscription_control_ai_review')->insert([
            ['id' => 1, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $service = new SubscriptionControlAiAdvisorService();

        $service->failPendingReview(1, 'review_timeout');
        $service->failPendingReview(2, 'job_failed');

        $failed = DB::table('v2_subscription_control_ai_review')->find(1);
        $completed = DB::table('v2_subscription_control_ai_review')->find(2);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('review_timeout', $failed->error_code);
        $this->assertSame('completed', $completed->status);
        $this->assertNull($completed->error_code);
    }

    public function test_review_job_uses_dedicated_queue_with_safe_timeout_ordering(): void
    {
        $job = new GenerateSubscriptionControlAiReviewJob(10);

        $queue = require dirname(__DIR__, 3).'/config/queue.php';
        $horizon = require dirname(__DIR__, 3).'/config/horizon.php';

        $this->assertSame('redis_ai', $job->connection);
        $this->assertSame('risk_ai', $job->queue);
        $this->assertSame(180, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertGreaterThan($job->timeout, $queue['connections']['redis_ai']['retry_after']);
        $this->assertGreaterThan($job->timeout, $horizon['environments']['production']['RiskAI']['timeout']);
    }
}
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_subscription_ai_benchmark', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('site_id');
            $table->char('context_key', 64);
            $table->json('teaching');
            $table->string('status', 24)->default('collecting');
            $table->unsignedBigInteger('created_by');
            $table->unsignedInteger('created_at');
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->unsignedInteger('cancelled_at')->nullable();
            $table->index(['site_id', 'id']);
        });
        Schema::create('v2_subscription_ai_benchmark_case', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('action_id')->unique();
            $table->char('subject_hash', 64);
            $table->string('cohort', 16);
            $table->json('evidence');
            $table->boolean('legacy_prediction')->nullable();
            $table->boolean('prediction')->nullable();
            $table->boolean('prediction_ok')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('evidence_age_seconds');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('completed_at')->nullable();
            $table->string('truth', 16)->nullable();
            $table->unsignedBigInteger('truth_by')->nullable();
            $table->unsignedInteger('truth_at')->nullable();
            $table->unique(['run_id', 'subject_hash'], 'sub_ai_bench_subject');
            $table->index(['run_id', 'cohort', 'id'], 'sub_ai_bench_cohort');
            $table->index(['subject_hash', 'cohort'], 'sub_ai_bench_prior_subject');
        });
        Schema::create('v2_subscription_ai_benchmark_label', function (Blueprint $table): void {
            $table->bigIncrements('id'); $table->unsignedBigInteger('case_id');
            $table->string('truth', 16); $table->unsignedBigInteger('admin_id'); $table->unsignedInteger('created_at');
            $table->index(['case_id', 'id'], 'sub_ai_bench_labels');
        });
        Schema::create('v2_subscription_ai_admission', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('run_id');
            $table->json('user_ids');
            $table->char('report_hash', 64);
            $table->unsignedBigInteger('approved_by');
            $table->unsignedInteger('approved_at');
            $table->boolean('operations_confirmed')->default(false);
            $table->unsignedInteger('expires_at');
            $table->unsignedInteger('revoked_at')->nullable();
            $table->index(['run_id', 'revoked_at', 'expires_at'], 'sub_ai_bench_admission');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_ai_admission');
        Schema::dropIfExists('v2_subscription_ai_benchmark_label');
        Schema::dropIfExists('v2_subscription_ai_benchmark_case');
        Schema::dropIfExists('v2_subscription_ai_benchmark');
    }
};

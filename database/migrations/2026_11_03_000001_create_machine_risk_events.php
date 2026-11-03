<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_risk_policy', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('revision');
            $table->json('config');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedInteger('updated_at');
        });
        DB::table('v2_machine_risk_policy')->insert(['id' => 1, 'revision' => 1,
            'config' => json_encode(['enabled' => false, 'auto_ai' => false, 'notify_admin' => false, 'hourly_budget' => 2]),
            'updated_at' => time()]);
        Schema::create('v2_machine_risk_event', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('episode_key', 64)->unique();
            $table->unsignedBigInteger('machine_id')->index();
            $table->char('authority_hash', 64);
            $table->char('probe_revision', 64);
            $table->string('status', 24)->default('open');
            $table->unsignedInteger('detected_at');
            $table->unsignedInteger('last_reachable_at')->nullable();
            $table->unsignedInteger('first_failure_at')->nullable();
            $table->unsignedInteger('recovered_at')->nullable();
            $table->json('evidence');
            $table->string('ai_status', 24)->default('not_requested');
            $table->char('ai_attempt', 32)->nullable();
            $table->unsignedInteger('ai_policy_revision')->nullable();
            $table->unsignedInteger('ai_requested_at')->nullable()->index();
            $table->unsignedInteger('ai_finished_at')->nullable();
            $table->string('ai_error_code', 40)->nullable();
            $table->string('ai_model', 120)->nullable();
            $table->json('analysis')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('notification_status', 24)->default('disabled');
            $table->string('review_verdict', 24)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedInteger('reviewed_at')->nullable();
            $table->unsignedInteger('created_at')->index();
            $table->unsignedInteger('updated_at');
            $table->index(['status', 'detected_at'], 'machine_risk_open');
        });
        Schema::create('v2_machine_risk_ai_attempt', function (Blueprint $table): void {
            $table->char('attempt', 32)->primary();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedInteger('policy_revision');
            $table->unsignedInteger('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_risk_ai_attempt');
        Schema::dropIfExists('v2_machine_risk_event');
        Schema::dropIfExists('v2_machine_risk_policy');
    }
};

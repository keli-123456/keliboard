<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_risk_investigation', function (Blueprint $table): void {
            $table->unsignedBigInteger('event_id')->primary();
            $table->unsignedInteger('policy_revision');
            $table->string('state', 24)->default('watching');
            $table->unsignedInteger('started_at');
            $table->unsignedInteger('deadline_at');
            $table->unsignedInteger('checked_at')->nullable();
            $table->unsignedInteger('next_check_at')->index();
            $table->index(['state', 'next_check_at'], 'machine_risk_followup_due');
            $table->unsignedInteger('last_probe_at')->nullable();
            $table->unsignedTinyInteger('recovery_samples')->default(0);
            $table->json('observation')->nullable();
            $table->string('ai_origin', 16)->nullable();
            $table->unsignedInteger('ai_queued_at')->nullable();
            $table->unsignedInteger('ai_next_at')->nullable()->index();
            $table->unsignedInteger('ai_deadline_at')->nullable();
            $table->json('notices')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_risk_investigation');
    }
};

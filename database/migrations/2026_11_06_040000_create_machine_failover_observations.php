<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_observation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id');
            $table->unsignedInteger('policy_revision');
            $table->string('sample_key', 64)->unique();
            $table->string('context_key', 64);
            $table->string('installation_id', 32)->nullable();
            $table->string('state', 20);
            $table->string('reason', 50);
            $table->unsignedInteger('checked_at')->nullable();
            $table->unsignedInteger('valid_until')->nullable();
            $table->unsignedInteger('observed_at');
            $table->unsignedInteger('baseline_at')->nullable();
            $table->unsignedInteger('first_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->boolean('threshold_reached')->default(false);
            $table->index(['policy_id', 'policy_revision', 'id'], 'machine_failover_observation_scope');
        });
        Schema::create('v2_machine_failover_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id');
            $table->unsignedInteger('policy_revision');
            $table->unsignedBigInteger('observation_id');
            $table->string('decision_key', 64)->unique();
            $table->string('status', 20)->default('blocked');
            $table->string('closed_reason', 50)->nullable();
            $table->text('blockers');
            $table->text('evidence');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('closed_at')->nullable();
            $table->index(['policy_id', 'policy_revision', 'id'], 'machine_failover_plan_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_failover_plan');
        Schema::dropIfExists('v2_machine_failover_observation');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_machine_rollout')) {
            Schema::create('v2_machine_rollout', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('admin_id')->index();
                $table->uuid('client_token');
                $table->string('status', 32)->index();
                $table->json('release');
                $table->unsignedInteger('observe_seconds')->default(600);
                $table->string('reason', 64)->nullable();
                $table->unsignedInteger('last_tick_at')->nullable();
                $table->timestamps();
                $table->unique(['admin_id', 'client_token']);
            });
        }
        if (!Schema::hasTable('v2_machine_rollout_target')) {
            Schema::create('v2_machine_rollout_target', function (Blueprint $table): void {
                $table->id();
                $table->uuid('rollout_id')->index();
                $table->unsignedBigInteger('machine_id')->index();
                $table->unsignedBigInteger('active_machine_id')->nullable()->unique();
                $table->string('name');
                $table->unsignedInteger('position');
                $table->string('status', 32)->default('pending');
                $table->uuid('upgrade_id')->nullable();
                $table->json('baseline')->nullable();
                $table->json('samples')->nullable();
                $table->json('watch')->nullable();
                $table->unsignedInteger('queued_at')->nullable();
                $table->unsignedInteger('observing_at')->nullable();
                $table->unsignedInteger('last_sample_at')->nullable();
                $table->unsignedInteger('matched_count')->default(0);
                $table->timestamps();
                $table->unique(['rollout_id', 'machine_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_rollout_target');
        Schema::dropIfExists('v2_machine_rollout');
    }
};

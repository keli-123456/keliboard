<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_owned_instance', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('policy_id')->index(); $table->unsignedInteger('policy_revision');
            $table->string('task_id', 36)->unique(); $table->string('installation_id', 32)->unique();
            $table->unsignedBigInteger('release_id'); $table->string('binary_sha256', 64); $table->string('version', 80);
            $table->string('ip', 45); $table->string('status', 30)->default('candidate');
            $table->unsignedBigInteger('resource_id')->nullable()->unique();
            $table->unsignedBigInteger('active_policy_id')->nullable()->unique();
            $table->string('challenge_id', 32)->nullable(); $table->unsignedInteger('challenge_deadline')->nullable();
            $table->text('verification')->nullable(); $table->unsignedInteger('verified_until')->nullable();
            $table->string('reason', 50)->nullable();
            $table->unsignedInteger('created_at'); $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_owned_dns', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('policy_id')->unique(); $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('credential_id'); $table->unsignedInteger('credential_revision');
            $table->string('zone', 32); $table->string('hostname', 253)->unique();
            $table->string('record_id', 32)->nullable()->unique(); $table->string('marker', 100);
            $table->unsignedBigInteger('active_instance_id')->nullable(); $table->string('ip', 45)->nullable();
            $table->string('status', 30)->default('reserved');
            $table->unsignedInteger('created_at'); $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_dns_transition', function (Blueprint $table) {
            $table->string('id', 36)->primary(); $table->string('request_key', 64)->unique();
            $table->string('recovery_of', 36)->nullable(); $table->string('write_kind', 10)->nullable();
            $table->unsignedBigInteger('dns_id')->index(); $table->unsignedInteger('dns_revision');
            $table->unsignedInteger('policy_revision'); $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('from_instance_id')->nullable(); $table->unsignedBigInteger('to_instance_id');
            $table->string('from_ip', 45)->nullable(); $table->string('to_ip', 45);
            $table->string('state', 30)->default('prepared'); $table->string('reason', 50)->nullable();
            $table->unsignedInteger('dispatched_at')->nullable(); $table->unsignedInteger('deadline_at');
            $table->unsignedInteger('created_at'); $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_acceptance_check', function (Blueprint $table) {
            $table->string('id', 32)->primary(); $table->unsignedBigInteger('instance_id')->index();
            $table->unsignedInteger('policy_revision'); $table->string('request_sha256', 64);
            $table->string('state', 20); $table->string('reason', 50)->nullable();
            $table->string('receipt_sha256', 64)->nullable();
            $table->unsignedInteger('issued_at'); $table->unsignedInteger('deadline_at'); $table->unsignedInteger('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_acceptance_check');
        Schema::dropIfExists('v2_machine_dns_transition');
        Schema::dropIfExists('v2_machine_owned_dns');
        Schema::dropIfExists('v2_machine_owned_instance');
    }
};

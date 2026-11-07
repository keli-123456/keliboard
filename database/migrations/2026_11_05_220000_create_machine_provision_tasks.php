<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_provision_task', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('request_key', 64)->unique();
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedInteger('account_revision');
            $table->unsignedBigInteger('machine_id')->index();
            $table->string('machine_authority', 64);
            $table->string('plan_hash', 64);
            $table->string('slot', 80);
            $table->string('installation_id', 32)->unique();
            $table->string('callback_hash', 64);
            $table->longText('payload');
            $table->string('marker', 64)->unique();
            $table->string('status', 40)->index();
            $table->string('reason', 50)->nullable();
            $table->string('external_id', 32)->nullable()->index();
            $table->string('provider_created_at', 40)->nullable();
            $table->text('public_ips')->nullable();
            $table->string('bootstrap_state', 20)->nullable();
            $table->unsignedInteger('bootstrap_at')->nullable();
            $table->unsignedInteger('dispatched_at')->nullable();
            $table->unsignedInteger('next_check_at')->default(0);
            $table->unsignedInteger('deadline_at');
            $table->unsignedInteger('finished_at')->nullable();
            $table->text('evidence')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['status', 'next_check_at'], 'machine_provision_due');
        });
        Schema::create('v2_machine_provision_step', function (Blueprint $table) {
            $table->id();
            $table->string('task_id', 36)->index();
            $table->string('state', 40);
            $table->string('reason', 50)->nullable();
            $table->unsignedInteger('created_at');
            $table->unique(['task_id', 'state', 'reason'], 'machine_provision_step_once');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_machine_provision_step');
        Schema::dropIfExists('v2_machine_provision_task');
    }
};

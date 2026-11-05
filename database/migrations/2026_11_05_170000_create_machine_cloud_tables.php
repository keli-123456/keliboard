<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_cloud_account', function (Blueprint $table) {
            $table->id(); $table->string('name', 80); $table->string('provider', 20)->default('linode');
            $table->text('credentials'); $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('last_sync_at')->nullable(); $table->unsignedInteger('last_page')->default(0);
            $table->unsignedInteger('pages')->default(0); $table->string('last_error', 40)->nullable();
            $table->unsignedInteger('created_at'); $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_cloud_resource', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('account_id')->index(); $table->string('external_id', 20)->unique();
            $table->string('label', 100); $table->string('region', 60); $table->string('size', 60);
            $table->string('provider_created_at', 30); $table->string('status', 40); $table->text('public_ips');
            $table->unsignedInteger('observed_at'); $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('machine_id')->nullable(); $table->string('installation_id', 32)->nullable();
            $table->string('machine_authority', 64)->nullable(); $table->unique(['machine_id', 'installation_id'], 'cloud_bound_installation');
            $table->boolean('auto_boot')->default(false); $table->boolean('armed')->default(false);
            $table->boolean('manual_hold')->default(true); $table->unsignedBigInteger('policy_admin_id')->nullable();
            $table->unsignedInteger('last_running_at')->nullable(); $table->unsignedInteger('offline_since')->nullable();
            $table->unsignedInteger('offline_samples')->default(0); $table->unsignedInteger('next_check_at')->default(0)->index();
            $table->unsignedInteger('last_auto_boot_at')->nullable(); $table->string('recovery_status', 40)->default('disabled');
            $table->unsignedBigInteger('active_task_id')->nullable()->index();
            $table->unsignedBigInteger('last_task_id')->nullable(); $table->boolean('dns_hold')->default(false);
            $table->unsignedInteger('created_at'); $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_cloud_task', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('resource_id')->index(); $table->unsignedBigInteger('admin_id');
            $table->string('request_key', 64)->unique(); $table->string('action', 20); $table->boolean('automatic')->default(false);
            $table->unsignedInteger('resource_revision'); $table->unsignedInteger('account_revision');
            $table->string('external_id', 20); $table->string('resource_label', 100);
            $table->string('status', 40)->default('queued'); $table->string('reason', 40)->nullable();
            $table->unsignedBigInteger('baseline_event_id')->default(0); $table->boolean('acknowledged')->default(false);
            $table->boolean('saw_transition')->default(false); $table->unsignedInteger('dispatched_at')->nullable();
            $table->unsignedInteger('provider_confirmed_at')->nullable(); $table->unsignedInteger('deadline_at');
            $table->unsignedInteger('next_check_at')->default(0)->index(); $table->unsignedInteger('finished_at')->nullable();
            $table->unsignedInteger('created_at'); $table->unsignedInteger('updated_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_machine_cloud_task'); Schema::dropIfExists('v2_machine_cloud_resource'); Schema::dropIfExists('v2_machine_cloud_account');
    }
};

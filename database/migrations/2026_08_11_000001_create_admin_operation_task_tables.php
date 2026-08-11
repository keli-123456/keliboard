<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_admin_operation_task', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_id')->index();
            $table->string('operation', 64)->index();
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->string('source_path', 255)->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('cancelled')->default(0);
            $table->json('payload')->nullable();
            $table->json('context')->nullable();
            $table->string('client_token', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('cancel_requested_at')->nullable();
            $table->unsignedInteger('started_at')->nullable();
            $table->unsignedInteger('finished_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');

            $table->unique(['admin_id', 'client_token'], 'uq_admin_operation_task_client');
            $table->index(['admin_id', 'created_at'], 'idx_admin_operation_task_owner_created');
        });

        Schema::create('v2_admin_operation_task_item', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('task_id')->index();
            $table->string('item_key', 191);
            $table->string('label', 255)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('started_at')->nullable();
            $table->unsignedInteger('finished_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');

            $table->unique(['task_id', 'item_key'], 'uq_admin_operation_task_item');
            $table->index(['task_id', 'status'], 'idx_admin_operation_task_item_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_admin_operation_task_item');
        Schema::dropIfExists('v2_admin_operation_task');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_marketing_template')) {
            Schema::create('v2_marketing_template', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->string('code', 64)->unique();
                $table->string('name', 128);
                $table->string('channel', 16)->default('email')->index();
                $table->string('message_type', 16)->default('marketing')->index();
                $table->string('subject')->nullable();
                $table->text('content');
                $table->boolean('enabled')->default(true)->index();
                $table->boolean('is_system')->default(true);
                $table->json('variables')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_marketing_rule')) {
            Schema::create('v2_marketing_rule', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->string('code', 64)->unique();
                $table->string('scene', 64)->unique();
                $table->string('name', 128);
                $table->string('message_type', 16)->default('marketing')->index();
                $table->string('description')->nullable();
                $table->boolean('enabled')->default(true)->index();
                $table->boolean('email_enabled')->default(true);
                $table->boolean('telegram_enabled')->default(false);
                $table->integer('email_template_id')->nullable()->index();
                $table->integer('telegram_template_id')->nullable()->index();
                $table->integer('priority')->default(100)->index();
                $table->integer('cooldown_hours')->default(24);
                $table->integer('daily_user_limit')->default(1);
                $table->json('trigger_config')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_message_dispatch_task')) {
            Schema::create('v2_message_dispatch_task', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('user_id')->nullable()->index();
                $table->integer('rule_id')->nullable()->index();
                $table->integer('template_id')->nullable()->index();
                $table->string('channel', 16)->index();
                $table->string('message_type', 16)->index();
                $table->integer('priority')->default(100)->index();
                $table->string('state', 24)->default('pending')->index();
                $table->string('dedupe_key', 191)->nullable()->index();
                $table->string('to_address', 191)->nullable()->index();
                $table->string('subject')->nullable();
                $table->json('payload')->nullable();
                $table->json('context')->nullable();
                $table->integer('scheduled_at')->nullable()->index();
                $table->integer('available_at')->nullable()->index();
                $table->integer('reserved_at')->nullable()->index();
                $table->integer('sent_at')->nullable()->index();
                $table->integer('attempt_count')->default(0);
                $table->integer('max_attempts')->default(3);
                $table->string('failure_classification', 32)->nullable()->index();
                $table->text('last_error')->nullable();
                $table->text('provider_response')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_message_dispatch_log')) {
            Schema::create('v2_message_dispatch_log', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('task_id')->nullable()->index();
                $table->integer('user_id')->nullable()->index();
                $table->integer('rule_id')->nullable()->index();
                $table->integer('template_id')->nullable()->index();
                $table->integer('mail_log_id')->nullable()->index();
                $table->string('channel', 16)->index();
                $table->string('message_type', 16)->index();
                $table->string('status', 24)->index();
                $table->integer('attempt')->default(1);
                $table->string('to_address', 191)->nullable()->index();
                $table->string('subject')->nullable();
                $table->string('failure_classification', 32)->nullable()->index();
                $table->string('provider_health_status', 24)->nullable()->index();
                $table->text('error_message')->nullable();
                $table->text('provider_response')->nullable();
                $table->json('context')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_message_suppression')) {
            Schema::create('v2_message_suppression', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('user_id')->nullable()->index();
                $table->string('channel', 16)->index();
                $table->string('address', 191)->nullable()->index();
                $table->string('scope', 32)->default('all')->index();
                $table->string('reason_type', 32)->index();
                $table->text('reason_detail')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->integer('expires_at')->nullable()->index();
                $table->integer('created_by_admin_id')->nullable()->index();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_spam_registration_candidate')) {
            Schema::create('v2_spam_registration_candidate', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('user_id')->unique();
                $table->string('status', 24)->default('candidate')->index();
                $table->boolean('freeze_applied')->default(false);
                $table->boolean('is_login_frozen')->default(false);
                $table->integer('candidate_since')->nullable()->index();
                $table->integer('last_evaluated_at')->nullable()->index();
                $table->integer('last_email_log_id')->nullable()->index();
                $table->string('last_failure_classification', 32)->nullable()->index();
                $table->string('provider_health_status', 24)->nullable()->index();
                $table->text('reason_summary')->nullable();
                $table->json('reason_codes')->nullable();
                $table->json('evaluation_snapshot')->nullable();
                $table->text('manual_note')->nullable();
                $table->integer('preserved_by_admin_id')->nullable()->index();
                $table->integer('preserved_at')->nullable()->index();
                $table->integer('restored_by_admin_id')->nullable()->index();
                $table->integer('restored_at')->nullable()->index();
                $table->integer('soft_deleted_by_admin_id')->nullable()->index();
                $table->integer('soft_deleted_at')->nullable()->index();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_spam_registration_candidate');
        Schema::dropIfExists('v2_message_suppression');
        Schema::dropIfExists('v2_message_dispatch_log');
        Schema::dropIfExists('v2_message_dispatch_task');
        Schema::dropIfExists('v2_marketing_rule');
        Schema::dropIfExists('v2_marketing_template');
    }
};

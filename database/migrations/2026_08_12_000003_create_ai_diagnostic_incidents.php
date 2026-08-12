<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_ai_diagnostic_incident')) {
            Schema::create('v2_ai_diagnostic_incident', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->char('fingerprint', 64)->unique();
                $table->string('scope_key', 64)->index();
                $table->string('scope_type', 24)->index();
                $table->unsignedInteger('site_id')->nullable()->index();
                $table->string('finding_key', 96)->index();
                $table->string('module', 32)->index();
                $table->string('severity', 20)->index();
                $table->unsignedBigInteger('subject_id')->default(0)->index();
                $table->string('status', 24)->default('open')->index();
                $table->unsignedBigInteger('first_report_id');
                $table->unsignedBigInteger('last_report_id');
                $table->unsignedInteger('occurrence_count')->default(1);
                $table->unsignedInteger('recurrence_count')->default(0);
                $table->unsignedInteger('assignee_id')->nullable()->index();
                $table->integer('due_at')->nullable()->index();
                $table->integer('first_seen_at')->index();
                $table->integer('last_seen_at')->index();
                $table->integer('resolved_at')->nullable()->index();
                $table->integer('last_notified_at')->nullable()->index();
                $table->json('last_notification_channels')->nullable();
                $table->text('last_notification_error')->nullable();
                $table->text('last_note')->nullable();
                $table->json('latest_evidence')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();

                $table->index(['scope_key', 'status', 'last_seen_at'], 'idx_ai_incident_scope_status_seen');
                $table->index(['assignee_id', 'status', 'due_at'], 'idx_ai_incident_assignee_due');
            });
        }

        if (!Schema::hasTable('v2_ai_diagnostic_incident_log')) {
            Schema::create('v2_ai_diagnostic_incident_log', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('incident_id')->index();
                $table->string('action', 48)->index();
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24)->nullable();
                $table->unsignedInteger('admin_id')->nullable()->index();
                $table->text('note')->nullable();
                $table->json('metadata')->nullable();
                $table->integer('created_at')->nullable()->index();
                $table->integer('updated_at')->nullable();

                $table->index(['incident_id', 'created_at'], 'idx_ai_incident_log_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ai_diagnostic_incident_log');
        Schema::dropIfExists('v2_ai_diagnostic_incident');
    }
};

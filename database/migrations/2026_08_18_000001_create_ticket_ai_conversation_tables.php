<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_ticket_ai_conversation')) {
            Schema::create('v2_ticket_ai_conversation', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('ticket_id')->unique();
                $table->string('scope_type', 20)->default('platform')->index();
                $table->integer('site_id')->nullable()->index();
                $table->integer('agent_user_id')->nullable()->index();
                $table->integer('agent_domain_id')->nullable()->index();
                $table->string('status', 30)->default('active')->index();
                $table->integer('auto_reply_count')->default(0);
                $table->integer('follow_up_count')->default(0);
                $table->integer('low_confidence_count')->default(0);
                $table->integer('failure_count')->default(0);
                $table->integer('last_source_message_id')->nullable()->index();
                $table->integer('last_reply_message_id')->nullable()->index();
                $table->string('last_draft_hash', 64)->nullable();
                $table->string('last_reason', 100)->nullable()->index();
                $table->string('handoff_reason', 100)->nullable()->index();
                $table->integer('handoff_at')->nullable()->index();
                $table->integer('last_activity_at')->nullable()->index();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_ticket_ai_conversation_event')) {
            Schema::create('v2_ticket_ai_conversation_event', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('conversation_id')->index();
                $table->integer('ticket_id')->index();
                $table->integer('source_message_id')->nullable()->index();
                $table->integer('suggestion_id')->nullable()->index();
                $table->integer('reply_message_id')->nullable()->index();
                $table->string('event', 40)->index();
                $table->string('reason', 100)->nullable()->index();
                $table->string('scope_type', 20)->default('platform')->index();
                $table->integer('site_id')->nullable()->index();
                $table->integer('agent_user_id')->nullable()->index();
                $table->integer('agent_domain_id')->nullable()->index();
                $table->integer('created_at')->index();
                $table->integer('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ticket_ai_conversation_event');
        Schema::dropIfExists('v2_ticket_ai_conversation');
    }
};

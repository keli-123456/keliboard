<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_ticket_ai_suggestion')) {
            return;
        }

        Schema::create('v2_ticket_ai_suggestion', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('ticket_id')->index();
            $table->integer('admin_id')->nullable()->index();
            $table->string('model', 100)->nullable();
            $table->string('category', 100)->nullable()->index();
            $table->string('sentiment', 50)->nullable();
            $table->string('risk', 50)->nullable()->index();
            $table->boolean('needs_human')->default(false)->index();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->text('summary')->nullable();
            $table->mediumText('draft')->nullable();
            $table->string('draft_hash', 64)->nullable();
            $table->text('instruction')->nullable();
            $table->json('knowledge_refs')->nullable();
            $table->json('matched_knowledge')->nullable();
            $table->string('status', 30)->default('generated')->index();
            $table->integer('inserted_at')->nullable()->index();
            $table->integer('discarded_at')->nullable()->index();
            $table->integer('sent_at')->nullable()->index();
            $table->integer('reply_message_id')->nullable()->index();
            $table->string('final_message_hash', 64)->nullable();
            $table->boolean('edited')->default(false)->index();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ticket_ai_suggestion');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_ticket_ai_request_log')) {
            throw new RuntimeException('v2_ticket_ai_request_log already exists; refusing to replace existing data.');
        }

        Schema::create('v2_ticket_ai_request_log', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->unsignedInteger('ticket_id')->nullable()->index();
            $table->unsignedInteger('suggestion_id')->nullable()->index();
            $table->unsignedInteger('admin_id')->nullable()->index();
            $table->string('status', 20)->index();
            $table->string('error_code', 40)->nullable()->index();
            $table->string('scope_type', 20)->default('platform')->index();
            $table->unsignedInteger('site_id')->nullable()->index();
            $table->unsignedInteger('agent_user_id')->nullable()->index();
            $table->unsignedInteger('agent_domain_id')->nullable()->index();
            $table->string('provider_host', 255)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('prompt_chars')->default(0);
            $table->unsignedInteger('response_chars')->default(0);
            $table->integer('created_at')->index();
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ticket_ai_request_log');
    }
};

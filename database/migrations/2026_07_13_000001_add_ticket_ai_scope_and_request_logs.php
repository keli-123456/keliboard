<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_ticket_ai_suggestion')) {
            $hasScopeType = Schema::hasColumn('v2_ticket_ai_suggestion', 'scope_type');
            $hasSiteId = Schema::hasColumn('v2_ticket_ai_suggestion', 'site_id');
            $hasAgentUserId = Schema::hasColumn('v2_ticket_ai_suggestion', 'agent_user_id');
            $hasAgentDomainId = Schema::hasColumn('v2_ticket_ai_suggestion', 'agent_domain_id');
            $hasStructuredOutput = Schema::hasColumn('v2_ticket_ai_suggestion', 'structured_output');

            Schema::table('v2_ticket_ai_suggestion', function (Blueprint $table) use (
                $hasScopeType,
                $hasSiteId,
                $hasAgentUserId,
                $hasAgentDomainId,
                $hasStructuredOutput
            ): void {
                if (!$hasScopeType) {
                    $table->string('scope_type', 20)->default('platform')->index();
                }
                if (!$hasSiteId) {
                    $table->unsignedInteger('site_id')->nullable()->index();
                }
                if (!$hasAgentUserId) {
                    $table->unsignedInteger('agent_user_id')->nullable()->index();
                }
                if (!$hasAgentDomainId) {
                    $table->unsignedInteger('agent_domain_id')->nullable()->index();
                }
                if (!$hasStructuredOutput) {
                    $table->boolean('structured_output')->default(true)->index();
                }
            });
        }

        if (Schema::hasTable('v2_ticket_ai_request_log')) {
            return;
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

        if (!Schema::hasTable('v2_ticket_ai_suggestion')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('v2_ticket_ai_suggestion', 'scope_type') ? 'scope_type' : null,
            Schema::hasColumn('v2_ticket_ai_suggestion', 'site_id') ? 'site_id' : null,
            Schema::hasColumn('v2_ticket_ai_suggestion', 'agent_user_id') ? 'agent_user_id' : null,
            Schema::hasColumn('v2_ticket_ai_suggestion', 'agent_domain_id') ? 'agent_domain_id' : null,
            Schema::hasColumn('v2_ticket_ai_suggestion', 'structured_output') ? 'structured_output' : null,
        ]));

        if ($columns !== []) {
            Schema::table('v2_ticket_ai_suggestion', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};

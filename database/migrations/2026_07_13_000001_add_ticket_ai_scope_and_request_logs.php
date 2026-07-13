<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->assertSchemaIsAvailable();
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

        if (Schema::hasTable('v2_ticket_ai_suggestion')) {
            Schema::table('v2_ticket_ai_suggestion', function (Blueprint $table): void {
                $table->string('scope_type', 20)->default('platform')->index();
                $table->unsignedInteger('site_id')->nullable()->index();
                $table->unsignedInteger('agent_user_id')->nullable()->index();
                $table->unsignedInteger('agent_domain_id')->nullable()->index();
                $table->boolean('structured_output')->default(true)->index();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_ticket_ai_suggestion')) {
            Schema::dropIfExists('v2_ticket_ai_request_log');
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('v2_ticket_ai_suggestion', 'scope_type') ? 'scope_type' : null,
            Schema::hasColumn('v2_ticket_ai_suggestion', 'site_id') ? 'site_id' : null,
            Schema::hasColumn('v2_ticket_ai_suggestion', 'agent_user_id') ? 'agent_user_id' : null,
            Schema::hasColumn('v2_ticket_ai_suggestion', 'agent_domain_id') ? 'agent_domain_id' : null,
            Schema::hasColumn('v2_ticket_ai_suggestion', 'structured_output') ? 'structured_output' : null,
        ]));

        $indexedColumns = array_values(array_intersect($columns, [
            'scope_type',
            'site_id',
            'agent_user_id',
            'agent_domain_id',
            'structured_output',
        ]));
        foreach ($indexedColumns as $column) {
            Schema::table('v2_ticket_ai_suggestion', fn (Blueprint $table) => $table->dropIndex([$column]));
        }

        if ($columns !== []) {
            Schema::table('v2_ticket_ai_suggestion', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }

        Schema::dropIfExists('v2_ticket_ai_request_log');
    }

    private function assertSchemaIsAvailable(): void
    {
        if (Schema::hasTable('v2_ticket_ai_request_log')) {
            throw new RuntimeException('v2_ticket_ai_request_log already exists; refusing a partial migration.');
        }
        if (!Schema::hasTable('v2_ticket_ai_suggestion')) {
            return;
        }

        foreach ([
            'scope_type',
            'site_id',
            'agent_user_id',
            'agent_domain_id',
            'structured_output',
        ] as $column) {
            if (Schema::hasColumn('v2_ticket_ai_suggestion', $column)) {
                throw new RuntimeException("v2_ticket_ai_suggestion.{$column} already exists; refusing a partial migration.");
            }
        }
    }
};

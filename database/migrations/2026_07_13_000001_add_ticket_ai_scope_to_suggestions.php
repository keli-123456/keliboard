<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const COLUMNS = [
        'scope_type', 'site_id', 'agent_user_id', 'agent_domain_id', 'structured_output',
    ];

    public function up(): void
    {
        $this->assertSchemaIsAvailable();
        if (!Schema::hasTable('v2_ticket_ai_suggestion')) {
            return;
        }

        Schema::table('v2_ticket_ai_suggestion', function (Blueprint $table): void {
            $table->string('scope_type', 20)->default('platform')->index();
            $table->unsignedInteger('site_id')->nullable()->index();
            $table->unsignedInteger('agent_user_id')->nullable()->index();
            $table->unsignedInteger('agent_domain_id')->nullable()->index();
            $table->boolean('structured_output')->default(true)->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_ticket_ai_suggestion')) {
            return;
        }

        $columns = array_values(array_filter(array_map(
            fn (string $column): ?string => Schema::hasColumn('v2_ticket_ai_suggestion', $column) ? $column : null,
            self::COLUMNS
        )));
        foreach ($columns as $column) {
            Schema::table('v2_ticket_ai_suggestion', fn (Blueprint $table) => $table->dropIndex([$column]));
        }
        if ($columns !== []) {
            Schema::table('v2_ticket_ai_suggestion', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function assertSchemaIsAvailable(): void
    {
        if (!Schema::hasTable('v2_ticket_ai_suggestion')) {
            return;
        }
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('v2_ticket_ai_suggestion', $column)) {
                throw new RuntimeException("v2_ticket_ai_suggestion.{$column} already exists; refusing a partial migration.");
            }
        }
    }
};

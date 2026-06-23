<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['v2_marketing_template', 'v2_message_dispatch_task', 'v2_message_dispatch_log'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $schema) use ($table): void {
                if (!Schema::hasColumn($table, 'scope_type')) {
                    $schema->string('scope_type', 32)->default('global')->index()->after($this->afterColumn($table));
                }
                if (!Schema::hasColumn($table, 'site_id')) {
                    $schema->unsignedInteger('site_id')->nullable()->index()->after('scope_type');
                }
                if (!Schema::hasColumn($table, 'agent_user_id')) {
                    $schema->unsignedInteger('agent_user_id')->nullable()->index()->after('site_id');
                }
                if (!Schema::hasColumn($table, 'agent_domain_id')) {
                    $schema->unsignedInteger('agent_domain_id')->nullable()->index()->after('agent_user_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['v2_marketing_template', 'v2_message_dispatch_task', 'v2_message_dispatch_log'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $schema) use ($table): void {
                foreach (['agent_domain_id', 'agent_user_id', 'site_id', 'scope_type'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $schema->dropColumn($column);
                    }
                }
            });
        }
    }

    private function afterColumn(string $table): string
    {
        return match ($table) {
            'v2_marketing_template' => 'variables',
            'v2_message_dispatch_task' => 'context',
            'v2_message_dispatch_log' => 'context',
            default => 'id',
        };
    }
};

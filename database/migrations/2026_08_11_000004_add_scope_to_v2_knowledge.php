<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const COLUMNS = ['scope_type', 'site_id', 'agent_user_id', 'agent_domain_id'];

    public function up(): void
    {
        if (!Schema::hasTable('v2_knowledge')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('v2_knowledge', $column)) {
                throw new RuntimeException("v2_knowledge.{$column} already exists; refusing a partial migration.");
            }
        }

        Schema::table('v2_knowledge', function (Blueprint $table): void {
            $table->string('scope_type', 20)->default('global')->index();
            $table->unsignedInteger('site_id')->nullable()->index();
            $table->unsignedInteger('agent_user_id')->nullable()->index();
            $table->unsignedInteger('agent_domain_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_knowledge')) {
            return;
        }

        $columns = array_values(array_filter(
            self::COLUMNS,
            fn (string $column): bool => Schema::hasColumn('v2_knowledge', $column)
        ));
        foreach ($columns as $column) {
            Schema::table('v2_knowledge', fn (Blueprint $table) => $table->dropIndex([$column]));
        }
        if ($columns !== []) {
            Schema::table('v2_knowledge', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};

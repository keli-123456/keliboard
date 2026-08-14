<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const COLUMNS = [
        'source_type',
        'source_key',
        'source_version',
        'source_hash',
        'source_synced_at',
    ];

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
            $table->string('source_type', 20)->default('custom')->index();
            $table->string('source_key', 191)->nullable()->unique();
            $table->string('source_version', 32)->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->integer('source_synced_at')->nullable();
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

        if (in_array('source_key', $columns, true)) {
            Schema::table('v2_knowledge', fn (Blueprint $table) => $table->dropUnique(['source_key']));
        }
        if (in_array('source_type', $columns, true)) {
            Schema::table('v2_knowledge', fn (Blueprint $table) => $table->dropIndex(['source_type']));
        }
        if ($columns !== []) {
            Schema::table('v2_knowledge', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};

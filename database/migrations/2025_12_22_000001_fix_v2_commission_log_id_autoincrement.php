<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('v2_commission_log')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $dbName = DB::getDatabaseName();

        $column = DB::table('information_schema.COLUMNS')
            ->select(['EXTRA', 'IS_NULLABLE'])
            ->where('TABLE_SCHEMA', $dbName)
            ->where('TABLE_NAME', 'v2_commission_log')
            ->where('COLUMN_NAME', 'id')
            ->first();

        if (!$column) {
            return;
        }

        $extra = strtolower((string) ($column->EXTRA ?? ''));
        $isNullable = strtoupper((string) ($column->IS_NULLABLE ?? '')) === 'YES';
        $needsAutoIncrement = !str_contains($extra, 'auto_increment');

        if (!$needsAutoIncrement && !$isNullable) {
            return;
        }

        $hasIndexOnId = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $dbName)
            ->where('TABLE_NAME', 'v2_commission_log')
            ->where('COLUMN_NAME', 'id')
            ->exists();

        if (!$hasIndexOnId) {
            DB::statement('ALTER TABLE `v2_commission_log` ADD INDEX `idx_v2_commission_log_id` (`id`)');
        }

        DB::statement('ALTER TABLE `v2_commission_log` MODIFY `id` INT NOT NULL AUTO_INCREMENT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // no-op
    }
};


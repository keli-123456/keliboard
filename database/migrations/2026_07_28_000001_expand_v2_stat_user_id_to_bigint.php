<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_stat_user') || !Schema::hasColumn('v2_stat_user', 'id')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            $column = DB::table('information_schema.COLUMNS')
                ->select(['COLUMN_TYPE'])
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'v2_stat_user')
                ->where('COLUMN_NAME', 'id')
                ->first();

            if (str_starts_with(strtolower((string) ($column->COLUMN_TYPE ?? '')), 'bigint')) {
                return;
            }

            DB::statement(
                'ALTER TABLE `v2_stat_user` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
            );
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "v2_stat_user" ALTER COLUMN "id" TYPE BIGINT');
        }
    }

    public function down(): void
    {
        // Shrinking an active statistics primary key can lose data once it exceeds INT.
    }
};
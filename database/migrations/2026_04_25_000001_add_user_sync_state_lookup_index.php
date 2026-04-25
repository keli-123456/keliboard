<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'user_sync_states';
    private const INDEX = 'idx_user_sync_states_group_available_user';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || $this->indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        try {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['group_id', 'available', 'user_id'], self::INDEX);
            });
        } catch (Throwable) {
            // Ignore duplicate/unsupported index operations for safe repeated deploys.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !$this->indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        try {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        } catch (Throwable) {
            // Ignore missing index errors for safe rollback.
        }
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        try {
            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['name'] ?? null) === $indexName) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};

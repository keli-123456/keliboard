<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_sync_events')) {
            return;
        }

        $this->addIndexSafely('idx_user_sync_events_group_id_id', ['group_id', 'id']);
        $this->addIndexSafely('idx_user_sync_events_old_group_id_id', ['old_group_id', 'id']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_sync_events')) {
            return;
        }

        $this->dropIndexSafely('idx_user_sync_events_group_id_id');
        $this->dropIndexSafely('idx_user_sync_events_old_group_id_id');
    }

    private function addIndexSafely(string $indexName, array $columns): void
    {
        try {
            Schema::table('user_sync_events', function (Blueprint $table) use ($indexName, $columns): void {
                $table->index($columns, $indexName);
            });
        } catch (\Throwable) {
            // Ignore duplicate/unsupported index operations for safe repeated deploys.
        }
    }

    private function dropIndexSafely(string $indexName): void
    {
        try {
            Schema::table('user_sync_events', function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        } catch (\Throwable) {
            // Ignore missing index errors for safe rollback.
        }
    }
};


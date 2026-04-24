<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->replaceUniqueIndex(
            'v2_stat_server',
            'server_id_server_type_record_at',
            'stat_server_record_type_unique',
            ['server_id', 'server_type', 'record_at', 'record_type']
        );

        $this->replaceUniqueIndex(
            'v2_stat_user',
            'server_rate_user_id_record_at',
            'stat_user_record_type_unique',
            ['user_id', 'server_rate', 'record_at', 'record_type']
        );
    }

    public function down(): void
    {
        $this->replaceUniqueIndex(
            'v2_stat_server',
            'stat_server_record_type_unique',
            'server_id_server_type_record_at',
            ['server_id', 'server_type', 'record_at']
        );

        $this->replaceUniqueIndex(
            'v2_stat_user',
            'stat_user_record_type_unique',
            'server_rate_user_id_record_at',
            ['server_rate', 'user_id', 'record_at']
        );
    }

    private function replaceUniqueIndex(
        string $tableName,
        string $oldIndexName,
        string $newIndexName,
        array $newColumns
    ): void {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        $oldIndexExists = $this->indexExists($tableName, $oldIndexName);
        if ($oldIndexExists !== false) {
            Schema::table($tableName, function (Blueprint $table) use ($oldIndexName) {
                $table->dropUnique($oldIndexName);
            });
        }

        $newIndexExists = $this->indexExists($tableName, $newIndexName);
        if ($newIndexExists !== true) {
            Schema::table($tableName, function (Blueprint $table) use ($newColumns, $newIndexName) {
                $table->unique($newColumns, $newIndexName);
            });
        }
    }

    private function indexExists(string $tableName, string $indexName): ?bool
    {
        try {
            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['name'] ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return null;
        }
    }
};

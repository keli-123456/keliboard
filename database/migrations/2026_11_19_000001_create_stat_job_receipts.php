<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $keys = [
            'v2_stat_user' => ['user_id', 'server_rate', 'record_at', 'record_type'],
            'v2_stat_user_node_day' => ['user_id', 'server_id', 'server_rate', 'record_at', 'record_type'],
            'v2_stat_server' => ['server_id', 'server_type', 'record_at', 'record_type'],
        ];
        $db = DB::connection();
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        foreach ($keys as $table => $columns) {
            if (!Schema::hasIndex($table, $columns, 'unique')) {
                throw new RuntimeException('Statistics receipts require the current statistics unique indexes.');
            }
            if ($mysql) {
                // MySQL upsert can match any unique index, including an obsolete key without record_type.
                foreach (Schema::getIndexes($table) as $index) {
                    if ($index['unique'] && !$index['primary'] && $index['columns'] !== $columns) {
                        throw new RuntimeException('Statistics receipts require removal of obsolete statistics unique indexes.');
                    }
                }
            }
        }

        if ($mysql) {
            $tables = array_map(fn (string $table) => $db->getTablePrefix() . $table, array_keys($keys));
            $engines = $db->selectFromWriteConnection(
                'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?)',
                $tables
            );
            if (count($engines) !== count($tables) || array_filter($engines, fn ($row) => strtolower((string) $row->engine) !== 'innodb')) {
                throw new RuntimeException('Statistics receipts require InnoDB statistics tables.');
            }
        }

        Schema::create('v2_stat_job_receipt', function (Blueprint $table) use ($mysql): void {
            if ($mysql) {
                $table->engine = 'InnoDB';
            }
            $table->uuid('delivery_id')->primary();
            $table->char('content_hash', 64);
            $table->unsignedInteger('applied_at');
        });
    }

    public function down(): void
    {
        if (DB::table('v2_stat_job_receipt')->exists()) {
            throw new RuntimeException('Statistics receipts must be retained to prevent double accounting.');
        }
        Schema::dropIfExists('v2_stat_job_receipt');
    }
};

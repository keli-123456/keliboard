<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'v2_stat_write_lane';

    public function up(): void
    {
        $db = DB::connection();
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) use ($mysql): void {
                if ($mysql) {
                    $table->engine = 'InnoDB';
                }
                $table->string('stat_table', 64)->primary();
            });
        }

        if (!Schema::hasIndex(self::TABLE, ['stat_table'], 'primary')) {
            throw new RuntimeException('Statistics write lanes require a primary key.');
        }

        if ($mysql) {
            $metadata = $db->selectOne(
                'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$db->getTablePrefix() . self::TABLE],
                false
            );
            if (strtolower((string) ($metadata->engine ?? '')) !== 'innodb') {
                throw new RuntimeException('Statistics write lanes require InnoDB.');
            }
        }

        $lanes = ['v2_stat_user', 'v2_stat_user_node_day'];
        $db->table(self::TABLE)->insertOrIgnore(array_map(fn (string $table) => ['stat_table' => $table], $lanes));
        if ($db->table(self::TABLE)->useWritePdo()->whereIn('stat_table', $lanes)->count() !== count($lanes)) {
            throw new RuntimeException('Statistics write lanes could not be initialized.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
